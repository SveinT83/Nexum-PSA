<?php

namespace App\Modules\Commercial\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Core\User;
use App\Modules\Commercial\Actions\ContractLifecycleWorkflow;
use App\Modules\Commercial\Jobs\SendCommercialDelivery;
use App\Modules\Commercial\Models\CommercialDeliveryAttempt;
use App\Modules\Commercial\Models\Contracts\ContractItem;
use App\Modules\Commercial\Models\Contracts\Contracts;
use App\Modules\Commercial\Models\Economy\Units;
use App\Modules\Commercial\Models\Services\Services;
use App\Modules\Commercial\Support\CommercialRevision;
use App\Modules\Commercial\Support\ContractTermSnapshotReadiness;
use App\Modules\System\Support\CompanyProfileSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CommercialApiParityTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->actor->givePermissionTo(['commercial.view', 'commercial.contract_manage', 'commercial.service_manage', 'commercial.package_manage', 'commercial.cost_manage', 'commercial.sla_manage', 'commercial.rate_manage', 'economy.manage_settings', 'client.view', 'commercial.timebank.quick-consume']);
        Sanctum::actingAs($this->actor, ['commercial.read', 'commercial.create', 'commercial.update', 'commercial.delete', 'commercial.send', 'commercial.approve', 'commercial.operations.read', 'commercial.settings.read', 'commercial.settings.update', 'commercial.timebank.read', 'commercial.timebank.consume']);
        app(CompanyProfileSettings::class)->update(['company_name' => 'API supplier', 'legal_name' => 'API Supplier AS', 'organization_number' => '999888777', 'address_line_1' => 'Street 1', 'postal_code' => '7600', 'city' => 'Levanger', 'country' => 'Norway']);
        config(['commercial.api_delivery_enabled' => true]);
    }

    private function service(): Services
    {
        $u = Units::query()->create(['name' => 'Item', 'short' => 'ea']);

        return Services::query()->create(['sku' => 'API-'.uniqid(), 'name' => 'Support', 'unitId' => $u->id, 'status' => 'Active', 'billing_cycle' => 'monthly', 'price_ex_vat' => '123.45', 'price_currency' => 'NOK', 'created_by_user_id' => $this->actor->id, 'updated_by_user_id' => $this->actor->id]);
    }

    private function contract(bool $ready = true): Contracts
    {
        $client = Client::factory()->create(['org_no' => '987654321', 'billing_email' => null]);
        $c = Contracts::query()->create(['client_id' => $client->id, 'description' => 'API contract', 'start_date' => today()->addMonth()->toDateString(), 'approval_status' => 'draft', 'created_by' => $this->actor->id, 'terms_snapshot' => 'Agreed service terms']);
        if ($ready) {
            $s = $this->service();
            ContractItem::query()->create(['contract_id' => $c->id, 'service_id' => $s->id, 'name' => 'Support', 'quantity' => 3, 'unit_price' => '123.45', 'price_currency' => 'NOK', 'billing_interval' => 'monthly', 'unit' => 'ea', 'setup_fee' => 0, 'uses_contract_default_sla' => true]);
        }
        app(ContractTermSnapshotReadiness::class)->markReviewed($c, $this->actor->id);

        return $c->fresh();
    }

    private function headers(Contracts|Services $c, string $key): array
    {
        return ['If-Match' => CommercialRevision::of($c->fresh()), 'Idempotency-Key' => $key];
    }

    #[Test]
    public function actual_bearer_requires_current_actor_permission_and_active_status(): void
    {
        $s = $this->service();
        $plain = $this->actor->createToken('Commercial regression', ['commercial.read'])->plainTextToken;
        auth()->forgetGuards();
        $this->withToken($plain)->getJson('/api/v1/commercial/services/'.$s->id)->assertOk();
        $this->actor->revokePermissionTo('commercial.service_manage');
        auth()->forgetGuards();
        $this->withToken($plain)->getJson('/api/v1/commercial/services/'.$s->id)->assertForbidden();
        $this->actor->forceFill(['status' => 'inactive'])->save();
        auth()->forgetGuards();
        $this->withToken($plain)->getJson('/api/v1/commercial/services/'.$s->id)->assertForbidden();
    }

    #[Test]
    public function token_ceiling_blocks_new_operations(): void
    {
        $s = $this->service();
        Sanctum::actingAs($this->actor, ['commercial.read']);
        $this->deleteJson('/api/v1/commercial/services/'.$s->id, [], $this->headers($s, 'blocked-delete-1'))->assertForbidden();
        $this->assertDatabaseHas('services', ['id' => $s->id]);
    }

    #[Test]
    public function strict_nested_and_server_owned_fields_are_rejected(): void
    {
        $s = $this->service();
        $this->patchJson('/api/v1/commercial/services/'.$s->id, ['source_integration_id' => 1])->assertUnprocessable();
        $this->patchJson('/api/v1/commercial/services/'.$s->id, ['time_rates' => ['1' => ['unknown' => true]]])->assertUnprocessable();
        $c = $this->contract();
        $this->postJson('/api/v1/commercial/contracts/'.$c->id.'/items', ['service_id' => $s->id, 'time_rates' => [['name' => 'Hidden', 'provider_secret' => 'x']]], $this->headers($c, 'unknown-nested-1'))->assertUnprocessable();
    }

    #[Test]
    public function pure_context_and_previews_do_not_persist_customer_or_legal_changes(): void
    {
        $c = $this->contract();
        $before = $c->getRawOriginal();
        $versions = DB::table('term_versions')->count();
        foreach (['services', 'packages', 'costs', 'terms', 'slas', 'time-rates', 'units', 'contracts'] as $kind) {
            $this->getJson('/api/v1/commercial/'.$kind.'/context')->assertOk();
        }
        $this->getJson('/api/v1/commercial/contracts/'.$c->id.'/terms')->assertOk();
        $this->postJson('/api/v1/commercial/contracts/'.$c->id.'/pricing-preview', ['items' => [['unit_price' => '0.10', 'quantity' => 3, 'billing_interval' => 'monthly']]])->assertOk()->assertJsonPath('data.pricing.monthly.decimal', '0.30');
        $this->assertSame($before, $c->fresh()->getRawOriginal());
        $this->assertSame($versions, DB::table('term_versions')->count());
        $this->assertDatabaseCount('commercial_command_receipts', 0);
    }

    #[Test]
    public function legal_version_commands_are_durable_and_conflicting_reuse_is_rejected(): void
    {
        $payload = ['name' => 'API terms', 'type' => 'general', 'content' => 'Agreed content'];
        $url = '/api/v1/commercial/terms';
        $h = ['Idempotency-Key' => 'create-terms-001'];
        $first = $this->postJson($url, $payload, $h)->assertCreated();
        $id = $first->json('data.id');
        $this->postJson($url, $payload, $h)->assertCreated()->assertJsonPath('replayed', true)->assertJsonPath('data.id', $id);
        $this->postJson($url, $payload + ['unused' => 'x'], $h)->assertStatus(409);
        $this->assertDatabaseCount('terms', 1);
        $this->assertDatabaseCount('term_versions', 1);
        $this->getJson('/api/v1/commercial/operations/'.$first->json('operation_id'))->assertOk()->assertJsonPath('data.result.data.id', $id);
        $this->actor->revokePermissionTo('commercial.contract_manage');
        $this->postJson($url, $payload, $h)->assertForbidden();
        $this->getJson('/api/v1/commercial/operations/'.$first->json('operation_id'))->assertForbidden();
    }

    #[Test]
    public function delete_replay_survives_deleted_record_but_requires_fresh_authority(): void
    {
        $s = $this->service();
        $url = '/api/v1/commercial/services/'.$s->id;
        $h = $this->headers($s, 'delete-service-001');
        $this->deleteJson($url, [], $h)->assertOk()->assertJsonPath('data.deleted', true);
        $this->deleteJson($url, [], $h)->assertOk()->assertJsonPath('replayed', true);
        $this->actor->revokePermissionTo('commercial.service_manage');
        $this->deleteJson($url, [], $h)->assertForbidden();
    }

    #[Test]
    public function new_writes_require_key_and_current_revision(): void
    {
        $this->postJson('/api/v1/commercial/units', ['name' => 'New'])->assertUnprocessable();
        $u = Units::query()->create(['name' => 'Unit', 'short' => 'ea']);
        $this->patchJson('/api/v1/commercial/units/'.$u->id, ['name' => 'Changed'], ['Idempotency-Key' => 'unit-update-001'])->assertStatus(428);
        $this->patchJson('/api/v1/commercial/units/'.$u->id, ['name' => 'Changed'], ['Idempotency-Key' => 'unit-update-002', 'If-Match' => str_repeat('0', 64)])->assertStatus(409);
        $this->assertSame('Unit', $u->fresh()->name);
    }

    #[Test]
    public function contract_line_commands_snapshot_and_replay_without_duplicate_lines(): void
    {
        $c = $this->contract(false);
        $s = $this->service();
        $url = '/api/v1/commercial/contracts/'.$c->id.'/items';
        $h = $this->headers($c, 'create-line-001');
        $payload = ['service_id' => $s->id, 'quantity' => 2];
        $first = $this->postJson($url, $payload, $h)->assertCreated()->assertJsonPath('data.unit_price', '123.45');
        $this->postJson($url, $payload, $h)->assertCreated()->assertJsonPath('replayed', true);
        $this->assertDatabaseCount('contract_items', 1);
        $other = $this->contract();
        $this->patchJson('/api/v1/commercial/contracts/'.$other->id.'/items/'.$first->json('data.id'), ['quantity' => 99], $this->headers($other, 'foreign-line-001'))->assertNotFound();
        $this->assertSame(2, ContractItem::query()->findOrFail($first->json('data.id'))->quantity);
    }

    #[Test]
    public function explicit_terms_refresh_and_manual_approval_preserve_immutable_document(): void
    {
        $c = $this->contract();
        $this->postJson('/api/v1/commercial/contracts/'.$c->id.'/approve-manual', [], $this->headers($c, 'manual-approve-001'))->assertOk()->assertJsonPath('data.approval_status', 'won');
        $captured = $c->fresh()->customer_document_snapshot;
        $this->assertNotNull($captured);
        $this->postJson('/api/v1/commercial/contracts/'.$c->id.'/terms/refresh', [], $this->headers($c, 'sent-refresh-001'))->assertStatus(409);
        $this->assertSame($captured, $c->fresh()->customer_document_snapshot);
    }

    #[Test]
    public function communication_preview_and_submit_freeze_reviewed_document_and_replay_once(): void
    {
        Queue::fake();
        $c = $this->contract();
        $base = '/api/v1/commercial/contracts/'.$c->id;
        $preview = $this->postJson($base.'/communication-preview', ['kind' => 'contract'], $this->headers($c, 'comm-preview-001'))->assertCreated();
        $this->assertSame('draft', $c->fresh()->approval_status);
        $p = ['preview_id' => $preview->json('data.id'), 'preview_hash' => $preview->json('data.preview_hash')];
        $h = $this->headers($c, 'comm-submit-001');
        $this->postJson($base.'/send-contract', $p, $h)->assertAccepted()->assertJsonPath('data.state', 'no_recipient')->assertJsonPath('data.inbox_delivery_confirmed', false);
        $this->postJson($base.'/send-contract', $p, $h)->assertAccepted()->assertJsonPath('replayed', true);
        $this->assertSame($preview->json('data.review.customer_document'), $c->fresh()->customer_document_snapshot);
        $this->assertSame('sent_contract', $c->fresh()->approval_status);
        $this->assertDatabaseCount('commercial_delivery_attempts', 1);
        Queue::assertNotPushed(SendCommercialDelivery::class);
        $this->getJson($base.'/deliveries/'.$p['preview_id'])->assertOk()->assertJsonPath('data.state', 'no_recipient');
    }

    #[Test]
    public function stale_communication_preview_and_default_off_delivery_fail_closed(): void
    {
        Queue::fake();
        $c = $this->contract();
        $base = '/api/v1/commercial/contracts/'.$c->id;
        $preview = $this->postJson($base.'/communication-preview', ['kind' => 'quote'], $this->headers($c, 'stale-preview-001'))->assertCreated();
        $p = ['preview_id' => $preview->json('data.id'), 'preview_hash' => $preview->json('data.preview_hash')];
        $c->client->update(['name' => 'Changed recipient identity']);
        $this->postJson($base.'/send-quote', $p, $this->headers($c, 'stale-submit-001'))->assertStatus(409);
        config(['commercial.api_delivery_enabled' => false]);
        $this->postJson($base.'/send-quote', $p, $this->headers($c, 'disabled-submit-001'))->assertStatus(409);
        $this->assertSame('draft', $c->fresh()->approval_status);
    }

    #[Test]
    public function public_reads_are_pure_and_acceptance_has_durable_exact_capability_replay(): void
    {
        $c = $this->contract();
        $result = app(ContractLifecycleWorkflow::class)->transitionForSending($c, 'sent_contract', null);
        $c = $result['contract'];
        $base = '/api/v1/commercial/public/contracts/'.$c->secure_token;
        $read = $this->getJson($base)->assertOk();
        $body = json_encode($read->json());
        foreach (['cost_unit_price', 'source_integration_id', 'secure_token', 'created_by', 'accepted_ip'] as $hidden) {
            $this->assertStringNotContainsString('"'.$hidden.'"', $body);
        }
        $this->assertNull($c->fresh()->viewed_at);
        $h = ['Idempotency-Key' => 'public-accept-001', 'If-Match' => $read->json('data.revision')];
        $this->postJson($base.'/accept', ['name' => 'Customer', 'confirm' => true], $h)->assertOk()->assertJsonPath('data.approval_status', 'won');
        $this->postJson($base.'/accept', ['name' => 'Customer', 'confirm' => true], $h)->assertOk()->assertJsonPath('replayed', true);
        $this->getJson('/api/v1/commercial/public/contracts/not-valid')->assertNotFound();
        $c->fresh()->forceFill(['secure_token' => 'rotated'])->save();
        $this->postJson($base.'/accept', ['name' => 'Customer', 'confirm' => true], $h)->assertNotFound();
    }

    #[Test]
    public function pagination_is_bounded_and_empty_context_never_creates_rows(): void
    {
        $this->getJson('/api/v1/commercial/services?per_page=101')->assertUnprocessable();
        $this->getJson('/api/v1/commercial/units/context/units')->assertOk()->assertJsonPath('meta.total', 0);
        $this->assertDatabaseCount('units', 0);
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('tech.sla.delete'));
    }

    #[Test]
    public function catalogues_preserve_composition_on_patch_and_delete_with_own_permissions(): void
    {
        $s = $this->service();
        $u = $s->unitId;
        $vendor = \App\Modules\Documentation\Models\Vendor::query()->create(['name' => 'API Vendor']);
        $cost = $this->postJson('/api/v1/commercial/costs', ['name' => 'API cost', 'cost' => 10, 'unitId' => $u, 'recurrence' => 'month', 'vendor_id' => $vendor->id], ['Idempotency-Key' => 'catalog-cost-001'])->assertCreated();
        $term = $this->postJson('/api/v1/commercial/terms', ['name' => 'Term', 'type' => 'general', 'content' => 'Legal text'], ['Idempotency-Key' => 'catalog-term-001'])->assertCreated();
        $rate = \App\Modules\Commercial\Models\TimeRate::query()->create(['name' => 'Labor', 'code' => 'LABOR', 'slug' => 'labor', 'rate_type' => 'labor', 'unit' => 'hour', 'amount_ex_vat' => '1000', 'currency' => 'NOK', 'is_active' => true]);
        $this->patchJson('/api/v1/commercial/services/'.$s->id, ['costs' => [$cost->json('data.id')], 'terms' => [$term->json('data.id')], 'time_rates' => [$rate->id => ['enabled' => true, 'amount_ex_vat' => 1100]]])->assertOk();
        $this->patchJson('/api/v1/commercial/services/'.$s->id, ['name' => 'Renamed'])->assertOk()->assertJsonCount(1, 'data.costs')->assertJsonCount(1, 'data.terms')->assertJsonCount(1, 'data.time_rates');
        $pkg = $this->postJson('/api/v1/commercial/packages', ['name' => 'API package', 'services' => [$s->id], 'terms' => [$term->json('data.id')], 'sales_price_user' => 99], ['Idempotency-Key' => 'catalog-pkg-001'])->assertCreated()->assertJsonCount(1, 'data.inherited_terms');
        $pid = $pkg->json('data.id');
        $revision = $pkg->json('data.revision');
        $this->patchJson('/api/v1/commercial/packages/'.$pid, ['description' => 'Changed'], ['Idempotency-Key' => 'catalog-pkg-002', 'If-Match' => $revision])->assertOk()->assertJsonCount(1, 'data.services')->assertJsonCount(1, 'data.terms');
        $this->postJson('/api/v1/commercial/packages/pricing-preview', ['service_ids' => [$s->id]])->assertOk()->assertJsonPath('data.total_cost', 10);
        $this->deleteJson('/api/v1/commercial/terms/'.$term->json('data.id'), [], ['Idempotency-Key' => 'inuse-term-001', 'If-Match' => $term->json('data.revision')])->assertStatus(409);
        foreach (['services', 'costs', 'packages', 'terms', 'slas', 'time-rates', 'units'] as $kind) {
            $this->getJson('/api/v1/commercial/'.$kind)->assertOk();
        }
    }

    #[Test]
    public function queued_delivery_is_claimed_once_and_ambiguous_result_cannot_be_recovered(): void
    {
        $c = $this->contract();
        $c = app(ContractLifecycleWorkflow::class)->transitionForSending($c, 'sent_contract', null)['contract'];
        $c->client->update(['billing_email' => 'synthetic@example.test']);
        $p = ['to' => 'synthetic@example.test', 'to_name' => 'Synthetic', 'cc' => null, 'subject' => 'Frozen', 'html' => '<p>Exact body</p>', 'text' => 'Exact body', 'binding' => ['account_id' => 1, 'provider_binding_version' => 1], 'token' => $c->secure_token, 'snapshot_hash' => hash('sha256', json_encode($c->customer_document_snapshot, JSON_THROW_ON_ERROR))];
        $a = CommercialDeliveryAttempt::query()->create(['id' => (string) \Illuminate\Support\Str::uuid(), 'actor_id' => $this->actor->id, 'contract_id' => $c->id, 'kind' => 'contract', 'state' => 'queued', 'revision' => str_repeat('a', 64), 'preview_hash' => str_repeat('b', 64), 'payload' => $p, 'expires_at' => now()->addMinutes(15)]);
        $transport = \Mockery::mock(\App\Modules\Commercial\Services\CommercialDeliveryTransport::class);
        $transport->shouldReceive('ready')->once()->andReturn(new \App\Modules\Email\Models\EmailAccount);
        $transport->shouldReceive('send')->once()->withArgs(fn ($account, $payload) => $payload === $p)->andThrow(new \RuntimeException('Synthetic ambiguous SMTP'));
        $this->app->instance(\App\Modules\Commercial\Services\CommercialDeliveryTransport::class, $transport);
        (new SendCommercialDelivery($a->id))->handle();
        (new SendCommercialDelivery($a->id))->handle();
        $this->assertSame('unknown', $a->fresh()->state);
        $this->getJson('/api/v1/commercial/contracts/'.$c->id.'/deliveries/'.$a->id)->assertOk()->assertJsonPath('data.needs_reconciliation', true)->assertJsonPath('data.recovery_allowed', false);

        $this->postJson('/api/v1/commercial/contracts/'.$c->id.'/deliveries/'.$a->id.'/recover', [], $this->headers($c, 'unknown-recover-001'))->assertStatus(409);
        $this->assertStringNotContainsString('Exact body', DB::table('commercial_delivery_attempts')->value('payload'));
    }

    #[Test]
    public function worker_revoked_actor_blocks_outbound_before_provider_access(): void
    {
        $c = $this->contract();
        $a = CommercialDeliveryAttempt::query()->create(['id' => (string) \Illuminate\Support\Str::uuid(), 'actor_id' => $this->actor->id, 'contract_id' => $c->id, 'kind' => 'contract', 'state' => 'queued', 'revision' => str_repeat('a', 64), 'preview_hash' => str_repeat('b', 64), 'payload' => [], 'expires_at' => now()->addMinutes(15)]);
        $this->actor->revokePermissionTo('commercial.contract_manage');
        $transport = \Mockery::mock(\App\Modules\Commercial\Services\CommercialDeliveryTransport::class);
        $transport->shouldNotReceive('ready');
        $transport->shouldNotReceive('send');
        $this->app->instance(\App\Modules\Commercial\Services\CommercialDeliveryTransport::class, $transport);
        (new SendCommercialDelivery($a->id))->handle();
        $this->assertSame('review_required', $a->fresh()->state);
    }

    #[Test]
    public function timebank_consumption_uses_balance_revision_and_durable_snapshot(): void
    {
        $c = $this->contract();
        $c->forceFill(['approval_status' => 'won', 'start_date' => today()->subMonth()->toDateString()])->save();
        $item = $c->items()->first();
        $item->service->update(['timebank_enabled' => true, 'timebank_minutes' => 120, 'timebank_interval' => 'monthly']);
        $rate = $item->timeRates()->create(['name' => 'Support', 'code' => 'SUP', 'unit' => 'hour', 'rate_type' => 'labor', 'amount_ex_vat' => 1000, 'currency' => 'NOK', 'is_active' => true]);
        app(\App\Modules\Commercial\Support\ClientTimebankQuickPolicy::class)->update(['quick_timebank_enabled' => true, 'quick_timebank_require_remaining' => true, 'quick_timebank_allow_overuse' => false, 'quick_timebank_require_note' => true, 'quick_timebank_max_minutes' => 120]);
        $base = '/api/v1/commercial/clients/'.$c->client_id.'/timebank';
        $ctx = $this->getJson($base)->assertOk();
        $revision = $ctx->json('data.balances.0.revision');
        $payload = ['contract_item_id' => $item->id, 'time_rate_source' => 'contract:'.$rate->id, 'work_date' => today()->toDateString(), 'minutes' => 30, 'note' => 'Synthetic timebank test'];
        $h = ['If-Match' => $revision, 'Idempotency-Key' => 'timebank-consume-001'];
        $response = $this->postJson($base.'/consumptions', $payload, $h)->assertCreated();
        $this->postJson($base.'/consumptions', $payload, $h)->assertCreated()->assertJsonPath('replayed', true);
        $this->postJson($base.'/consumptions', $payload, ['If-Match' => $revision, 'Idempotency-Key' => 'timebank-consume-002'])->assertStatus(409);
        $this->getJson($base.'/consumptions/'.$response->json('data.id'))->assertOk();
        $this->assertDatabaseCount('client_contract_time_consumptions', 1);
        $this->actor->syncPermissions(['commercial.timebank.quick-consume']);
        $this->getJson('/api/v1/commercial/operations/'.$response->json('operation_id'))->assertOk()->assertJsonPath('data.result.data.id', $response->json('data.id'));

    }

    #[Test]
    public function portal_api_requires_current_membership_client_and_legal_acceptance_evidence(): void
    {
        $c = $this->contract();
        $c = app(ContractLifecycleWorkflow::class)->transitionForSending($c, 'sent_contract', null)['contract'];
        $c->client->update(['active' => true]);
        $contact = \App\Modules\Contact\Models\Contact::query()->create(['type' => 'person', 'status' => 'active', 'display_name' => 'Portal API Contact']);
        $email = 'portal-api@example.test';
        \App\Modules\Contact\Models\ContactEmail::query()->create(['contact_id' => $contact->id, 'label' => 'work', 'email' => $email, 'is_primary' => true, 'is_verified' => true]);
        \App\Modules\Contact\Models\ContactRelation::query()->create(['contact_id' => $contact->id, 'related_type' => $c->client->getMorphClass(), 'related_id' => $c->client_id, 'relation_type' => 'contact', 'is_primary' => true]);
        $user = User::factory()->create(['contact_id' => $contact->id, 'email' => $email, 'status' => User::STATUS_ACTIVE]);
        foreach (\App\Modules\Notification\Support\NotificationTypeRegistry::labels(\App\Modules\Notification\Support\NotificationTypeRegistry::AUDIENCE_CUSTOMER_PORTAL) as $type => $label) {
            \App\Modules\Notification\Models\NotificationSetting::updateOrCreate(['user_id' => $user->id, 'notification_type' => $type], ['mail_enabled' => false, 'database_enabled' => true, 'nextcloud_talk_enabled' => false]);
        }
        $account = \App\Modules\CustomerPortal\Models\CustomerPortalAccount::query()->create(['user_id' => $user->id, 'contact_id' => $contact->id, 'status' => 'active']);
        $membership = \App\Modules\CustomerPortal\Models\CustomerPortalMembership::query()->create(['customer_portal_account_id' => $account->id, 'client_id' => $c->client_id, 'site_id' => null, 'role' => 'viewer', 'status' => 'active']);
        auth()->forgetGuards();
        $this->actingAs($user, 'web');
        $base = '/api/v1/commercial/portal/contracts';
        $this->getJson($base)->assertOk()->assertJsonPath('data.0.id', $c->id);
        $read = $this->getJson($base.'/'.$c->id)->assertOk();
        $h = ['If-Match' => $read->json('data.revision'), 'Idempotency-Key' => 'portal-accept-001'];
        $payload = ['name' => 'Portal API Customer', 'confirm' => true];
        $this->postJson($base.'/'.$c->id.'/accept', $payload, $h)->assertOk();
        $this->postJson($base.'/'.$c->id.'/accept', $payload, $h)->assertOk()->assertJsonPath('replayed', true);
        $this->assertDatabaseHas('contracts', ['id' => $c->id, 'portal_accepted_membership_id' => $membership->id, 'portal_accepted_contact_id' => $contact->id]);
        $this->assertDatabaseCount('legal_acceptance_events', 1);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseHas('customer_portal_audit_events', ['event' => 'portal_contract_accepted', 'customer_portal_account_id' => $account->id]);
        $foreign = $this->contract();
        $this->getJson($base.'/'.$foreign->id)->assertNotFound();
        $membership->forceFill(['status' => 'revoked'])->save();
        $this->postJson($base.'/'.$c->id.'/accept', $payload, $h)->assertForbidden();
    }

    #[Test]
    public function generated_openapi_covers_all_registered_commercial_methods_and_closed_inputs(): void
    {
        $json = json_decode(file_get_contents(storage_path('api-docs/api-docs.json')), true, 512, JSON_THROW_ON_ERROR);
        $routes = \Illuminate\Support\Facades\Route::getRoutes();
        foreach ($routes as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/commercial/')) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $this->assertArrayHasKey(strtolower($method), $json['paths']['/'.$route->uri()] ?? [], $method.' '.$route->uri());
            }
        }
        $this->assertSame('object', $json['components']['schemas']['CommercialInputServices']['properties']['time_rates']['type']);
        $this->assertArrayHasKey('enabled', $json['components']['schemas']['CommercialInputServices']['properties']['time_rates']['additionalProperties']['properties']);
        foreach (['Services', 'Contracts', 'Costs', 'Packages', 'Terms', 'Units', 'TimeRates', 'Items'] as $name) {
            $this->assertFalse($json['components']['schemas']['CommercialInput'.$name]['additionalProperties']);
        }
        $portal = $routes->getByName('api.v1.commercial.portal.contracts.accept');
        $this->assertContains('web', $portal->gatherMiddleware());
        $this->assertNotContains('auth:sanctum', $portal->gatherMiddleware());
    }

    #[Test]
    public function portal_accept_requires_csrf_even_with_authenticated_staff_token(): void
    {
        $middleware = new CommercialCsrfEnforcedForTest($this->app, $this->app['encrypter']);
        $this->app->instance(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class, $middleware);
        $this->postJson('/api/v1/commercial/portal/contracts/1/accept', ['name' => 'Denied', 'confirm' => true])->assertStatus(419);
    }

    #[Test]
    public function browser_and_api_share_the_durable_no_recipient_transition(): void
    {
        config(['commercial.durable_delivery_enabled' => true]);
        Queue::fake();
        $c = $this->contract();
        $this->actingAs($this->actor, 'web')->post(route('tech.contracts.send-contract', $c), [])->assertRedirect()->assertSessionHas('success');
        $this->assertSame('sent_contract', $c->fresh()->approval_status);
        $this->assertDatabaseHas('commercial_delivery_attempts', ['contract_id' => $c->id, 'state' => 'no_recipient', 'kind' => 'contract']);
        $this->assertNotNull($c->fresh()->customer_document_snapshot);
    }

    #[Test]
    public function staff_editor_economics_are_available_without_saving_preview_lines(): void
    {
        $c = $this->contract();
        $item = $c->items()->first();
        $before = $item->getRawOriginal();
        $base = '/api/v1/commercial/contracts/'.$c->id;
        $read = $this->getJson($base.'/pricing')->assertOk()->assertJsonStructure(['data' => ['pricing', 'editor' => ['total_cost_display', 'total_discount_display', 'annual_profit_display']]]);
        $this->postJson($base.'/pricing-preview', ['items' => [['item_id' => $item->id, 'unit_price' => '200.00', 'quantity' => 2, 'billing_interval' => 'monthly']]])->assertOk()->assertJsonPath('data.pricing.monthly.decimal', '400.00')->assertJsonPath('data.editor.annual_profit_display', '4 800,00 kr');
        $foreign = $this->contract();
        $this->postJson('/api/v1/commercial/contracts/'.$foreign->id.'/pricing-preview', ['items' => [['item_id' => $item->id, 'unit_price' => 1, 'quantity' => 1, 'billing_interval' => 'monthly']]])->assertNotFound();
        $this->assertSame($before, $item->fresh()->getRawOriginal());
    }

    #[Test]
    public function timebank_policy_update_has_pure_read_and_revision_replay_contract(): void
    {
        $url = '/api/v1/commercial/settings/timebank-policy';
        $before = DB::table('common_settings')->count();
        $read = $this->getJson($url)->assertOk();
        $this->assertSame($before, DB::table('common_settings')->count());
        $h = ['If-Match' => $read->json('revision'), 'Idempotency-Key' => 'policy-update-001'];
        $input = ['quick_timebank_max_minutes' => 45];
        $this->patchJson($url, $input, $h)->assertOk()->assertJsonPath('data.quick_timebank_max_minutes', 45);
        $this->patchJson($url, $input, $h)->assertOk()->assertJsonPath('replayed', true);
        $this->patchJson($url, ['quick_timebank_max_minutes' => 60], ['If-Match' => $read->json('revision'), 'Idempotency-Key' => 'policy-update-002'])->assertStatus(409);
    }

    #[Test]
    public function new_browser_routes_cannot_silently_escape_the_parity_inventory(): void
    {
        $baseline = json_decode(file_get_contents(base_path('docs/plans/2026-10-25-commercial-api-inventory.json')), true, 512, JSON_THROW_ON_ERROR);
        $mapped = collect($baseline['browser_routes'])->pluck('workflow', 'name');
        $seen = 0;
        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/')) {
                continue;
            }
            if (! str_starts_with($route->getActionName(), 'App\\Modules\\Commercial\\') && ! str_starts_with($route->getName() ?? '', 'tech.admin.settings.cs.')) {
                continue;
            }
            $this->assertNotNull($mapped->get($route->getName()), 'Map new Commercial web workflow: '.$route->getName());
            $seen++;
        }
        $this->assertGreaterThanOrEqual(65, $seen);
    }

    #[Test]
    public function api_legacy_attestation_and_pdf_use_the_named_reviewed_frozen_document(): void
    {
        $c = $this->contract();
        $c = app(ContractLifecycleWorkflow::class)->transitionForSending($c, 'sent_contract', null)['contract'];
        $c->forceFill(['customer_document_snapshot' => null])->saveQuietly();
        $c->refresh();
        $base = '/api/v1/commercial/contracts/'.$c->id;
        $this->getJson($base)->assertStatus(409);
        $preview = $this->getJson($base.'/customer-document/attestation-preview?document_type=Avtale')->assertOk();
        $payload = ['attestation_note' => 'Synthetic original document reviewed by the named test actor.', 'confirm_legacy_attestation' => true, 'legacy_attestation_fingerprint' => $preview->json('data.fingerprint'), 'legacy_attestation_document_type' => 'Avtale'];
        $this->postJson($base.'/customer-document/attest-legacy', $payload, $this->headers($c, 'attest-legacy-001'))->assertOk()->assertJsonPath('data.customer_document.document.type', 'Avtale');
        $bytes = $this->get($base.'/pdf', ['Accept' => 'application/pdf'])->assertOk()->assertHeader('Content-Type', 'application/pdf')->getContent();
        $this->assertStringStartsWith('%PDF', $bytes);
        $this->assertNotNull($c->fresh()->customer_document_snapshot);
    }

    #[Test]
    public function smtp_acceptance_is_recorded_once_without_claiming_inbox_delivery(): void
    {
        $c = $this->contract();
        $c = app(ContractLifecycleWorkflow::class)->transitionForSending($c, 'sent_contract', null)['contract'];
        $c->client->update(['billing_email' => 'synthetic@example.test']);
        $payload = ['to' => 'synthetic@example.test', 'to_name' => 'Synthetic', 'cc' => null, 'subject' => 'Frozen', 'html' => '<p>Exact</p>', 'text' => 'Exact', 'binding' => ['account_id' => 1, 'provider_binding_version' => 1], 'token' => $c->secure_token, 'snapshot_hash' => hash('sha256', json_encode($c->customer_document_snapshot, JSON_THROW_ON_ERROR))];
        $a = CommercialDeliveryAttempt::query()->create(['id' => (string) \Illuminate\Support\Str::uuid(), 'actor_id' => $this->actor->id, 'contract_id' => $c->id, 'kind' => 'contract', 'state' => 'queued', 'revision' => str_repeat('a', 64), 'preview_hash' => str_repeat('b', 64), 'payload' => $payload, 'expires_at' => now()->addMinutes(15)]);
        $transport = \Mockery::mock(\App\Modules\Commercial\Services\CommercialDeliveryTransport::class);
        $transport->shouldReceive('ready')->once()->andReturn(new \App\Modules\Email\Models\EmailAccount);
        $transport->shouldReceive('send')->once()->andReturn('synthetic-message-id');
        $this->app->instance(\App\Modules\Commercial\Services\CommercialDeliveryTransport::class, $transport);
        (new SendCommercialDelivery($a->id))->handle();
        (new SendCommercialDelivery($a->id))->handle();
        $read = $this->getJson('/api/v1/commercial/contracts/'.$c->id.'/deliveries/'.$a->id)->assertOk()->assertJsonPath('data.state', 'smtp_accepted')->assertJsonPath('data.message_id', 'synthetic-message-id')->assertJsonPath('data.inbox_delivery_confirmed', false)->assertJsonPath('data.provider_submission_confirmed', true)->assertJsonPath('data.recovery_allowed', false)->assertJsonPath('data.needs_reconciliation', false);
        $this->assertNotNull($a->fresh()->finished_at);
    }

    #[Test]
    public function unit_delete_via_legacy_update_action_still_requires_delete_ability_on_replay(): void
    {
        $u = Units::query()->create(['name' => 'Protected unit', 'short' => 'ea']);
        $url = '/api/v1/commercial/units/'.$u->id;
        $h = ['Idempotency-Key' => 'unit-action-delete-001', 'If-Match' => CommercialRevision::of($u)];
        Sanctum::actingAs($this->actor, ['commercial.update']);
        $this->patchJson($url, ['action' => 'delete'], $h)->assertForbidden();
        $this->assertDatabaseHas('units', ['id' => $u->id]);
        Sanctum::actingAs($this->actor, ['commercial.update', 'commercial.delete']);
        $this->patchJson($url, ['action' => 'delete'], $h)->assertOk()->assertJsonPath('data.deleted', true);
        $this->patchJson($url, ['action' => 'delete'], $h)->assertOk()->assertJsonPath('replayed', true);
        Sanctum::actingAs($this->actor, ['commercial.update']);
        $this->patchJson($url, ['action' => 'delete'], $h)->assertForbidden();
    }

    #[Test]
    public function create_revisions_include_database_defaults_and_are_immediately_usable(): void
    {
        $created = $this->postJson('/api/v1/commercial/units', ['name' => 'Fresh unit'], ['Idempotency-Key' => 'fresh-unit-create-001'])->assertCreated();
        $url = '/api/v1/commercial/units/'.$created->json('data.id');
        $this->getJson($url)->assertOk()->assertJsonPath('data.revision', $created->json('data.revision'));
        $this->patchJson($url, ['name' => 'Updated fresh unit'], ['Idempotency-Key' => 'fresh-unit-update-001', 'If-Match' => $created->json('data.revision')])->assertOk()->assertJsonPath('data.name', 'Updated fresh unit');
        $client = Client::factory()->create();
        $c = $this->postJson('/api/v1/commercial/contracts', ['client_id' => $client->id, 'start_date' => today()->addMonth()->toDateString()], ['Idempotency-Key' => 'fresh-contract-create-001'])->assertCreated();
        $this->getJson('/api/v1/commercial/contracts/'.$c->json('data.id'))->assertOk()->assertJsonPath('data.revision', $c->json('data.revision'));
    }

    #[Test]
    public function selected_technician_and_form_defaults_match_browser_and_api(): void
    {
        $role = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'service.view', 'guard_name' => 'web']);
        $technician = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $technician->assignRole($role);
        $lookup = $this->getJson('/api/v1/commercial/contracts/context/technicians')->assertOk()->assertJsonPath('data.0.id', $technician->id)->assertJsonCount(1, 'data');
        $context = $this->getJson('/api/v1/commercial/contracts/context')->assertOk();
        $defaults = $context->json('data.defaults');
        $this->assertSame(app(\App\Modules\Commercial\Queries\CommercialContractFormContext::class)->defaults(), $defaults);
        $client = Client::factory()->create();
        $payload = ['client_id' => $client->id, 'created_by' => $technician->id, 'start_date' => $defaults['start_date'], 'end_date' => $defaults['end_date']];
        $api = $this->postJson('/api/v1/commercial/contracts', $payload, ['Idempotency-Key' => 'technician-assignment-001'])->assertCreated()->assertJsonPath('data.created_by', $technician->id);
        $this->actingAs($this->actor, 'web')->post(route('tech.contracts.store'), $payload)->assertRedirect();
        $this->assertDatabaseCount('contracts', 2);
        $this->assertSame(2, Contracts::query()->where('created_by', $technician->id)->count());
    }
}

/** This one test exercises real CSRF instead of Laravel's ordinary unit-test bypass. */
class CommercialCsrfEnforcedForTest extends \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken
{
    protected function runningUnitTests()
    {
        return false;
    }
}
