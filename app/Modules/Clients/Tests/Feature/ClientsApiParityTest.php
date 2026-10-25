<?php

namespace App\Modules\Clients\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Clients\ClientFormat;
use App\Models\Clients\ClientSite;
use App\Models\Core\User;
use App\Modules\Clients\Support\ClientRevision;
use App\Modules\Commercial\Models\Contracts\ClientContractTimeConsumption;
use App\Modules\Commercial\Models\Contracts\ContractItem;
use App\Modules\Commercial\Models\Contracts\Contracts;
use App\Modules\Commercial\Models\Economy\Units;
use App\Modules\Commercial\Models\Services\Services;
use App\Modules\Commercial\Models\TimeRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Operational HTTP contracts exercise actual policies, persistence and rejected side effects. */
class ClientsApiParityTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private array $scopes = ['clients.read', 'clients.create', 'clients.update', 'clients.delete', 'clients.settings.read', 'clients.settings.update', 'clients.operations.read', 'clients.time.read', 'clients.time.update', 'clients.licenses.read', 'clients.licenses.write'];

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'Tech']);
        Role::firstOrCreate(['name' => 'Admin']);
        $this->actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->actor->assignRole('Tech');
        $this->actor->givePermissionTo(['client.view', 'client.create', 'client.update', 'client.delete', 'client.manage_settings', 'contact.view']);
        Sanctum::actingAs($this->actor, $this->scopes);
        Http::preventStrayRequests();
        Queue::fake();
    }

    private function headers(string $key, ?string $revision = null): array
    {
        return ['Idempotency-Key' => $key] + ($revision ? ['If-Match' => $revision] : []);
    }

    #[Test]
    public function all_editable_client_fields_are_read_back_without_unrelated_owner_data(): void
    {
        $client = Client::factory()->create(['notes' => 'Original', 'website' => 'https://example.test']);
        $this->patchJson('/api/v1/clients/'.$client->id, ['notes' => 'Verified notes', 'website' => 'https://changed.test', 'active' => false])
            ->assertOk()->assertJsonPath('data.notes', 'Verified notes')->assertJsonPath('data.active', false);
        $this->getJson('/api/v1/clients/'.$client->id)->assertOk()
            ->assertJsonPath('data.website', 'https://changed.test')->assertJsonPath('data.notes', 'Verified notes')
            ->assertJsonMissingPath('data.risk_score')->assertJsonStructure(['data' => ['revision', 'client_format_id', 'sales_category_id', 'lead_temperature']]);
        $this->assertSame('Verified notes', $client->fresh()->notes);
    }

    #[Test]
    public function actor_and_scope_denials_are_independent_and_use_current_database_state(): void
    {
        $client = Client::factory()->create();
        $this->actor->revokePermissionTo('client.view');
        $this->getJson('/api/v1/clients/'.$client->id)->assertForbidden();
        $this->actor->givePermissionTo('client.view');
        Sanctum::actingAs($this->actor, ['clients.read']);
        $this->getJson('/api/v1/clients/'.$client->id)->assertOk();
        $this->patchJson('/api/v1/clients/'.$client->id, ['notes' => 'Denied'])->assertForbidden();
        Sanctum::actingAs($this->actor, $this->scopes);
        $this->actor->update(['status' => 'inactive']);
        $this->getJson('/api/v1/clients')->assertForbidden();
        $this->postJson('/api/v1/clients', ['name' => 'Denied'])->assertForbidden();
    }

    #[Test]
    public function context_and_workspace_reads_do_not_create_state_or_leak_owner_links(): void
    {
        $client = Client::factory()->create();
        $integrations = \App\Models\System\Integrations\Integration::count();
        $this->getJson('/api/v1/clients/context')->assertOk()->assertJsonPath('data.number.reserved', false);
        $this->getJson('/api/v1/clients/'.$client->id.'/workspace')->assertOk()
            ->assertJsonMissingPath('data.links.tickets')->assertJsonMissingPath('data.links.contracts');
        $this->assertDatabaseCount('integrations', $integrations);
        $this->assertDatabaseCount('client_api_commands', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    #[Test]
    public function composite_creation_replays_one_client_site_and_canonical_contact_without_copying_billing_email(): void
    {
        $body = ['name' => 'Composite API Client', 'site' => ['name' => 'Main', 'zip' => '0123'], 'primary_contact' => ['display_name' => 'Primary person', 'email' => 'primary@example.test']];
        $headers = $this->headers('composite-creation-key');
        $first = $this->postJson('/api/v1/clients', $body, $headers)->assertCreated()->json('data.id');
        $this->postJson('/api/v1/clients', $body, $headers)->assertCreated()->assertJsonPath('data.id', $first);
        $this->assertDatabaseCount('clients', 1);
        $this->assertDatabaseCount('client_sites', 1);
        $this->assertDatabaseCount('contacts', 1);
        $this->assertDatabaseCount('client_users', 1);
        $this->assertNull(Client::findOrFail($first)->billing_email);
        $this->assertSame('0123', ClientSite::first()->zip);
        $this->postJson('/api/v1/clients', $body + ['notes' => 'Another payload'], $headers)->assertConflict();
        $this->assertDatabaseCount('clients', 1);
    }

    #[Test]
    public function failed_composite_validation_and_unsupported_input_leave_no_partial_graph(): void
    {
        $this->postJson('/api/v1/clients', ['name' => 'Broken', 'primary_contact' => ['email' => 'person@example.test']], $this->headers('invalid-creation-key'))->assertUnprocessable();
        $this->postJson('/api/v1/clients', ['name' => 'Broken', 'site' => ['name' => 'Main', 'secret' => 'ignored']])->assertUnprocessable();
        $this->postJson('/api/v1/clients', ['name' => 'Broken', 'created_by' => 99])->assertUnprocessable();
        $this->postJson('/api/v1/clients', ['name' => 'Empty contact', 'primary_contact' => []], $this->headers('empty-contact-key'))->assertUnprocessable();
        $this->assertDatabaseCount('clients', 0);
        $this->assertDatabaseCount('client_sites', 0);
        $this->assertDatabaseCount('contacts', 0);
    }

    #[Test]
    public function client_filters_sort_and_pagination_match_actual_eligible_records(): void
    {
        $format = ClientFormat::first();
        Client::factory()->create(['name' => 'Target filter', 'client_format_id' => $format->id]);
        Client::factory()->create(['name' => 'Other filter', 'client_format_id' => null]);
        $this->getJson('/api/v1/clients?format_id='.$format->id.'&contract_filter=without_contract&sort=client_number&per_page=1')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.name', 'Target filter');
        foreach (['per_page=101', 'page=0', 'sort=raw_sql', 'direction=raw_sql'] as $query) {
            $this->getJson('/api/v1/clients?'.$query)->assertUnprocessable();
        }
    }

    #[Test]
    public function settings_and_notes_have_distinct_rights_and_revision_checks(): void
    {
        $client = Client::factory()->create(['notes' => 'Before', 'billing_email' => 'billing@example.test', 'active' => true]);
        $rev = $this->getJson('/api/v1/clients/'.$client->id)->json('data.revision');
        $this->patchJson('/api/v1/clients/'.$client->id.'/notes', ['notes' => 'After'], $this->headers('notes-change-key', $rev))->assertOk()->assertJsonPath('data.notes', 'After');
        $this->assertSame('billing@example.test', $client->fresh()->billing_email);
        $this->patchJson('/api/v1/clients/'.$client->id.'/notes', ['notes' => 'Stale'], $this->headers('notes-stale-key', $rev))->assertConflict();
        $this->patchJson('/api/v1/clients/'.$client->id.'/notes', ['notes' => 'Missing revision'], $this->headers('notes-missing-key'))->assertStatus(428);
        $this->actor->revokePermissionTo('client.manage_settings');
        $this->getJson('/api/v1/clients/'.$client->id.'/settings')->assertForbidden();
        $this->actor->revokePermissionTo('client.update');
        $this->patchJson('/api/v1/clients/'.$client->id.'/notes', ['notes' => 'After'], $this->headers('notes-change-key', $rev))->assertForbidden();
    }

    #[Test]
    public function site_detail_default_and_delete_are_real_and_replay_is_not_a_second_delete(): void
    {
        $client = Client::factory()->create();
        $first = ClientSite::factory()->create(['client_id' => $client->id, 'is_default' => true]);
        $second = $this->postJson('/api/v1/clients/'.$client->id.'/sites', ['name' => 'Second', 'is_default' => true])->assertCreated()->json('data.id');
        $this->assertFalse((bool) $first->fresh()->is_default);
        $revision = $this->getJson('/api/v1/client-sites/'.$second)->assertOk()->json('data.revision');
        $headers = $this->headers('site-delete-key', $revision);
        $this->deleteJson('/api/v1/client-sites/'.$second, [], $headers)->assertOk()->assertJsonPath('data.deleted', true);
        $this->deleteJson('/api/v1/client-sites/'.$second, [], $headers)->assertOk()->assertJsonPath('replayed', true);
        $this->getJson('/api/v1/client-sites/'.$second)->assertNotFound();
        $this->assertDatabaseHas('client_sites', ['id' => $first->id]);
    }

    #[Test]
    public function formats_follow_current_permission_based_admin_access_and_preserve_omitted_values(): void
    {
        $this->actor->revokePermissionTo('client.manage_settings');
        $this->getJson('/api/v1/client-settings/formats')->assertForbidden();
        $this->actor->givePermissionTo('client.manage_settings');
        $body = ['name' => 'API format', 'code' => 'APIFMT', 'is_active' => true, 'sort_order' => 17];
        $first = $this->postJson('/api/v1/client-settings/formats', $body, $this->headers('format-create-key'))->assertCreated();
        $id = $first->json('data.id');
        $this->patchJson('/api/v1/client-settings/formats/'.$id, ['name' => 'Changed format'], $this->headers('format-update-key', $first->json('data.revision')))
            ->assertOk()->assertJsonPath('data.is_active', true)->assertJsonPath('data.sort_order', 17);
        $this->assertDatabaseHas('client_formats', ['id' => $id, 'code' => 'APIFMT', 'name' => 'Changed format']);
    }

    #[Test]
    public function operation_status_is_bound_to_its_actual_actor_and_token(): void
    {
        $client = Client::factory()->create();
        $result = $this->patchJson('/api/v1/clients/'.$client->id.'/notes', ['notes' => 'Saved'], $this->headers('actor-bound-notes', ClientRevision::of($client)))->assertOk();
        $this->getJson('/api/v1/client-operations/'.$result->json('operation_id'))->assertOk()->assertJsonPath('data.state', 'completed');
        $other = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $other->givePermissionTo('client.view');
        Sanctum::actingAs($other, ['clients.operations.read']);
        $this->getJson('/api/v1/client-operations/'.$result->json('operation_id'))->assertNotFound();
    }

    #[Test]
    public function ticket_time_correction_preserves_source_authority_and_blocks_ordered_or_foreign_rows(): void
    {
        $this->actor->givePermissionTo('ticket.view');
        $client = Client::factory()->create();
        $ticket = \App\Modules\Ticket\Models\Ticket::factory()->create(['client_id' => $client->id]);
        $entry = $ticket->timeEntries()->create(['user_id' => $this->actor->id, 'work_date' => today()->toDateString(), 'minutes' => 15, 'note' => 'Before']);
        $url = '/api/v1/clients/'.$client->id.'/time-usage/ticket/'.$entry->id;
        $before = \App\Modules\Economy\Models\EconomyOrderLine::count();
        $rev = $this->getJson($url)->assertOk()->json('data.revision');
        $this->getJson('/api/v1/clients/'.$client->id.'/time-usage')->assertOk()->assertJsonPath('total', 1);
        $this->assertDatabaseCount('economy_order_lines', $before);
        $body = ['work_date' => today()->toDateString(), 'minutes' => 35, 'note' => 'Corrected'];
        $headers = $this->headers('ticket-correction-key', $rev);
        $this->patchJson($url, $body, $headers)->assertOk()->assertJsonPath('data.minutes', 35)->assertJsonPath('data.billing_status', 'pending');
        $this->patchJson($url, $body, $headers)->assertOk()->assertJsonPath('replayed', true);
        $this->assertSame(35, $entry->fresh()->minutes);
        $other = Client::factory()->create();
        $this->getJson('/api/v1/clients/'.$other->id.'/time-usage/ticket/'.$entry->id)->assertNotFound();
        $order = \App\Modules\Economy\Models\EconomyOrder::create(['client_id' => $client->id, 'order_number' => 'CLIENT-TIME-TEST',
            'period_start' => today()->startOfMonth()->toDateString(), 'period_end' => today()->endOfMonth()->toDateString(), 'status' => 'draft']);
        \App\Modules\Economy\Models\EconomyOrderLine::create(['economy_order_id' => $order->id, 'client_id' => $client->id,
            'line_type' => 'time', 'description' => 'Synthetic ordered source', 'quantity' => 1, 'unit' => 'hour', 'unit_price_ex_vat' => 100,
            'line_total_ex_vat' => 100, 'currency' => 'NOK', 'status' => 'active', 'source_type' => $entry->getMorphClass(), 'source_id' => $entry->id]);
        $this->patchJson($url, $body + ['invoice_text' => 'Denied'], $this->headers('ordered-correction-key', ClientRevision::of($entry->fresh())))->assertUnprocessable();
        $this->assertSame(35, $entry->fresh()->minutes);
    }

    #[Test]
    public function source_owner_is_rechecked_on_replay_and_another_users_time_cannot_be_edited(): void
    {
        $this->actor->givePermissionTo('ticket.view');
        $client = Client::factory()->create();
        $ticket = \App\Modules\Ticket\Models\Ticket::factory()->create(['client_id' => $client->id]);
        $other = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $entry = $ticket->timeEntries()->create(['user_id' => $other->id, 'work_date' => today()->toDateString(), 'minutes' => 15]);
        $url = '/api/v1/clients/'.$client->id.'/time-usage/ticket/'.$entry->id;
        $this->patchJson($url, ['work_date' => today()->toDateString(), 'minutes' => 45], $this->headers('foreign-owner-key', ClientRevision::of($entry)))->assertForbidden();
        $this->assertSame(15, $entry->fresh()->minutes);
        Sanctum::actingAs($this->actor, ['clients.read', 'clients.time.read']);
        $this->patchJson($url, ['work_date' => today()->toDateString(), 'minutes' => 45])->assertForbidden();
    }

    #[Test]
    public function task_actual_time_and_coupled_ticket_billing_are_not_independently_corrected(): void
    {
        $this->actor->givePermissionTo(['task.view', 'ticket.view']);
        $client = Client::factory()->create();
        $task = app(\App\Modules\Task\Actions\StoreTask::class)->handle(['title' => 'Client actual time'], $this->actor, $client);
        $entry = $task->timeEntries()->create(['user_id' => $this->actor->id, 'work_date' => today()->toDateString(), 'minutes' => 15]);
        $url = '/api/v1/clients/'.$client->id.'/time-usage/task/'.$entry->id;
        $this->patchJson($url, ['work_date' => today()->toDateString(), 'minutes' => 20], $this->headers('task-correction-key', ClientRevision::of($entry)))
            ->assertOk()->assertJsonPath('data.minutes', 20);
        $ticket = \App\Modules\Ticket\Models\Ticket::factory()->create(['client_id' => $client->id]);
        $coupled = app(\App\Modules\Task\Actions\StoreTask::class)->handle(['title' => 'Coupled task'], $this->actor, $ticket);
        $tracked = $coupled->timeEntries()->create(['user_id' => $this->actor->id, 'work_date' => today()->toDateString(), 'minutes' => 15]);
        $this->patchJson('/api/v1/clients/'.$client->id.'/time-usage/task/'.$tracked->id,
            ['work_date' => today()->toDateString(), 'minutes' => 25], $this->headers('coupled-correction-key', ClientRevision::of($tracked)))->assertUnprocessable();
        $this->assertSame(15, $tracked->fresh()->minutes);
    }

    #[Test]
    public function quick_correction_recalculates_balance_and_returns_exact_persisted_rate_context(): void
    {
        $this->actor->givePermissionTo('commercial.timebank.view');
        [$client, $contract, $item] = $this->createClientTimebankContract(120);
        $entry = ClientContractTimeConsumption::create(['client_id' => $client->id, 'contract_id' => $contract->id, 'contract_item_id' => $item->id,
            'user_id' => $this->actor->id, 'work_date' => today()->toDateString(), 'minutes' => 15, 'source' => 'quick_client',
            'period_start' => today()->startOfMonth()->toDateString(), 'period_end' => today()->endOfMonth()->toDateString(),
            'included_minutes_snapshot' => 120, 'used_before_minutes_snapshot' => 0, 'overused_minutes' => 0]);
        $url = '/api/v1/clients/'.$client->id.'/time-usage/quick/'.$entry->id;
        $this->patchJson($url, ['work_date' => today()->toDateString(), 'minutes' => 35, 'note' => 'Actual correction'],
            $this->headers('quick-correction-key', ClientRevision::of($entry)))->assertOk()->assertJsonPath('data.minutes', 35)->assertJsonPath('data.overused_minutes', 0);
        $this->getJson('/api/v1/clients/'.$client->id.'/time-usage')->assertOk()->assertJsonPath('data.0.current_rate_source', null);
        $this->assertSame(35, $entry->fresh()->minutes);
    }

    #[Test]
    public function related_workspace_requires_both_owner_scopes_and_current_permissions(): void
    {
        $client = Client::factory()->create();
        $this->actor->givePermissionTo(['ticket.view', 'task.view']);
        $ticket = \App\Modules\Ticket\Models\Ticket::factory()->create(['client_id' => $client->id]);
        $url = '/api/v1/clients/'.$client->id.'/related/tickets';
        $this->getJson($url)->assertForbidden();
        Sanctum::actingAs($this->actor, ['clients.read', 'tickets.read', 'tasks.read']);
        $this->getJson($url)->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $ticket->id);
        $other = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        app(\App\Modules\Task\Actions\StoreTask::class)->handle(['title' => 'Private outside actor', 'visibility' => \App\Modules\Task\Models\Task::VISIBILITY_PRIVATE], $other, $client);
        $this->getJson('/api/v1/clients/'.$client->id.'/related/tasks')->assertOk()->assertJsonPath('total', 0);
        $this->actor->revokePermissionTo('ticket.view');
        $this->getJson($url)->assertForbidden();
    }

    private function createClientTimebankContract(int $includedMinutes): array
    {
        $client = Client::factory()->create(['name' => 'Timebank Client AS']);
        $unit = Units::query()->create(['name' => 'Month', 'short' => 'mo']);
        $service = Services::query()->create([
            'sku' => 'SUPPORT-TIMEBANK-'.$includedMinutes,
            'name' => 'Support Timebank',
            'unitId' => $unit->id,
            'status' => 'published',
            'availability_audience' => 'business',
            'orderable' => false,
            'taxable' => 25,
            'billing_cycle' => 'monthly',
            'price_ex_vat' => 0,
            'price_including_tax' => 0,
            'timebank_enabled' => true,
            'timebank_minutes' => $includedMinutes,
            'timebank_interval' => 'monthly',
            'created_by_user_id' => $this->actor->id,
            'updated_by_user_id' => $this->actor->id,
        ]);
        TimeRate::query()->firstOrCreate(
            ['code' => 'TIME_WITH_CONTRACT'],
            [
                'name' => 'Time with contract',
                'slug' => 'time-with-contract',
                'rate_type' => 'labor',
                'unit' => 'hour',
                'amount_ex_vat' => 650,
                'currency' => 'NOK',
                'applies_without_contract' => false,
                'applies_with_contract' => true,
                'is_active' => true,
                'sort_order' => 20,
            ],
        );
        $contract = Contracts::query()->create([
            'client_id' => $client->id,
            'created_by' => $this->actor->id,
            'description' => 'Managed support',
            'approval_status' => 'won',
            'start_date' => now()->startOfMonth()->toDateString(),
            'end_date' => now()->endOfMonth()->toDateString(),
        ]);
        $item = ContractItem::query()->create([
            'contract_id' => $contract->id,
            'service_id' => $service->id,
            'name' => 'Support Timebank',
            'sku' => $service->sku,
            'unit_price' => 0,
            'quantity' => 1,
            'unit' => $unit->name,
            'billing_interval' => 'monthly',
            'discount_value' => 0,
            'discount_type' => 'amount',
            'setup_fee' => 0,
        ]);

        return [$client, $contract, $item];
    }

    #[Test]
    public function signal_projection_needs_its_explicit_scope_and_never_returns_raw_payload(): void
    {
        $client = Client::factory()->create();
        $this->actor->givePermissionTo('signal.view');
        \App\Modules\Signal\Models\Signal::create(['client_id' => $client->id, 'source_domain' => 'test', 'source_type' => 'test',
            'signal_type' => 'synthetic', 'severity' => 'info', 'confidence' => 1, 'status' => 'active', 'summary' => 'Permitted summary',
            'payload' => ['private_source' => 'Never projected'], 'occurred_at' => now()]);
        $url = '/api/v1/clients/'.$client->id.'/related/signals';
        $this->getJson($url)->assertForbidden();
        Sanctum::actingAs($this->actor, ['clients.read', 'clients.signals.read']);
        $this->getJson($url)->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.summary', 'Permitted summary')->assertJsonMissingPath('data.0.payload');
        $this->actor->revokePermissionTo('signal.view');
        $this->getJson($url)->assertForbidden();
    }

    #[Test]
    public function site_delete_cannot_cascade_legacy_identity_or_broaden_site_portal_membership(): void
    {
        $client = Client::factory()->create();
        $site = ClientSite::factory()->create(['client_id' => $client->id]);
        $legacy = \App\Models\Clients\ClientUser::factory()->create(['client_site_id' => $site->id]);
        $this->deleteJson('/api/v1/client-sites/'.$site->id, [], $this->headers('referenced-site-key', ClientRevision::of($site)))->assertConflict();
        $this->assertDatabaseHas('client_users', ['id' => $legacy->id, 'client_site_id' => $site->id]);
        $portalSite = ClientSite::factory()->create(['client_id' => $client->id, 'name' => 'Portal scoped Site']);
        $contact = \App\Modules\Contact\Models\Contact::create(['type' => 'person', 'status' => 'active', 'display_name' => 'Scoped portal identity']);
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE, 'contact_id' => $contact->id]);
        $account = \App\Modules\CustomerPortal\Models\CustomerPortalAccount::create(['user_id' => $user->id, 'contact_id' => $contact->id, 'status' => 'active']);
        $member = \App\Modules\CustomerPortal\Models\CustomerPortalMembership::create(['customer_portal_account_id' => $account->id,
            'client_id' => $client->id, 'site_id' => $portalSite->id, 'role' => 'viewer', 'status' => 'active']);
        $this->deleteJson('/api/v1/client-sites/'.$portalSite->id, [], $this->headers('portal-site-delete-key', ClientRevision::of($portalSite)))->assertConflict();
        $this->assertSame($portalSite->id, $member->fresh()->site_id);
        $this->assertDatabaseHas('client_sites', ['id' => $portalSite->id]);
        $this->assertDatabaseCount('client_api_commands', 0);
    }

    #[Test]
    public function explicit_null_default_site_name_uses_the_documented_default(): void
    {
        $this->postJson('/api/v1/clients', ['name' => 'Null default Site', 'site_name' => null])
            ->assertCreated()->assertJsonPath('data.sites.0.name', 'Default');
        $this->assertDatabaseCount('clients', 1);
        $this->assertDatabaseCount('client_sites', 1);
    }
}
