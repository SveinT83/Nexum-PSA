<?php

namespace App\Modules\Contact\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Clients\ClientUser;
use App\Models\Core\User;
use App\Modules\Contact\Actions\StoreContact;
use App\Modules\Contact\Livewire\Tech\ContactForm;
use App\Modules\Contact\Models\Contact;
use App\Modules\Contact\Support\ContactApiState;
use App\Modules\Contact\Support\ContactSettings;
use App\Modules\CustomerPortal\Jobs\SendCustomerPortalInvitationEmail;
use App\Modules\CustomerPortal\Models\CustomerPortalMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Behavioral contracts and exact saved-state assertions for every Contact API workflow. */
class ContactApiParityTest extends TestCase
{
    use ContactApiContractAssertions;
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('Tech');
        $this->actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->actor->assignRole('Tech');
        foreach (['contact.view', 'contact.create', 'contact.update', 'contact.delete', 'contact.manage_settings', 'customer_portal.invite'] as $p) {
            Permission::findOrCreate($p, 'web');
            $this->actor->givePermissionTo($p);
        }
        $this->apiAs($this->actor, ['*']);
        Queue::fake();
    }

    private function person(array $extra = []): Contact
    {
        return app(StoreContact::class)->handle(array_merge(['display_name' => 'Identity', 'email' => 'person@example.test', 'phone' => '+47 12 34 56 78'], $extra));
    }

    private function scope(): array
    {
        $client = Client::factory()->create(['active' => true]);
        $site = ClientSite::factory()->create(['client_id' => $client->id, 'is_default' => true]);

        return [$client, $site];
    }

    private function headers(string $key = 'contact-parity-command'): array
    {
        return ['If-Match' => ContactApiState::revision(), 'Idempotency-Key' => $key];
    }

    /** Exercise actual bearer-token identity/scopes, including durable receipt binding. */
    private function apiAs(User $actor, array $abilities): void
    {
        $this->withToken($actor->createToken('contact-api-test', $abilities)->plainTextToken);
        app('auth')->forgetGuards();
    }

    #[Test]
    public function native_permission_revocation_denies_a_valid_scoped_token(): void
    {
        $this->actor->revokePermissionTo('contact.view');
        $this->getJson('/api/v1/contacts')->assertForbidden();
        $this->actor->forceFill(['status' => User::STATUS_DISABLED])->save();
        $this->getJson('/api/v1/contacts/context')->assertForbidden();
    }

    #[Test]
    public function read_scope_cannot_write_or_administer_or_read_receipts(): void
    {
        $this->apiAs($this->actor, ['contacts.read']);
        $this->postJson('/api/v1/contacts', ['display_name' => 'Denied'])->assertForbidden();
        $this->getJson('/api/v1/contacts/settings')->assertForbidden();
        $this->getJson('/api/v1/contacts/operations/00000000-0000-0000-0000-000000000000')->assertForbidden();
        $this->assertDatabaseCount('contacts', 0);
    }

    #[Test]
    public function list_filters_title_normalized_phone_pagination_and_statistics_are_canonical(): void
    {
        [$client, $site] = $this->scope();
        $person = $this->person(['client_id' => $client->id, 'site_id' => $site->id, 'job_title' => 'Chief Example']);
        $this->person(['display_name' => 'Other', 'email' => 'other@example.test', 'phone' => '99999999']);
        $this->getJson('/api/v1/contacts?client_id='.$client->id.'&q=Chief')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $person->id);
        $this->getJson('/api/v1/contacts?site_id='.$site->id.'&phone=004712345678')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/contacts?phone=5678')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/contacts?per_page=101')->assertUnprocessable();
        $this->getJson('/api/v1/contacts/statistics?client_id='.$client->id)->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.with_email', 1);
        $this->getJson('/api/v1/contacts')->assertOk()->assertJsonCount(2, 'data');
    }

    #[Test]
    public function mismatched_site_client_filters_are_rejected(): void
    {
        [$a] = $this->scope();
        [$b, $site] = $this->scope();
        $this->getJson('/api/v1/contacts?client_id='.$a->id.'&site_id='.$site->id)->assertUnprocessable();
        $this->postJson('/api/v1/contacts', ['display_name' => 'Bad', 'client_id' => $a->id, 'site_id' => $site->id])->assertUnprocessable();
        $this->assertDatabaseCount('contacts', 0);
    }

    #[Test]
    public function detail_and_patch_preserve_secondary_relations_bridge_and_addresses(): void
    {
        [$client, $site] = $this->scope();
        $person = $this->person(['client_id' => $client->id, 'site_id' => $site->id, 'city' => 'Steinkjer', 'address' => 'A 1', 'marketing_consent' => true]);
        [$other] = $this->scope();
        $person->relations()->create(['related_type' => $other->getMorphClass(), 'related_id' => $other->id, 'relation_type' => 'billing_contact']);
        $ids = $person->relations()->pluck('id')->all();
        $bridge = $person->clientUsers()->firstOrFail();
        $this->patchJson('/api/v1/contacts/'.$person->id, ['job_title' => 'New title', 'type' => 'department', 'city' => 'Trondheim'])
            ->assertOk()->assertJsonPath('data.type', 'department')->assertJsonPath('data.addresses.0.address', 'A 1')->assertJsonPath('data.addresses.0.city', 'Trondheim');
        $this->assertSame($ids, $person->relations()->pluck('id')->all());
        $this->assertTrue($person->fresh()->marketing_consent);
        $this->assertTrue((bool) $bridge->fresh()->active);
        $this->getJson('/api/v1/contacts/'.$person->id)->assertOk()->assertJsonPath('data.compatibility.client_users.0.id', $bridge->id)->assertJsonMissingPath('data.metadata');
    }

    #[Test]
    public function duplicate_lookup_and_create_reuse_do_not_overwrite_a_person(): void
    {
        $person = $this->person();
        $this->getJson('/api/v1/contacts/duplicates?email=PERSON@example.test')->assertOk()->assertJsonPath('data.0.id', $person->id);
        $this->postJson('/api/v1/contacts', ['display_name' => 'Replacement', 'email' => 'person@example.test', 'reuse_existing' => true, 'status' => 'inactive', 'do_not_email' => true])
            ->assertOk()->assertJsonPath('data.display_name', 'Identity')->assertJsonPath('meta.reused', true)->assertJsonPath('data.status', 'active')->assertJsonPath('data.do_not_email', false);
        $this->assertDatabaseCount('contacts', 1);
    }

    #[Test]
    public function conflicting_email_and_phone_do_not_choose_an_arbitrary_contact(): void
    {
        $one = $this->person();
        $two = $this->person(['display_name' => 'Second', 'email' => 'second@example.test', 'phone' => '99999999']);
        $this->postJson('/api/v1/contacts', ['display_name' => 'Unsafe', 'email' => 'person@example.test', 'phone' => '99999999'])->assertUnprocessable();
        $this->assertSame('Identity', $one->fresh()->display_name);
        $this->assertSame('Second', $two->fresh()->display_name);
    }

    #[Test]
    public function disabled_relation_cannot_be_arbitrarily_added_but_current_value_is_preserved(): void
    {
        app(ContactSettings::class)->update(['enabled_relation_types' => ['contact']]);
        $this->postJson('/api/v1/contacts', ['display_name' => 'No', 'relation_type' => 'ceo'])->assertUnprocessable();
        $person = $this->person();
        $this->patchJson('/api/v1/contacts/'.$person->id, ['status' => 'inactive'])->assertOk()->assertJsonPath('data.status', 'inactive');
        $this->patchJson('/api/v1/contacts/'.$person->id, ['portal_role' => CustomerPortalMembership::ROLE_VIEWER])->assertUnprocessable();
    }

    #[Test]
    public function form_only_actor_can_discover_defaults_and_scoped_options_without_directory_access(): void
    {
        [$client, $site] = $this->scope();
        $this->actor->revokePermissionTo(['contact.view', 'contact.update', 'contact.manage_settings', 'customer_portal.invite']);
        $this->getJson('/api/v1/contacts/context?client_id='.$client->id)->assertOk()->assertJsonPath('data.site.id', $site->id)->assertJsonPath('data.capabilities.read', false);
        $this->getJson('/api/v1/contacts/lookups/sites?client_id='.$client->id)->assertOk()->assertJsonPath('data.0.id', $site->id);
        $this->getJson('/api/v1/contacts')->assertForbidden();
        $this->getJson('/api/v1/contacts/settings')->assertForbidden();
        $this->getJson('/api/v1/contacts/lookups/sites')->assertUnprocessable();
    }

    #[Test]
    public function settings_write_validates_and_returns_saved_values_with_audit(): void
    {
        $data = ['default_contact_type' => 'team', 'default_status' => 'active', 'enabled_relation_types' => ['billing_contact'],
            'default_relation_type' => 'billing_contact', 'send_portal_invitation_by_default' => true];
        $this->putJson('/api/v1/contacts/settings', $data)->assertOk()->assertJsonPath('data.default_contact_type', 'team');
        $this->getJson('/api/v1/contacts/settings')->assertOk()->assertJsonPath('data.send_portal_invitation_by_default', true);
        $this->assertDatabaseHas('activity_log', ['event' => 'contact.settings.updated']);
        $data['default_relation_type'] = 'ceo';
        $this->putJson('/api/v1/contacts/settings', $data)->assertUnprocessable();
        $this->postJson('/api/v1/contacts', ['display_name' => 'No implicit invite'])->assertCreated()->assertJsonPath('portal_invitation', null);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function explicit_invitation_preview_rolls_back_and_execution_is_default_off(): void
    {
        [$client, $site] = $this->scope();
        $data = ['display_name' => 'Invited', 'email' => 'invite@example.test', 'client_id' => $client->id, 'site_id' => $site->id,
            'send_customer_portal_invitation' => true, 'portal_role' => CustomerPortalMembership::ROLE_SITE_ADMIN];
        $preview = $this->postJson('/api/v1/contacts/preview', $data)->assertOk()->assertJsonPath('execution_enabled', false)->assertJsonPath('dry_run', true);
        $this->assertDatabaseCount('contacts', 0);
        $this->assertDatabaseCount('customer_portal_invitations', 0);
        Queue::assertNothingPushed();
        $this->postJson('/api/v1/contacts', $data, ['If-Match' => $preview->headers->get('ETag'), 'Idempotency-Key' => 'invite-disabled-command'])->assertConflict();
        $this->assertDatabaseCount('contacts', 0);
    }

    #[Test]
    public function invitation_selected_role_is_transactional_and_replay_safe_with_exact_receipt(): void
    {
        config(['contact_api.portal_invitation_execution_enabled' => true]);
        [$client, $site] = $this->scope();
        $data = ['display_name' => 'Invited', 'email' => 'invite@example.test', 'client_id' => $client->id, 'site_id' => $site->id,
            'send_customer_portal_invitation' => true, 'portal_role' => CustomerPortalMembership::ROLE_CUSTOMER_ADMIN];
        $preview = $this->postJson('/api/v1/contacts/preview', $data)->assertOk();
        $headers = ['If-Match' => $preview->headers->get('ETag'), 'Idempotency-Key' => 'invite-unique-command'];
        $saved = $this->postJson('/api/v1/contacts', $data, $headers)->assertCreated()->assertJsonPath('portal_invitation.role', CustomerPortalMembership::ROLE_CUSTOMER_ADMIN);
        $id = $saved->json('data.id');
        $operation = $saved->json('operation_id');
        $this->postJson('/api/v1/contacts', $data, $headers)->assertCreated()->assertJsonPath('replayed', true)->assertJsonPath('data.id', $id);
        $this->assertDatabaseCount('contacts', 1);
        $this->assertDatabaseCount('customer_portal_invitations', 1);
        $this->getJson('/api/v1/contacts/operations/'.$operation)->assertOk()->assertJsonPath('data.result.data.id', $id);
        $this->assertStringNotContainsString('invite@example.test', DB::table('contact_api_commands')->value('result'));
        $this->actor->revokePermissionTo('customer_portal.invite');
        $this->getJson('/api/v1/contacts/operations/'.$operation)->assertForbidden();
        $this->postJson('/api/v1/contacts', $data, $headers)->assertForbidden();
        // QueueFake captures dispatch intents; replay must not add a second invitation job.
        Queue::assertPushed(SendCustomerPortalInvitationEmail::class, 1);
    }

    #[Test]
    public function invalid_invitation_rolls_back_contact_and_ordinary_edits_never_send(): void
    {
        config(['contact_api.portal_invitation_execution_enabled' => true]);
        [$client, $site] = $this->scope();
        $client->update(['active' => false]);
        $data = ['display_name' => 'Invalid', 'email' => 'invite@example.test', 'client_id' => $client->id,
            'send_customer_portal_invitation' => true];
        $this->postJson('/api/v1/contacts/preview', $data)->assertUnprocessable();
        $this->assertDatabaseCount('contacts', 0);
        $person = $this->person();
        $this->patchJson('/api/v1/contacts/'.$person->id, ['send_customer_portal_invitation' => true])->assertUnprocessable();
        $this->assertDatabaseCount('customer_portal_invitations', 0);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function repair_requires_preview_key_and_current_state_and_preserves_bridge_id(): void
    {
        [$client, $site] = $this->scope();
        [$target, $targetSite] = $this->scope();
        $person = $this->person(['client_id' => $client->id, 'site_id' => $site->id]);
        $bridge = $person->clientUsers()->firstOrFail();
        $url = '/api/v1/contacts/'.$person->id.'/move';
        $body = ['target_client_id' => $target->id, 'target_site_id' => $targetSite->id];
        $this->postJson($url, $body)->assertStatus(428);
        $preview = $this->postJson($url, $body + ['dry_run' => true])->assertOk()->assertJsonPath('dry_run', true);
        $headers = ['If-Match' => $preview->headers->get('ETag'), 'Idempotency-Key' => 'repair-unique-command'];
        $this->postJson($url, $body, $headers)->assertOk()->assertJsonPath('changed', true);
        $this->assertSame($targetSite->id, $bridge->fresh()->client_site_id);
        $this->postJson($url, $body, $headers)->assertOk()->assertJsonPath('replayed', true);
        $this->postJson($url, $body + ['reason' => 'changed payload'], $headers)->assertConflict();
        $this->postJson($url, $body, ['If-Match' => $headers['If-Match'], 'Idempotency-Key' => 'repair-stale-command'])->assertStatus(412);
        $this->assertDatabaseCount('client_users', 1);
    }

    #[Test]
    public function receipts_are_bound_to_the_original_actor_and_token(): void
    {
        $saved = $this->postJson('/api/v1/contacts', ['display_name' => 'Receipt'])->assertCreated();
        $other = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $other->assignRole('Tech');
        $other->givePermissionTo('contact.view');
        $this->apiAs($other, ['*']);
        $this->getJson('/api/v1/contacts/operations/'.$saved->json('operation_id'))->assertNotFound();
    }

    #[Test]
    public function contact_form_role_selection_uses_authoritative_roles_and_least_privilege_default(): void
    {
        [$client, $site] = $this->scope();
        $this->actingAs($this->actor);
        Livewire::test(ContactForm::class, ['activeClientId' => $client->id, 'activeSiteId' => $site->id])
            ->assertSet('portal_role', CustomerPortalMembership::ROLE_VIEWER)
            ->set('display_name', 'Portal person')->set('email', 'ui@example.test')->set('send_customer_portal_invitation', true)
            ->assertSee('Portal role')->set('portal_role', CustomerPortalMembership::ROLE_SITE_ADMIN)->call('save')->assertHasNoErrors();
        $this->assertDatabaseHas('customer_portal_invitations', ['email' => 'ui@example.test', 'role' => CustomerPortalMembership::ROLE_SITE_ADMIN, 'site_id' => $site->id]);
    }

    #[Test]
    public function all_workspace_and_repair_operations_have_successful_typed_readback(): void
    {
        [$client, $site] = $this->scope();
        $person = $this->person(['client_id' => $client->id, 'site_id' => $site->id, 'job_title' => 'Director']);
        $this->getJson('/api/v1/contacts/lookups/clients?q='.urlencode($client->name))->assertOk();
        $this->getJson('/api/v1/contacts/lookups/titles?q=Director')->assertOk()->assertJsonPath('data.0', 'Director');
        $this->getJson('/api/v1/contacts/context?contact_id='.$person->id)->assertOk()->assertJsonPath('data.contact.id', $person->id);
        $this->getJson('/api/v1/contacts/'.$person->id.'/signals')->assertOk()->assertJsonCount(0, 'data');
        $this->putJson('/api/v1/contacts/'.$person->id, ['job_title' => 'New'])->assertOk();
        $this->getJson('/api/v1/clients/'.$client->id.'/contacts')->assertOk();
        $this->postJson('/api/v1/clients/'.$client->id.'/contacts/bulk-fix', ['contact_ids' => [$person->id], 'dry_run' => true])->assertOk();
        $this->postJson('/api/v1/clients/'.$client->id.'/contacts/bulk-fix', ['contact_ids' => [$person->id], 'dry_run' => false], ['If-Match' => $this->postJson('/api/v1/clients/'.$client->id.'/contacts/bulk-fix', ['contact_ids' => [$person->id]] + ['dry_run' => true])->headers->get('ETag'), 'Idempotency-Key' => 'batch-command'])->assertOk();
        $legacy = ClientUser::factory()->create(['client_site_id' => $site->id, 'contact_id' => null, 'email' => 'legacy@example.test']);
        $this->postJson('/api/v1/clients/'.$client->id.'/contacts/legacy-orphans/cleanup', ['client_user_ids' => [$legacy->id], 'dry_run' => true])->assertOk();
        $this->postJson('/api/v1/clients/'.$client->id.'/contacts/legacy-orphans/cleanup', ['client_user_ids' => [$legacy->id], 'dry_run' => false], ['If-Match' => $this->postJson('/api/v1/clients/'.$client->id.'/contacts/legacy-orphans/cleanup', ['client_user_ids' => [$legacy->id]] + ['dry_run' => true])->headers->get('ETag'), 'Idempotency-Key' => 'cleanup-command'])->assertOk();
        $this->assertNotNull($legacy->fresh()->contact_id);
        $this->deleteJson('/api/v1/clients/'.$client->id.'/contacts/'.$person->id, ['dry_run' => true])->assertOk();
        $this->deleteJson('/api/v1/clients/'.$client->id.'/contacts/'.$person->id, [], ['If-Match' => $this->deleteJson('/api/v1/clients/'.$client->id.'/contacts/'.$person->id, ['dry_run' => true])->headers->get('ETag'), 'Idempotency-Key' => 'detach-command'])->assertOk();
        $this->assertFalse((bool) $person->clientUsers()->firstOrFail()->active);
        $this->getJson('/api/v1/contacts/'.$person->id)->assertOk();
    }

    #[Test]
    public function every_runtime_contact_operation_has_one_typed_swagger_contract(): void
    {
        $document = json_decode(file_get_contents(getenv('CONTACT_API_CONTRACT_DOCUMENT') ?: storage_path('api-docs/api-docs.json')), true);
        $runtime = [];
        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/') || ! str_starts_with($route->getActionName(), 'App\\Modules\\Contact\\Controllers\\Api\\V1\\')) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $key = strtolower($method).' /'.$route->uri();
                $runtime[] = $key;
                $op = $document['paths']['/'.$route->uri()][strtolower($method)] ?? null;
                $this->assertNotNull($op, $key);
                $this->assertArrayHasKey('operationId', $op);
                $this->assertNotEmpty($op['responses'][200]['content']['application/json']['schema'] ?? $op['responses'][201]['content']['application/json']['schema'] ?? null);
            }
        }
        $published = [];
        foreach ($document['paths'] as $path => $verbs) {
            foreach ($verbs as $method => $op) {
                if (in_array('Contacts', $op['tags'] ?? [], true)) {
                    $published[] = $method.' '.$path;
                }
            }
        }
        sort($runtime);
        sort($published);
        $this->assertSame($runtime, $published);
        $this->assertCount(19, $runtime);
    }

    #[Test]
    public function preview_is_bound_to_exact_action_target_body_and_token(): void
    {
        [$client, $site] = $this->scope();
        [$target, $targetSite] = $this->scope();
        $person = $this->person(['client_id' => $client->id, 'site_id' => $site->id]);
        $url = '/api/v1/contacts/'.$person->id.'/move';
        $body = ['target_client_id' => $target->id];
        $preview = $this->postJson($url, $body + ['dry_run' => true])->assertOk();
        $this->postJson($url, $body + ['reason' => 'unreviewed change'], ['If-Match' => $preview->headers->get('ETag'), 'Idempotency-Key' => 'preview-mismatch'])->assertStatus(412);
        $this->apiAs($this->actor, ['*']);
        $this->postJson($url, $body, ['If-Match' => $preview->headers->get('ETag'), 'Idempotency-Key' => 'preview-other-token'])->assertStatus(412);
        $this->assertSame($site->id, $person->clientUsers()->firstOrFail()->client_site_id);
    }

    #[Test]
    public function all_nineteen_operations_require_current_native_authority_and_their_token_abilities(): void
    {
        [$client, $site] = $this->scope();
        $saved = $this->postJson('/api/v1/contacts', ['display_name' => 'Guarded'])->assertCreated();
        $id = $saved->json('data.id');
        $operation = $saved->json('operation_id');
        $requests = [['GET', '/contacts'], ['POST', '/contacts'], ['GET', '/contacts/'.$id], ['PUT', '/contacts/'.$id], ['PATCH', '/contacts/'.$id],
            ['GET', '/contacts/context'], ['GET', '/contacts/lookups/clients'], ['GET', '/contacts/duplicates'], ['GET', '/contacts/statistics'],
            ['GET', '/contacts/settings'], ['PUT', '/contacts/settings'], ['POST', '/contacts/preview'], ['GET', '/contacts/'.$id.'/signals'],
            ['GET', '/contacts/operations/'.$operation], ['GET', '/clients/'.$client->id.'/contacts'], ['POST', '/contacts/'.$id.'/move'],
            ['POST', '/clients/'.$client->id.'/contacts/bulk-fix'], ['POST', '/clients/'.$client->id.'/contacts/legacy-orphans/cleanup'], ['DELETE', '/clients/'.$client->id.'/contacts/'.$id]];
        $this->actor->revokePermissionTo(['contact.view', 'contact.create', 'contact.update', 'contact.manage_settings']);
        foreach ($requests as [$method, $url]) {
            $this->json($method, '/api/v1'.$url)->assertForbidden();
        }
        $this->actor->givePermissionTo(['contact.view', 'contact.create', 'contact.update', 'contact.manage_settings']);
        $this->apiAs($this->actor, []);
        foreach ($requests as [$method, $url]) {
            $this->json($method, '/api/v1'.$url)->assertForbidden();
        }
        $this->assertDatabaseCount('contacts', 1);
    }

    #[Test]
    public function selecting_a_secondary_phone_makes_it_primary_and_updates_the_stable_bridge(): void
    {
        [$client, $site] = $this->scope();
        $person = $this->person(['client_id' => $client->id, 'site_id' => $site->id]);
        $phone = $person->phones()->create(['phone' => '99999999', 'label' => 'work', 'is_primary' => false, 'sms_allowed' => false]);
        $bridge = $person->clientUsers()->firstOrFail();
        $this->patchJson('/api/v1/contacts/'.$person->id, ['display_name' => 'Renamed', 'phone' => '99999999', 'sms_allowed' => true])
            ->assertOk()->assertJsonPath('data.primary_phone', '99999999')->assertJsonPath('data.primary_phone_sms_allowed', true);
        $this->assertSame('Renamed', $bridge->fresh()->name);
        $this->assertSame('99999999', $bridge->fresh()->phone);
        $this->assertSame(1, $person->phones()->where('is_primary', true)->count());
        $this->assertTrue($phone->fresh()->is_primary);
    }

    public static function portalEntryPoints(): array
    {
        return [['central', 'viewer'], ['central', 'customer_admin'], ['client', 'viewer'], ['client', 'customer_admin'], ['site', 'viewer'], ['site', 'site_admin']];
    }

    #[Test]
    #[DataProvider('portalEntryPoints')]
    public function all_contact_create_entry_points_persist_the_separate_predefined_portal_role(string $entry, string $role): void
    {
        [$client, $site] = $this->scope();
        $this->actingAs($this->actor);
        $args = $entry === 'site' ? ['activeClientId' => $client->id, 'activeSiteId' => $site->id]
            : ($entry === 'client' ? ['activeClientId' => $client->id] : []);
        $form = Livewire::test(ContactForm::class, $args)->assertSet('portal_role', CustomerPortalMembership::ROLE_VIEWER);
        if ($entry === 'central') {
            $form->call('selectClient', $client->id);
        }
        $form->set('display_name', 'Role person')->set('email', 'role@example.test')->set('job_title', 'CEO')->set('relation_type', 'billing_contact')
            ->set('send_customer_portal_invitation', true)->set('portal_role', $role)->call('save')->assertHasNoErrors();
        $this->assertDatabaseHas('customer_portal_invitations', ['email' => 'role@example.test', 'role' => $role, 'client_id' => $client->id]);
        $this->assertDatabaseHas('contacts', ['job_title' => 'CEO']);
        $this->assertDatabaseHas('contact_relations', ['relation_type' => 'billing_contact']);
        Queue::assertPushed(SendCustomerPortalInvitationEmail::class, 1);
    }

    #[Test]
    public function related_history_exposes_only_the_contact_summary_and_keeps_provider_payload_private(): void
    {
        $person = $this->person();
        $signal = \App\Modules\Signal\Models\Signal::create(['source_domain' => 'email', 'contact_id' => $person->id,
            'signal_type' => 'hard_bounce', 'severity' => 'error', 'confidence' => 95, 'summary' => 'Delivery failed',
            'payload' => ['private_provider_detail' => 'not-for-contact-api'], 'occurred_at' => now()]);
        \App\Modules\Signal\Models\Signal::create(['source_domain' => 'email', 'signal_type' => 'other', 'summary' => 'Unrelated', 'occurred_at' => now()]);
        $this->getJson('/api/v1/contacts/'.$person->id.'/signals')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $signal->id)->assertJsonMissingPath('data.0.payload');
    }

    public static function invalidPortalCreates(): array
    {
        return [['role', 'portal_role'], ['email', 'email'], ['inactive_client', 'client_id'], ['wrong_site', 'site_id'], ['existing_access', 'contact_id']];
    }

    #[Test]
    #[DataProvider('invalidPortalCreates')]
    public function invalid_portal_create_rolls_back_every_contact_invitation_and_delivery_effect(string $case, string $field): void
    {
        [$client, $site] = $this->scope();
        if ($case === 'inactive_client') {
            $client->update(['active' => false]);
        }
        if ($case === 'existing_access') {
            $person = $this->person(['email' => 'blocked@example.test', 'client_id' => $client->id, 'site_id' => $site->id]);
            $user = User::factory()->create(['contact_id' => $person->id, 'email' => 'blocked@example.test', 'status' => User::STATUS_ACTIVE]);
            $account = \App\Modules\CustomerPortal\Models\CustomerPortalAccount::create(['user_id' => $user->id, 'contact_id' => $person->id, 'status' => 'active']);
            CustomerPortalMembership::create(['customer_portal_account_id' => $account->id, 'client_id' => $client->id, 'site_id' => null, 'role' => 'viewer', 'status' => 'active']);
        }
        $before = [Contact::count(), DB::table('activity_log')->count()];
        $this->actingAs($this->actor);
        $form = Livewire::test(ContactForm::class, ['activeClientId' => $client->id])
            ->set('display_name', 'Blocked')->set('email', $case === 'email' ? 'invalid' : 'blocked@example.test')
            ->set('send_customer_portal_invitation', true);
        if ($case === 'role') {
            $form->set('portal_role', 'Superuser');
        }
        if ($case === 'wrong_site') {
            [, $foreignSite] = $this->scope();
            $form->set('site_id', $foreignSite->id);
        }
        $form->call('save')->assertHasErrors([$field]);
        $this->assertSame($before, [Contact::count(), DB::table('activity_log')->count()]);
        $this->assertDatabaseCount('customer_portal_invitations', 0);
        Queue::assertNothingPushed();
    }
}
