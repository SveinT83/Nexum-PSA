<?php

namespace App\Modules\Documentation\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Core\User;
use App\Modules\Contact\Models\Contact;
use App\Modules\CustomerPortal\Models\CustomerPortalAccount;
use App\Modules\CustomerPortal\Models\CustomerPortalMembership;
use App\Modules\Documentation\Models\Documentation;
use App\Modules\Documentation\Models\DocumentationApiCommand;
use App\Modules\Documentation\Models\DocumentationTemplate;
use App\Modules\Documentation\Models\ShippingCarrier;
use App\Modules\Documentation\Models\Vendor;
use App\Modules\Notification\Actions\SendCustomerPortalNotification;
use App\Modules\Taxonomy\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Exercise real bearer tokens, persisted effects and denied projections, not controller mocks. */
class DocumentationApiParityTest extends TestCase
{
    use RefreshDatabase;

    private User $tech;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('Tech', 'web');
        $this->tech = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->tech->assignRole('Tech');
        $this->tech->givePermissionTo([
            'documentation.view', 'documentation.create', 'documentation.update', 'documentation.delete',
            'documentation.manage_templates', 'documentation.carrier_manage', 'taxonomy.manage_categories',
            'storage.view', 'storage.purchase_view',
        ]);
        $this->token = $this->tech->createToken('Documentation parity', ['*'])->plainTextToken;
        $this->bearer($this->token);
    }

    private function bearer(string $token): void
    {
        $this->app['auth']->forgetGuards();
        $this->withToken($token);
    }

    private function key(): array
    {
        return ['Idempotency-Key' => (string) Str::uuid()];
    }

    private function document(array $overrides = []): Documentation
    {
        $category = Category::create(['name' => 'Runbooks', 'slug' => 'runbooks-'.Str::uuid(), 'type' => 'documentation', 'is_active' => true]);
        $template = DocumentationTemplate::create(['category_id' => $category->id, 'name' => 'Runbook',
            'fields' => [['Name' => 'content', 'labelName' => 'Content', 'type' => 'textarea']], 'is_active' => true]);

        return Documentation::create(array_replace([
            'category_id' => $category->id, 'template_id' => $template->id, 'title' => 'API Runbook',
            'scope_type' => 'internal', 'template_snapshot_json' => $template->fields,
            'data_json' => ['content' => 'Visible instructions', 'unrendered_private' => 'MUST NOT LEAK'],
        ], $overrides));
    }

    private function carrierPayload(array $overrides = []): array
    {
        return array_replace([
            'name' => 'API Carrier', 'code' => 'api-carrier', 'lifecycle_state' => 'active',
            'sort_order' => 100, 'service_tags' => ['parcel'],
            'website_url' => 'https://carrier.example.test', 'source_url' => 'https://carrier.example.test/source',
            'tracking_method' => 'template', 'tracking_url_template' => 'https://track.example.test/{tracking_number}',
            'tracking_page_url' => 'https://track.example.test', 'allowed_tracking_hosts' => ['track.example.test'],
            'link_visibility' => 'normal', 'verification_state' => 'verified', 'verified_at' => now()->toDateString(),
        ], $overrides);
    }

    private function portal(): array
    {
        $client = Client::factory()->create(['active' => true]);
        $site = ClientSite::factory()->create(['client_id' => $client->id]);
        $contact = Contact::create(['type' => 'person', 'status' => 'active', 'display_name' => 'Portal API Contact']);
        $user = User::factory()->create(['contact_id' => $contact->id, 'status' => User::STATUS_ACTIVE]);
        $account = CustomerPortalAccount::create(['user_id' => $user->id, 'contact_id' => $contact->id, 'status' => 'active']);
        $membership = CustomerPortalMembership::create(['customer_portal_account_id' => $account->id,
            'client_id' => $client->id, 'site_id' => $site->id, 'role' => 'viewer', 'status' => 'active']);
        $token = $user->createToken('Portal documents', ['documentation.portal.read'])->plainTextToken;

        return [$client, $site, $user, $account, $membership, $token];
    }

    #[Test]
    public function context_and_lookups_are_bounded_pure_and_honest_about_revision_work(): void
    {
        $client = Client::factory()->create(['active' => true]);
        $site = ClientSite::factory()->create(['client_id' => $client->id]);
        ClientSite::factory()->create();
        $before = DB::table('documentation_api_commands')->count();
        $this->getJson('/api/v1/documentation/context')->assertOk()
            ->assertJsonPath('data.capabilities.immutable_revisions', false)
            ->assertJsonPath('data.carrier_defaults.tracking_method', 'generic_page');
        $this->getJson('/api/v1/documentation/lookups/clients?q='.urlencode($client->name))->assertOk()->assertJsonFragment(['id' => $client->id, 'name' => $client->name]);
        $this->getJson('/api/v1/documentation/lookups/sites?client_id='.$client->id)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $site->id);
        $this->getJson('/api/v1/documentation/lookups/sites')->assertUnprocessable();
        $this->getJson('/api/v1/documentation/lookups/secret')->assertNotFound();
        foreach (['per_page=101', 'page=-1', 'q[]=bad', 'scope_type=unknown', 'context_type=bad', 'unexpected=x'] as $query) {
            $this->getJson('/api/v1/knowledge/documentations?'.$query)->assertUnprocessable();
        }
        $this->assertSame($before, DB::table('documentation_api_commands')->count());
    }

    #[Test]
    public function token_scopes_never_grant_missing_web_actor_rights(): void
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->assignRole('Tech');
        $this->bearer($user->createToken('No rights', ['*'])->plainTextToken);
        $this->getJson('/api/v1/knowledge/documentations')->assertForbidden();
        $this->postJson('/api/v1/documentation/vendors', ['name' => 'Denied'], $this->key())->assertForbidden();
        $this->getJson('/api/v1/documentation/shipping-carriers')->assertForbidden();
        $this->assertDatabaseCount('vendors', 0);
    }

    #[Test]
    public function read_scopes_cannot_write_and_generic_editors_cannot_administer_carriers_or_templates(): void
    {
        $this->bearer($this->tech->createToken('Read only', ['knowledge.read', 'documentation.vendors.read'])->plainTextToken);
        $this->postJson('/api/v1/documentation/vendors', ['name' => 'Denied'], $this->key())->assertForbidden();
        $this->postJson('/api/v1/knowledge/documentation-categories', ['name' => 'Denied'])->assertForbidden();
        $this->tech->revokePermissionTo(['documentation.carrier_manage', 'documentation.manage_templates', 'taxonomy.manage_categories']);
        $this->bearer($this->token);
        $this->postJson('/api/v1/documentation/shipping-carriers', $this->carrierPayload(), $this->key())->assertForbidden();
        $this->postJson('/api/v1/knowledge/documentation-templates', ['name' => 'Denied'])->assertForbidden();
        $this->postJson('/api/v1/knowledge/documentation-categories', ['name' => 'Denied'])->assertForbidden();
    }

    #[Test]
    public function inactive_and_revoked_actors_are_denied_even_on_replay(): void
    {
        $payload = ['name' => 'Canonical Supplier', 'is_supplier' => true, 'is_active' => true];
        $key = $this->key();
        $this->postJson('/api/v1/documentation/suppliers', $payload, $key)->assertCreated();
        $this->tech->revokePermissionTo('documentation.create');
        $this->postJson('/api/v1/documentation/suppliers', $payload, $key)->assertForbidden();
        $this->tech->update(['status' => User::STATUS_DISABLED]);
        $this->getJson('/api/v1/knowledge/documentations')->assertForbidden();
        $this->assertDatabaseCount('vendors', 1);
    }

    #[Test]
    public function vendor_supplier_and_manufacturer_lifecycle_is_the_storage_master(): void
    {
        $supplier = $this->postJson('/api/v1/documentation/suppliers', ['name' => 'Wholesale', 'vendor_code' => 'WHOLE',
            'email' => 'orders@example.test', 'is_active' => true, 'default_lead_time_days' => 3], $this->key())
            ->assertCreated()->assertJsonPath('data.is_supplier', true)->assertJsonPath('data.is_vendor', false)
            ->assertJsonPath('data.is_manufacturer', false)->assertHeader('ETag')->json('data.id');
        $maker = $this->postJson('/api/v1/documentation/vendors', ['name' => 'Maker', 'is_active' => true], $this->key())
            ->assertCreated()->assertJsonPath('data.is_manufacturer', true)->json('data.id');
        $this->getJson('/api/v1/documentation/suppliers')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $supplier);
        $this->getJson('/api/v1/documentation/vendors?role=manufacturers')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $maker);
        $this->getJson('/api/v1/storage/suppliers?q=WHOLE')->assertOk()->assertJsonPath('data.0.id', $supplier);
        $this->patchJson('/api/v1/documentation/vendors/'.$supplier, ['is_active' => false, 'terms' => 'Net 14'], $this->key())
            ->assertOk()->assertJsonPath('data.name', 'Wholesale')->assertJsonPath('data.is_active', false);
        $this->getJson('/api/v1/documentation/vendors/'.$supplier)->assertOk()->assertJsonPath('data.terms', 'Net 14');
        $this->getJson('/api/v1/documentation/suppliers?is_active=0')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/storage/suppliers?q=WHOLE')->assertOk()->assertJsonCount(0, 'data');
    }

    #[Test]
    public function register_payloads_reject_system_provenance_unknown_fields_and_duplicates(): void
    {
        $this->postJson('/api/v1/documentation/vendors', ['name' => 'A'], [])->assertStatus(428);
        foreach (['source_provenance', 'created_from_purchase_import_id', 'supplier_bootstrap_status', 'supplier_import_identity_hash'] as $field) {
            $this->postJson('/api/v1/documentation/vendors', ['name' => 'A', $field => 'bad'], $this->key())
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->postJson('/api/v1/documentation/vendors', ['name' => 'A', 'vendor_code' => 'UNIQUE'], $this->key())->assertCreated();
        $this->postJson('/api/v1/documentation/vendors', ['name' => 'B', 'vendor_code' => 'UNIQUE'], $this->key())->assertUnprocessable();
        $vendor = Vendor::firstOrFail();
        $vendor->forceFill(['source_provenance' => ['raw_email' => 'NEVER RETURN THIS'],
            'supplier_import_identity_hash' => str_repeat('x', 64)])->save();
        $json = $this->getJson('/api/v1/documentation/vendors/'.$vendor->id)->assertOk()->getContent();
        $this->assertStringNotContainsString('source_provenance', $json);
        $this->assertStringNotContainsString('supplier_import_identity_hash', $json);
        $this->assertDatabaseCount('vendors', 1);
        $this->postJson('/api/v1/documentation/vendors', ['name' => 'Null flag', 'is_active' => null], $this->key())->assertUnprocessable();
        $this->postJson('/api/v1/documentation/vendors', ['name' => 'Long URL', 'url' => str_repeat('x', 256)], $this->key())->assertUnprocessable();
        $this->patchJson('/api/v1/documentation/vendors/'.$vendor->id, ['default_lead_time_days' => null], $this->key())
            ->assertOk()->assertJsonPath('data.default_lead_time_days', 0);
    }

    #[Test]
    public function durable_replay_is_encrypted_conflict_safe_and_bound_to_actor_and_token(): void
    {
        $key = $this->key();
        $payload = ['name' => 'Private Partner', 'note' => 'PRIVATE RESULT TEXT'];
        $response = $this->postJson('/api/v1/documentation/vendors', $payload, $key)->assertCreated();
        $id = $response->json('operation_id');
        $this->postJson('/api/v1/documentation/vendors', $payload, $key)->assertCreated()
            ->assertJsonPath('operation_id', $id)->assertJsonPath('replayed', true);
        $this->postJson('/api/v1/documentation/vendors', ['name' => 'Different'], $key)->assertConflict();
        $raw = DB::table('documentation_api_commands')->where('id', $id)->value('result');
        $this->assertStringNotContainsString('PRIVATE RESULT TEXT', $raw);
        $this->assertSame('PRIVATE RESULT TEXT', DocumentationApiCommand::findOrFail($id)->result['body']['data']['note']);
        $this->getJson('/api/v1/documentation/operations/'.$id)->assertOk()->assertJsonPath('data.state', 'completed');
        $this->bearer($this->tech->createToken('Another token', ['*'])->plainTextToken);
        $this->getJson('/api/v1/documentation/operations/'.$id)->assertNotFound();
        $this->assertDatabaseCount('vendors', 1);
        $this->bearer($this->token);
        $this->tech->revokePermissionTo('documentation.create');
        $this->getJson('/api/v1/documentation/operations/'.$id)->assertForbidden();
    }

    #[Test]
    public function replay_rechecks_original_scope_and_tokens_can_be_revoked(): void
    {
        $key = $this->key();
        $created = $this->postJson('/api/v1/documentation/vendors', ['name' => 'Scoped'], $key)->assertCreated();
        $token = PersonalAccessToken::findToken($this->token);
        $token->forceFill(['abilities' => ['documentation.operations.read']])->save();
        $this->bearer($this->token);
        $this->getJson('/api/v1/documentation/operations/'.$created->json('operation_id'))->assertForbidden();
        $this->postJson('/api/v1/documentation/vendors', ['name' => 'Scoped'], $key)->assertForbidden();
        $token->delete();
        $this->bearer($this->token);
        $this->getJson('/api/v1/documentation/context')->assertUnauthorized();
    }

    #[Test]
    public function optimistic_conflicts_detect_browser_changes_in_the_same_second(): void
    {
        $vendor = Vendor::create(['name' => 'Before', 'is_vendor' => true]);
        $etag = $this->getJson('/api/v1/documentation/vendors/'.$vendor->id)->assertOk()->headers->get('ETag');
        app(\App\Modules\Documentation\Actions\SaveVendor::class)->handle(['name' => 'Browser edit'], $vendor);
        $key = $this->key();
        $this->patchJson('/api/v1/documentation/vendors/'.$vendor->id, ['name' => 'Stale'], $key + ['If-Match' => $etag])->assertStatus(412);
        $this->assertSame('Browser edit', $vendor->fresh()->name);
        $etag = $this->getJson('/api/v1/documentation/vendors/'.$vendor->id)->assertOk()->headers->get('ETag');
        $this->patchJson('/api/v1/documentation/vendors/'.$vendor->id, ['name' => 'Current'], $key + ['If-Match' => $etag])->assertOk();
        $this->assertSame('Current', $vendor->fresh()->name);
    }

    #[Test]
    public function invalid_commands_roll_back_without_claiming_the_key(): void
    {
        $key = $this->key();
        $this->postJson('/api/v1/documentation/vendors', ['name' => 'Bad', 'email' => 'not-email'], $key)->assertUnprocessable();
        $this->assertDatabaseCount('documentation_api_commands', 0);
        $this->postJson('/api/v1/documentation/vendors', ['name' => 'Corrected', 'email' => 'ok@example.test'], $key)->assertCreated();
        $this->assertDatabaseCount('documentation_api_commands', 1);
    }

    #[Test]
    public function carriers_share_browser_validation_and_tracking_preview_has_no_writes_or_network(): void
    {
        $id = $this->postJson('/api/v1/documentation/shipping-carriers', $this->carrierPayload(), $this->key())
            ->assertCreated()->assertJsonPath('data.created_by', $this->tech->id)->json('data.id');
        $before = DocumentationApiCommand::count();
        $this->postJson('/api/v1/documentation/shipping-carriers/'.$id.'/tracking-preview', ['tracking_number' => 'A/B ?'])
            ->assertOk()->assertJsonPath('data.url', 'https://track.example.test/A%2FB%20%3F')
            ->assertJsonPath('data.provider_executed', false);
        $this->assertSame($before, DocumentationApiCommand::count());
        $this->patchJson('/api/v1/documentation/shipping-carriers/'.$id, ['name' => 'Updated Carrier', 'lifecycle_state' => 'legacy'], $this->key())
            ->assertOk()->assertJsonPath('data.code', 'api-carrier')->assertJsonPath('data.lifecycle_state', 'legacy');
        $this->getJson('/api/v1/documentation/shipping-carriers?lifecycle_state=legacy')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->getJson('/api/v1/documentation/shipping-carriers/'.$id)->assertOk()->assertJsonPath('data.name', 'Updated Carrier');
        foreach ([
            ['website_url' => 'http://carrier.example.test'],
            ['name' => ['bad']], ['service_tags' => [['bad']]], ['allowed_tracking_hosts' => 'bad'],
            ['tracking_url_template' => 'https://evil.example.test/{tracking_number}'],
            ['tracking_url_template' => 'https://track.example.test/{tracking_number}/{another}'],
            ['tracking_page_url' => 'https://user:password@track.example.test'],
            ['allowed_tracking_hosts' => ['localhost']],
            ['verification_state' => 'verified', 'verified_at' => null],
        ] as $bad) {
            $this->postJson('/api/v1/documentation/shipping-carriers', $this->carrierPayload(['code' => 'bad-'.Str::random(5)] + $bad), $this->key())->assertUnprocessable();
        }
        $this->assertDatabaseCount('shipping_carriers', 1);
    }

    #[Test]
    public function multiple_templates_require_selection_and_preserve_captured_schema(): void
    {
        $doc = $this->document();
        $template = $doc->template;
        DocumentationTemplate::create(['category_id' => $doc->category_id, 'name' => 'Alternative', 'fields' => [], 'is_active' => true]);
        $payload = ['title' => 'Explicit Template', 'category_id' => $doc->category_id, 'data' => ['content' => 'Snapshot']];
        $this->postJson('/api/v1/knowledge/documentations', $payload)->assertUnprocessable()->assertJsonValidationErrors('template_id');
        $id = $this->postJson('/api/v1/knowledge/documentations', $payload + ['template_id' => $template->id])->assertCreated()->json('data.id');
        $template->update(['fields' => [['Name' => 'new_field', 'labelName' => 'New', 'type' => 'text']]]);
        $this->patchJson('/api/v1/knowledge/documentations/'.$id, ['content' => 'Updated'])
            ->assertOk()->assertJsonPath('data.template_snapshot.0.Name', 'content');
        $this->getJson('/api/v1/knowledge/documentations/'.$id)->assertOk()->assertJsonPath('data.content', 'Updated');
        $this->patchJson('/api/v1/knowledge/documentations/'.$id, ['scope_type' => 'client'])->assertUnprocessable();
        $key = $this->key();
        $this->deleteJson('/api/v1/knowledge/documentations/'.$id, [], $key)->assertNoContent()->assertHeader('X-Operation-Id');
        $this->deleteJson('/api/v1/knowledge/documentations/'.$id, [], $key)->assertNoContent();
        $this->assertSoftDeleted('documentations', ['id' => $id]);
    }

    #[Test]
    public function category_and_template_admin_support_updates_and_reject_cross_domain_or_parent_cycles(): void
    {
        $category = $this->postJson('/api/v1/knowledge/documentation-categories', ['name' => 'Created Category'])->assertCreated()->json('data.id');
        $this->patchJson('/api/v1/knowledge/documentation-categories/'.$category, ['parent_id' => $category])->assertUnprocessable();
        $this->putJson('/api/v1/knowledge/documentation-categories/'.$category, ['description' => 'Updated'])->assertOk()->assertHeader('ETag');
        $this->getJson('/api/v1/knowledge/documentation-categories/'.$category)->assertOk()->assertJsonPath('data.description', 'Updated');
        $other = Category::create(['name' => 'Knowledge', 'slug' => 'knowledge-only', 'type' => 'knowledge', 'is_active' => true]);
        $this->getJson('/api/v1/knowledge/documentation-categories/'.$other->id)->assertNotFound();
        $this->patchJson('/api/v1/knowledge/documentation-categories/'.$category, ['parent_id' => $other->id])->assertUnprocessable();
        $this->postJson('/api/v1/knowledge/documentation-templates', ['category_id' => $category, 'name' => 'Invalid', 'fields' => [['Name' => 'x']]])->assertUnprocessable();
        $template = $this->postJson('/api/v1/knowledge/documentation-templates', ['category_id' => $category, 'name' => 'Valid',
            'fields' => [['Name' => 'x', 'labelName' => 'X', 'type' => 'text']]])->assertCreated()->json('data.id');
        $this->putJson('/api/v1/knowledge/documentation-templates/'.$template, ['is_active' => false])->assertOk()->assertHeader('ETag');
        $this->getJson('/api/v1/knowledge/documentation-templates?include_inactive=1')->assertOk()->assertJsonPath('data.0.id', $template);
        $this->getJson('/api/v1/knowledge/documentation-templates/'.$template)->assertOk()->assertJsonPath('data.is_active', false);
    }

    #[Test]
    public function portal_visibility_shares_audit_notification_and_exactly_once_replay(): void
    {
        $client = Client::factory()->create(['active' => true]);
        $doc = $this->document(['client_id' => $client->id, 'scope_type' => 'client']);
        $this->mock(SendCustomerPortalNotification::class, function ($mock) use ($client, $doc): void {
            $mock->shouldReceive('handle')->once()->withArgs(fn (...$args) => $args[0] === 'portal_document_published'
                && $args[1] === $client->id && $args[7] === $doc->id)->andReturn(0);
        });
        $path = '/api/v1/knowledge/documentations/'.$doc->id.'/portal-visibility';
        $key = $this->key();
        $this->postJson($path, ['portal_visible' => true], $key)->assertOk()->assertJsonPath('data.portal_visible', true);
        $this->postJson($path, ['portal_visible' => true], $key)->assertOk()->assertJsonPath('replayed', true);
        $this->assertDatabaseCount('customer_portal_audit_events', 1);
        $this->assertDatabaseHas('customer_portal_audit_events', ['event' => 'portal_documentation_visibility_enabled', 'client_id' => $client->id]);
        $this->assertSame($this->tech->id, $doc->fresh()->portal_visible_by);
        $this->postJson($path, ['portal_visible' => false], $this->key())->assertOk()->assertJsonPath('data.portal_visible_at', null);
        $internal = $this->document();
        $this->postJson('/api/v1/knowledge/documentations/'.$internal->id.'/portal-visibility', ['portal_visible' => true], $this->key())->assertUnprocessable();
    }

    #[Test]
    public function document_edits_notify_current_audience_but_scope_changes_hide_until_republished(): void
    {
        $client = Client::factory()->create(['active' => true]);
        $other = Client::factory()->create(['active' => true]);
        $doc = $this->document(['client_id' => $client->id, 'scope_type' => 'client', 'portal_visible_at' => now()]);
        $this->mock(SendCustomerPortalNotification::class, function ($mock) use ($client): void {
            $mock->shouldReceive('handle')->once()->withArgs(fn (...$args) => $args[0] === 'portal_document_updated'
                && $args[1] === $client->id)->andReturn(0);
        });
        $this->patchJson('/api/v1/knowledge/documentations/'.$doc->id, ['content' => 'Edit'])->assertOk()->assertJsonPath('data.portal_visible', true);
        $this->patchJson('/api/v1/knowledge/documentations/'.$doc->id, ['client_id' => $other->id])->assertOk()
            ->assertJsonPath('data.client_id', $other->id)->assertJsonPath('data.portal_visible', false);
        $this->assertNull($doc->fresh()->portal_visible_at);
    }

    #[Test]
    public function portal_bearer_reads_match_client_site_and_visible_template_fields(): void
    {
        [$client, $site, $user, $account, $membership, $token] = $this->portal();
        $visible = $this->document(['client_id' => $client->id, 'site_id' => $site->id, 'scope_type' => 'site', 'portal_visible_at' => now()]);
        $wide = $this->document(['client_id' => $client->id, 'scope_type' => 'client', 'portal_visible_at' => now()]);
        $hidden = $this->document(['client_id' => $client->id, 'scope_type' => 'client']);
        $otherSite = ClientSite::factory()->create(['client_id' => $client->id, 'name' => 'Other Site']);
        $wrongSite = $this->document(['client_id' => $client->id, 'site_id' => $otherSite->id, 'scope_type' => 'site', 'portal_visible_at' => now()]);
        $otherClient = Client::factory()->create(['active' => true]);
        $wrongClient = $this->document(['client_id' => $otherClient->id, 'scope_type' => 'client', 'portal_visible_at' => now()]);
        $internal = $this->document(['client_id' => $client->id, 'portal_visible_at' => now()]);
        $this->bearer($token);
        $response = $this->getJson('/api/v1/portal/documents?membership_id='.$membership->id)->assertOk()->assertJsonCount(2, 'data');
        $this->assertEqualsCanonicalizing([$visible->id, $wide->id], array_column($response->json('data'), 'id'));
        $json = $this->getJson('/api/v1/portal/documents/'.$visible->id)->assertOk()->assertJsonPath('data.fields.0.value', 'Visible instructions')->getContent();
        foreach (['MUST NOT LEAK', 'unrendered_private', 'portal_visible_by', 'template_snapshot', 'created_by'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
        foreach ([$hidden, $wrongSite, $wrongClient, $internal] as $denied) {
            $this->getJson('/api/v1/portal/documents/'.$denied->id)->assertNotFound();
        }
        $this->getJson('/api/v1/knowledge/documentations')->assertForbidden();
        $this->getJson('/api/v1/documentation/vendors')->assertForbidden();
    }

    #[Test]
    public function portal_membership_selection_revocation_and_contact_status_fail_closed(): void
    {
        [$client, $site, $user, $account, $membership, $token] = $this->portal();
        $other = $this->portal();
        $this->bearer($token);
        $this->getJson('/api/v1/portal/documents?membership_id='.$other[4]->id)->assertForbidden();
        $this->getJson('/api/v1/portal/documents?membership_id=9999999')->assertForbidden();
        $this->getJson('/api/v1/portal/documents?membership_id[]=bad')->assertUnprocessable();
        $this->getJson('/api/v1/portal/documents?client_id='.$other[0]->id)->assertUnprocessable();
        $membership->update(['status' => User::STATUS_DISABLED]);
        $this->getJson('/api/v1/portal/documents')->assertForbidden();
        $membership->update(['status' => 'active']);
        $user->contact->update(['status' => 'inactive']);
        $this->getJson('/api/v1/portal/documents')->assertForbidden();
    }

    #[Test]
    public function portal_session_and_bearer_require_active_own_access(): void
    {
        [$client, $site, $user, $account, $membership, $token] = $this->portal();
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->actingAs($user)->withSession(['customer_portal_membership_id' => $membership->id]);
        $this->getJson('/api/v1/portal/documents')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->bearer($this->tech->createToken('Staff without membership', ['documentation.portal.read'])->plainTextToken);
        $this->getJson('/api/v1/portal/documents')->assertForbidden();
    }

    #[Test]
    public function all_legacy_http_methods_return_typed_authoritative_readback(): void
    {
        $document = $this->document();
        $this->getJson('/api/v1/knowledge/documentations?scope_type=internal')->assertOk()
            ->assertJsonPath('data.0.id', $document->id);
        $this->getJson('/api/v1/knowledge/documentation-categories')->assertOk()
            ->assertJsonPath('data.0.id', $document->category_id);
        $this->patchJson('/api/v1/knowledge/documentation-categories/'.$document->category_id, ['description' => 'Category API edit'])
            ->assertOk()->assertJsonPath('data.description', 'Category API edit');
        $this->patchJson('/api/v1/knowledge/documentation-templates/'.$document->template_id, ['name' => 'Updated template'])
            ->assertOk()->assertJsonPath('data.name', 'Updated template');
        $this->putJson('/api/v1/knowledge/documentations/'.$document->id, ['title' => 'PUT document edit'])
            ->assertOk()->assertJsonPath('data.title', 'PUT document edit');
    }

    #[Test]
    public function local_notification_failure_rolls_back_visibility_audit_and_receipt(): void
    {
        $client = Client::factory()->create(['active' => true]);
        $document = $this->document(['client_id' => $client->id, 'scope_type' => 'client']);
        $this->mock(SendCustomerPortalNotification::class, function ($mock): void {
            $mock->shouldReceive('handle')->once()->andThrow(new \RuntimeException('Simulated local handoff failure'));
        });
        $key = $this->key();
        $this->postJson('/api/v1/knowledge/documentations/'.$document->id.'/portal-visibility',
            ['portal_visible' => true], $key)->assertStatus(500);
        $this->assertNull($document->fresh()->portal_visible_at);
        $this->assertDatabaseCount('documentation_api_commands', 0);
        $this->assertDatabaseCount('customer_portal_audit_events', 0);
        $this->mock(SendCustomerPortalNotification::class, function ($mock): void {
            $mock->shouldReceive('handle')->once()->andReturn(0);
        });
        $this->postJson('/api/v1/knowledge/documentations/'.$document->id.'/portal-visibility',
            ['portal_visible' => true], $key)->assertOk()->assertJsonPath('data.portal_visible', true);
    }

    #[Test]
    public function narrow_form_owners_can_read_exact_edit_context_without_broad_index_rights(): void
    {
        $document = $this->document();
        $carrier = ShippingCarrier::create($this->carrierPayload());
        $vendor = Vendor::create(['name' => 'Carrier partner', 'note' => 'PRIVATE NOTE']);
        $editor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $editor->assignRole('Tech');
        $editor->givePermissionTo('documentation.update');
        $this->bearer($editor->createToken('Narrow editor', ['knowledge.read', 'documentation.vendors.read'])->plainTextToken);
        $this->getJson('/api/v1/knowledge/documentations/'.$document->id)->assertOk();
        $this->getJson('/api/v1/documentation/vendors/'.$vendor->id)->assertOk();
        $this->getJson('/api/v1/documentation/context')->assertOk();
        $this->getJson('/api/v1/knowledge/documentations')->assertForbidden();
        $editor->syncPermissions(['documentation.carrier_manage']);
        $this->bearer($editor->createToken('Narrow carrier', ['knowledge.read', 'documentation.carriers.read'])->plainTextToken);
        $this->getJson('/api/v1/documentation/shipping-carriers/'.$carrier->id)->assertOk();
        $this->getJson('/api/v1/documentation/lookups/vendors')->assertOk()
            ->assertJsonFragment(['id' => $vendor->id, 'name' => 'Carrier partner'])->assertDontSee('PRIVATE NOTE');
        $this->getJson('/api/v1/documentation/lookups/clients')->assertForbidden();
        $editor->syncPermissions(['documentation.manage_templates', 'taxonomy.manage_categories']);
        $this->bearer($editor->createToken('Narrow templates', ['knowledge.read'])->plainTextToken);
        $this->getJson('/api/v1/knowledge/documentation-templates?include_inactive=1')->assertOk();
        $this->getJson('/api/v1/knowledge/documentation-templates/'.$document->template_id)->assertOk();
        $this->getJson('/api/v1/knowledge/documentation-categories?include_inactive=1')->assertOk();
        $this->getJson('/api/v1/knowledge/documentation-categories/'.$document->category_id)->assertOk();
        $this->getJson('/api/v1/documentation/vendors')->assertForbidden();
    }

    /** Validate actual successful HTTP payloads against the published contract. */
    public function json($method, $uri, array $data = [], array $headers = [], $options = 0)
    {
        $response = parent::json($method, $uri, $data, $headers, $options);
        if ($response->status() < 200 || $response->status() >= 300 || ! str_starts_with($uri, '/api/v1/')) {
            return $response;
        }
        $route = \Illuminate\Support\Facades\Route::getRoutes()->match(\Illuminate\Http\Request::create($uri, $method));
        if (! str_starts_with($route->getActionName(), 'App\\Modules\\Documentation\\')) {
            return $response;
        }
        $document = json_decode(file_get_contents(storage_path('api-docs/api-docs.json')), true, 512, JSON_THROW_ON_ERROR);
        $operation = $document['paths']['/'.$route->uri()][strtolower($method)] ?? null;
        $this->assertNotNull($operation, $method.' '.$route->uri().' has a published operation');
        $this->assertArrayHasKey($response->status(), $operation['responses']);
        if ($response->status() !== 204) {
            $schema = $operation['responses'][$response->status()]['content']['application/json']['schema'] ?? null;
            $this->assertIsArray($schema, 'A successful response must have a typed contract');
            $errors = [];
            $this->checkSchema(json_decode($response->getContent(), false, 512, JSON_THROW_ON_ERROR),
                $schema, $document['components']['schemas'], '$', $errors);
            $this->assertSame([], $errors, $method.' '.$route->uri().' response contract');
        }
        if ($response->headers->has('ETag') && is_string($response->json('data.revision'))) {
            $this->assertSame($response->headers->get('ETag'), $response->json('data.revision'), 'Body revision and ETag must agree');
        }
        self::$observed[strtolower($method).' /'.$route->uri()] = $response->status();

        return $response;
    }

    private static array $observed = [];

    public static function tearDownAfterClass(): void
    {
        if ($path = getenv('DOCUMENTATION_API_EVIDENCE_PATH')) {
            file_put_contents($path, json_encode(self::$observed, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }
        parent::tearDownAfterClass();
    }

    private function checkSchema(mixed $value, array $schema, array $schemas, string $path, array &$errors): void
    {
        if ($value === null && ($schema['nullable'] ?? false)) {
            return;
        }
        if (isset($schema['$ref'])) {
            $this->checkSchema($value, $schemas[basename($schema['$ref'])], $schemas, $path, $errors);

            return;
        }
        foreach (['oneOf', 'anyOf'] as $mode) {
            if (! isset($schema[$mode])) {
                continue;
            }
            $matches = 0;
            foreach ($schema[$mode] as $part) {
                $candidateErrors = [];
                $this->checkSchema($value, $part, $schemas, $path, $candidateErrors);
                if ($candidateErrors === []) {
                    $matches++;
                }
            }
            if (($mode === 'oneOf' && $matches !== 1) || ($mode === 'anyOf' && $matches < 1)) {
                $errors[] = $path.' does not match '.$mode.' (matches '.$matches.')';
            }
        }
        $type = $schema['type'] ?? null;
        $valid = match ($type) {
            'object' => $value instanceof \stdClass,
            'array' => is_array($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'string' => is_string($value),
            'boolean' => is_bool($value),
            default => true,
        };
        if (! $valid) {
            $errors[] = $path.' expected '.$type.', got '.get_debug_type($value);

            return;
        }
        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            $errors[] = $path.' outside declared enum';
        }
        if ($type === 'object') {
            $fields = get_object_vars($value);
            foreach ($schema['required'] ?? [] as $field) {
                if (! array_key_exists($field, $fields)) {
                    $errors[] = $path.'.'.$field.' required';
                }
            }
            foreach ($fields as $field => $child) {
                if (isset($schema['properties'][$field])) {
                    $this->checkSchema($child, $schema['properties'][$field], $schemas, $path.'.'.$field, $errors);
                } elseif (($schema['additionalProperties'] ?? true) === false) {
                    $errors[] = $path.'.'.$field.' undeclared';
                } elseif (is_array($schema['additionalProperties'] ?? null)) {
                    $this->checkSchema($child, $schema['additionalProperties'], $schemas, $path.'.'.$field, $errors);
                }
            }
        } elseif ($type === 'array') {
            foreach ($value as $index => $child) {
                $this->checkSchema($child, $schema['items'] ?? [], $schemas, $path.'.'.$index, $errors);
            }
            if (isset($schema['maxItems']) && count($value) > $schema['maxItems']) {
                $errors[] = $path.' has too many items';
            }
        }
        if ($type === 'string' && isset($schema['format']) && in_array($schema['format'], ['date-time', 'date'], true)
            && strtotime($value) === false) {
            $errors[] = $path.' invalid '.$schema['format'];
        }
    }
}
