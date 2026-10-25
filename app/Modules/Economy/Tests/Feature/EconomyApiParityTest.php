<?php

namespace App\Modules\Economy\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Core\User;
use App\Modules\Commercial\Models\Contracts\ClientContractTimeConsumption;
use App\Modules\Commercial\Models\Contracts\ContractItem;
use App\Modules\Commercial\Models\Contracts\Contracts;
use App\Modules\Commercial\Models\Services\Services;
use App\Modules\Contact\Models\Contact;
use App\Modules\Contact\Models\ContactEmail;
use App\Modules\Contact\Models\ContactRelation;
use App\Modules\CustomerPortal\Models\CustomerPortalAccount;
use App\Modules\CustomerPortal\Models\CustomerPortalMembership;
use App\Modules\DataExchange\Actions\EnsureDataExchangeProfileTemplates;
use App\Modules\Economy\Actions\DeleteEconomyOrder;
use App\Modules\Economy\Actions\DispatchEconomyNotification;
use App\Modules\Economy\Actions\GenerateOrders;
use App\Modules\Economy\Models\EconomyNotificationIntent;
use App\Modules\Economy\Models\EconomyOrder;
use App\Modules\Economy\Models\EconomyOrderLine;
use App\Modules\Integration\Models\CloudFactory\BillingPeriod;
use App\Modules\Notification\Actions\SendCustomerPortalNotification;
use App\Modules\Ticket\Actions\EnsureTicketDefaults;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketStatus;
use App\Modules\Ticket\Models\TicketTimeEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** End-to-end contracts exercise actual authorization, persistence, retries and owner boundaries. */
class EconomyApiParityTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private array $abilities = ['economy.read', 'economy.create', 'economy.update', 'economy.delete', 'economy.settings.read', 'economy.settings.update', 'economy.operations.read', 'economy.export', 'data_exchange.read', 'data_exchange.run', 'data_exchange.download'];

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'Tech']);
        $this->staff = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->staff->assignRole('Tech');
        $this->staff->givePermissionTo(['economy.view', 'economy.order_manage', 'economy.generate_orders', 'economy.delete_orders', 'economy.manage_settings', 'data_exchange.view', 'data_exchange.run', 'data_exchange.download']);
        Notification::fake();
        Storage::fake('local');
        Sanctum::actingAs($this->staff, $this->abilities);
    }

    private function order(array $fields = []): EconomyOrder
    {
        return EconomyOrder::create($fields + ['client_id' => Client::factory()->create()->id, 'order_number' => 'API-'.Str::random(8), 'period_start' => now()->startOfMonth()->toDateString(), 'period_end' => now()->endOfMonth()->toDateString(), 'status' => 'draft']);
    }

    private function line(EconomyOrder $order, array $fields = []): EconomyOrderLine
    {
        return EconomyOrderLine::create($fields + ['economy_order_id' => $order->id, 'client_id' => $order->client_id, 'line_type' => 'manual', 'description' => 'Synthetic financial line', 'quantity' => 1, 'unit' => 'pcs', 'unit_price_ex_vat' => 100, 'line_total_ex_vat' => 100, 'total_inc_vat' => 100, 'currency' => 'NOK', 'status' => 'active']);
    }

    private function revision(EconomyOrder $order): string
    {
        return $this->getJson('/api/v1/economy/orders/'.$order->id)->assertOk()->json('data.revision');
    }

    private function headers(string $key, ?string $revision = null): array
    {
        return ['Idempotency-Key' => $key] + ($revision ? ['If-Match' => $revision] : []);
    }

    #[Test]
    public function staff_reads_are_pure_and_effective_totals_preserve_stored_history(): void
    {
        $order = $this->order();
        $this->line($order, ['metadata' => ['secret_internal_note' => 'not projected']]);
        foreach (['/orders', '/orders/context', '/orders/'.$order->id, '/orders/'.$order->id.'/lines', '/settings', '/orders/generate/preview', '/exports/preview'] as $uri) {
            $this->getJson('/api/v1/economy'.$uri)->assertOk();
        }
        $this->assertDatabaseCount('economy_settings', 0);
        $this->assertDatabaseCount('economy_source_owners', 0);
        $this->assertDatabaseCount('ticket_time_entry_allocations', 0);
        $this->assertDatabaseCount('economy_command_receipts', 0);
        $this->assertDatabaseCount('data_exchange_profiles', 0);
        $this->assertDatabaseCount('data_exchange_runs', 0);
        $this->getJson('/api/v1/economy/orders/'.$order->id)->assertOk()
            ->assertJsonPath('data.effective_totals.total_inc_vat', 125)
            ->assertJsonPath('data.stored_totals.total_inc_vat', '0.00')
            ->assertJsonPath('data.lines.0.decimals.unit_price_ex_vat', '100.0000')
            ->assertJsonMissing(['secret_internal_note' => 'not projected']);
        $this->assertSame('0.00', $order->refresh()->total_inc_vat);
    }

    #[Test]
    public function token_abilities_cannot_replace_current_domain_authority(): void
    {
        $order = $this->order();
        $this->staff->revokePermissionTo('economy.order_manage');
        $this->getJson('/api/v1/economy/orders/'.$order->id)->assertForbidden();
        $this->postJson('/api/v1/economy/orders/'.$order->id.'/ready')->assertForbidden();
        $this->assertSame('draft', $order->refresh()->status);
        $this->staff->givePermissionTo('economy.order_manage');
        Sanctum::actingAs($this->staff, ['economy.read']);
        $this->getJson('/api/v1/economy/orders/'.$order->id)->assertOk();
        $this->postJson('/api/v1/economy/orders/'.$order->id.'/ready')->assertForbidden();
        $this->getJson('/api/v1/economy/settings')->assertForbidden();
    }

    #[Test]
    public function inactive_actor_is_refused_even_with_a_still_authenticated_token(): void
    {
        $order = $this->order();
        $this->staff->update(['status' => 'inactive']);
        $this->getJson('/api/v1/economy/orders/'.$order->id)->assertForbidden();
        $this->postJson('/api/v1/economy/orders/generate')->assertForbidden();
    }

    #[Test]
    public function real_bearer_token_scopes_and_revocation_are_enforced(): void
    {
        auth()->forgetGuards();
        $token = $this->staff->createToken('synthetic-economy', ['economy.read']);
        $headers = ['Authorization' => 'Bearer '.$token->plainTextToken];
        $order = $this->order();
        $this->withHeaders($headers)->getJson('/api/v1/economy/orders/'.$order->id)->assertOk();
        $this->withHeaders($headers)->postJson('/api/v1/economy/orders/'.$order->id.'/ready')->assertForbidden();
        $token->accessToken->delete();
        auth()->forgetGuards();
        $this->withHeaders($headers)->getJson('/api/v1/economy/orders/'.$order->id)->assertUnauthorized();
    }

    #[Test]
    public function unknown_fields_nested_input_sort_paging_and_invalid_dates_are_rejected(): void
    {
        $order = $this->order();
        foreach (['per_page=101', 'page=0', 'sort=raw_sql', 'direction=invalid', 'status=unknown', 'client_id=999999', 'private=1', 'period_start=2026-10-10&period_end=2026-09-01'] as $query) {
            $this->getJson('/api/v1/economy/orders?'.$query)->assertUnprocessable();
        }
        $this->postJson('/api/v1/economy/orders/'.$order->id.'/ready', ['created_by' => 999])->assertUnprocessable();
        $this->postJson('/api/v1/economy/orders/generate', ['source' => ['id' => 4]])->assertUnprocessable();
        $this->postJson('/api/v1/economy/orders/generate', ['period_start' => '2026-11-01', 'period_end' => '2026-10-01'])->assertUnprocessable();
        $this->assertDatabaseCount('economy_command_receipts', 0);
    }

    #[Test]
    public function filters_stats_sort_and_large_order_line_paging_are_complete(): void
    {
        $order = $this->order(['order_number' => 'TARGET-ORDER']);
        $this->order(['status' => 'ready']);
        for ($i = 0; $i < 101; $i++) {
            $this->line($order);
        }
        $this->getJson('/api/v1/economy/orders?q=TARGET&status=draft&sort=order_number&direction=asc&per_page=1')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('meta.stats.draft', 1)->assertJsonPath('meta.stats.ready', 0)
            ->assertJsonPath('data.0.lines_count', 101);
        $this->getJson('/api/v1/economy/orders/'.$order->id)->assertOk()->assertJsonCount(100, 'data.lines')->assertJsonPath('data.lines_truncated', true)
            ->assertJsonPath('data.effective_totals.total_inc_vat', 12625);
        $this->getJson('/api/v1/economy/orders/'.$order->id.'/lines?per_page=100&page=2')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 101);
    }

    #[Test]
    public function all_settings_support_partial_false_zero_default_and_durable_replay(): void
    {
        $revision = $this->getJson('/api/v1/economy/settings')->assertOk()->json('data.revision');
        $input = ['create_orders_from_resolved_ticket_time' => true, 'create_orders_from_closed_ticket_time' => false, 'include_unresolved_ticket_time_in_period_close' => true, 'create_orders_from_picked_ticket_costs' => false, 'auto_pick_ticket_costs_on_resolved_or_closed_ticket' => false, 'time_order_line_grouping' => 'per_entry', 'order_line_text_format' => 'ticket_date_text', 'order_prefix' => 'SAFE-', 'default_vat_rate' => 0];
        $headers = $this->headers('settings-complete-1', $revision);
        $first = $this->patchJson('/api/v1/economy/settings', $input, $headers)->assertOk()->assertJsonPath('data.order_prefix', 'SAFE-')->assertJsonPath('data.default_vat_rate', '0.00');
        $this->patchJson('/api/v1/economy/settings', $input, $headers)->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('operation_id', $first->json('operation_id'));
        $this->patchJson('/api/v1/economy/settings', ['order_prefix' => 'WRONG-'], $headers)->assertConflict();
        $current = $this->getJson('/api/v1/economy/settings')->json('data.revision');
        $this->patchJson('/api/v1/economy/settings', ['default_vat_rate' => null], $this->headers('settings-null-vat', $current))->assertOk()->assertJsonPath('data.order_prefix', 'SAFE-')->assertJsonPath('data.create_orders_from_closed_ticket_time', false)->assertJsonPath('data.default_vat_rate', '25.00');
        $this->assertDatabaseCount('economy_settings', 1);
        $this->assertDatabaseCount('economy_command_receipts', 2);
    }

    #[Test]
    public function settings_require_headers_scope_authority_and_valid_supported_values(): void
    {
        $revision = $this->getJson('/api/v1/economy/settings')->json('data.revision');
        $this->patchJson('/api/v1/economy/settings', ['order_prefix' => 'X'])->assertUnprocessable();
        $this->patchJson('/api/v1/economy/settings', ['order_prefix' => 'X'], $this->headers('settings-no-revision'))->assertStatus(428);
        $this->patchJson('/api/v1/economy/settings', ['default_vat_rate' => 101], $this->headers('settings-invalid-vat', $revision))->assertUnprocessable();
        $this->patchJson('/api/v1/economy/settings', ['time_order_line_grouping' => 'custom'], $this->headers('settings-invalid-mode', $revision))->assertUnprocessable();
        $this->staff->revokePermissionTo('economy.manage_settings');
        $this->getJson('/api/v1/economy/settings')->assertForbidden();
        $this->patchJson('/api/v1/economy/settings', [], $this->headers('settings-no-authority', $revision))->assertForbidden();
    }

    #[Test]
    public function state_transitions_replay_and_manual_invoicing_preserve_external_state(): void
    {
        $order = $this->order();
        $headers = $this->headers('order-ready-key-1', $this->revision($order));
        $first = $this->postJson('/api/v1/economy/orders/'.$order->id.'/ready', [], $headers)->assertOk()->assertJsonPath('data.status', 'ready')->assertJsonPath('data.updated_by', $this->staff->id);
        $this->postJson('/api/v1/economy/orders/'.$order->id.'/ready', [], $headers)->assertOk()->assertJsonPath('replayed', true);
        $this->postJson('/api/v1/economy/orders/'.$order->id.'/draft', [], $this->headers('order-stale-draft', trim($headers['If-Match'])))->assertConflict();
        $this->postJson('/api/v1/economy/orders/'.$order->id.'/invoiced', [], $this->headers('manual-invoice-key', $this->revision($order)))->assertOk()->assertJsonPath('data.status', 'manual_invoiced')->assertJsonPath('data.exported_at', null);
        $this->postJson('/api/v1/economy/orders/'.$order->id.'/draft')->assertUnprocessable();
        $this->assertDatabaseCount('data_exchange_runs', 0);
        $this->staff->revokePermissionTo('economy.order_manage');
        $this->postJson('/api/v1/economy/orders/'.$order->id.'/ready', [], $headers)->assertForbidden();
        $this->assertSame('manual_invoiced', $order->refresh()->status);
    }

    #[Test]
    public function same_second_external_line_edit_invalidates_order_revision(): void
    {
        $order = $this->order();
        $line = $this->line($order);
        $revision = $this->revision($order);
        $line->update(['quantity' => 2]);
        $this->postJson('/api/v1/economy/orders/'.$order->id.'/ready', [], $this->headers('changed-line-key', $revision))->assertConflict();
        $this->assertSame('draft', $order->refresh()->status);
    }

    #[Test]
    public function publish_hide_and_notification_intents_are_deduplicated_and_audited(): void
    {
        $order = $this->order();
        $preview = $this->postJson('/api/v1/economy/orders/'.$order->id.'/portal-visibility/preview', ['portal_visible' => true])->assertOk()->json('data');
        $input = ['portal_visible' => true, 'preview_hash' => $preview['preview_hash']];
        $headers = $this->headers('visibility-publish-key', $preview['revision']);
        $first = $this->postJson('/api/v1/economy/orders/'.$order->id.'/portal-visibility', $input, $headers)->assertOk()->assertJsonPath('data.portal_visible', true);
        $this->postJson('/api/v1/economy/orders/'.$order->id.'/portal-visibility', $input, $headers)->assertOk()->assertJsonPath('replayed', true);
        $this->assertDatabaseCount('economy_notification_intents', 1);
        $this->assertDatabaseHas('customer_portal_audit_events', ['event' => 'portal_economy_order_visibility_enabled', 'user_id' => $this->staff->id]);
        $ready = $this->postJson('/api/v1/economy/orders/'.$order->id.'/ready', [], $this->headers('published-ready-key', $this->revision($order)))->assertOk();
        $this->assertDatabaseCount('economy_notification_intents', 2);
        $preview = $this->postJson('/api/v1/economy/orders/'.$order->id.'/portal-visibility/preview', ['portal_visible' => false])->json('data');
        $this->postJson('/api/v1/economy/orders/'.$order->id.'/portal-visibility', ['portal_visible' => false, 'preview_hash' => $preview['preview_hash']], $this->headers('visibility-hide-key', $preview['revision']))->assertOk()->assertJsonPath('data.portal_visible', false);
        $this->assertDatabaseCount('economy_notification_intents', 2);
        $this->getJson('/api/v1/economy/operations/'.$ready->json('operation_id'))->assertOk()->assertJsonPath('data.inbox_delivery_verified', false)->assertJsonCount(1, 'data.notification_status');
    }

    #[Test]
    public function notification_dispatch_is_after_commit_claimed_once_and_unknown_is_not_retried(): void
    {
        $order = $this->order(['portal_visible_at' => now()]);
        $intent = EconomyNotificationIntent::create(['id' => (string) Str::uuid(), 'order_id' => $order->id, 'state' => 'pending', 'payload' => ['actor_id' => $this->staff->id, 'client_id' => $order->client_id, 'status' => 'ready', 'type' => 'portal_order_status_changed', 'title' => 'Synthetic', 'body' => 'Ready']]);
        $mock = $this->mock(SendCustomerPortalNotification::class);
        $mock->shouldReceive('handle')->once()->andThrow(new \RuntimeException('synthetic ambiguous notification'));
        app(DispatchEconomyNotification::class)->handle($intent->id);
        $this->assertSame('unknown', $intent->refresh()->state);
        app(DispatchEconomyNotification::class)->handle($intent->id);
        $this->assertSame('notification_submission_uncertain', $intent->refresh()->error_code);
    }

    #[Test]
    public function deletion_requires_correct_parent_and_replays_after_the_parent_is_gone(): void
    {
        $order = $this->order();
        $line = $this->line($order);
        $foreign = $this->order();
        $this->deleteJson('/api/v1/economy/orders/'.$foreign->id.'/lines/'.$line->id)->assertNotFound();
        $this->deleteJson('/api/v1/economy/orders/'.$order->id)->assertUnprocessable();
        $headers = $this->headers('delete-last-line-key', $this->revision($order));
        $uri = '/api/v1/economy/orders/'.$order->id.'/lines/'.$line->id;
        $result = $this->deleteJson($uri, [], $headers)->assertOk()->assertJsonPath('data.deleted_order', true);
        $this->deleteJson($uri, [], $headers)->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('operation_id', $result->json('operation_id'));
        $this->getJson('/api/v1/economy/operations/'.$result->json('operation_id'))->assertOk()->assertJsonPath('data.result.data.deleted_order', true);
        $this->assertDatabaseMissing('economy_orders', ['id' => $order->id]);
        $this->assertDatabaseMissing('economy_order_lines', ['id' => $line->id]);
    }

    #[Test]
    public function non_draft_lines_and_locked_orders_cannot_be_deleted_or_reverted(): void
    {
        foreach (['ready', 'approved', 'exported', 'manual_invoiced', 'cancelled'] as $state) {
            $order = $this->order(['status' => $state]);
            $line = $this->line($order);
            $this->deleteJson('/api/v1/economy/orders/'.$order->id.'/lines/'.$line->id)->assertUnprocessable();
            $this->deleteJson('/api/v1/economy/orders/'.$order->id)->assertUnprocessable();
            if ($state !== 'ready') {
                $this->postJson('/api/v1/economy/orders/'.$order->id.'/draft')->assertUnprocessable();
            }
        }
    }

    private function timeSource(): TicketTimeEntry
    {
        $defaults = app(EnsureTicketDefaults::class)->handle();
        $client = Client::factory()->create();
        $ticket = Ticket::create(['ticket_key' => 'TD-API-'.Str::random(6), 'subject' => 'Synthetic billing', 'description' => 'Synthetic', 'client_id' => $client->id, 'queue_id' => $defaults['queue']->id, 'status_id' => TicketStatus::where('slug', 'closed')->firstOrFail()->id, 'priority_id' => $defaults['priority']->id, 'channel' => 'manual']);

        return TicketTimeEntry::create(['ticket_id' => $ticket->id, 'user_id' => $this->staff->id, 'work_date' => now()->toDateString(), 'minutes' => 60, 'billable' => true, 'billing_status' => 'pending', 'timebank_status' => 'pending', 'billing_basis' => 'without_contract', 'invoice_text' => 'Synthetic', 'rate_name' => 'Synthetic', 'rate_amount_ex_vat' => 1000, 'rate_currency' => 'NOK']);
    }

    #[Test]
    public function generation_preview_is_pure_stale_preview_is_refused_and_receipt_is_exact(): void
    {
        $entry = $this->timeSource();
        $preview = $this->getJson('/api/v1/economy/orders/generate/preview')->assertOk()->assertJsonPath('data.candidate_counts.ticket_time', 1)->json('data');
        $this->assertDatabaseCount('economy_settings', 0);
        $entry->update(['minutes' => 30]);
        $this->postJson('/api/v1/economy/orders/generate', ['preview_hash' => $preview['preview_hash']], $this->headers('generate-stale-key'))->assertConflict();
        $preview = $this->getJson('/api/v1/economy/orders/generate/preview')->json('data');
        $input = ['preview_hash' => $preview['preview_hash']];
        $headers = $this->headers('generate-preview-key');
        $first = $this->postJson('/api/v1/economy/orders/generate', $input, $headers)->assertCreated()->assertJsonPath('data.summary.lines_created', 1)->assertJsonCount(1, 'data.summary.created_line_ids');
        $this->postJson('/api/v1/economy/orders/generate', $input, $headers)->assertCreated()->assertJsonPath('replayed', true)->assertJsonPath('data.summary', $first->json('data.summary'));
        $this->postJson('/api/v1/economy/orders/generate')->assertCreated()->assertJsonPath('data.summary.lines_created', 0)->assertJsonPath('data.summary.orders_touched', 0);
        $this->assertDatabaseCount('economy_order_lines', 1);
        $this->assertSame('queued', $entry->refresh()->billing_status);
    }

    #[Test]
    public function moved_ticket_line_correction_retains_the_recorded_financial_customer(): void
    {
        $entry = $this->timeSource();
        app(GenerateOrders::class)->handle(actor: $this->staff);
        $line = EconomyOrderLine::firstOrFail();
        $originalClient = $line->client_id;
        $entry->ticket->update(['client_id' => Client::factory()->create()->id]);
        app(DeleteEconomyOrder::class)->handle($line->economy_order_id, $line->id);
        $this->assertDatabaseHas('ticket_time_entry_allocations', ['ticket_time_entry_id' => $entry->id, 'client_id' => $originalClient]);
        $this->assertDatabaseHas('economy_source_owners', ['source_id' => $entry->id, 'source_type' => $entry->getMorphClass(), 'client_id' => $originalClient]);
        app(GenerateOrders::class)->handle(actor: $this->staff);
        $this->assertSame($originalClient, EconomyOrderLine::firstOrFail()->client_id);
        $this->assertDatabaseCount('economy_order_lines', 1);
    }

    #[Test]
    public function operation_receipts_are_actor_scoped_and_current_rights_are_rechecked(): void
    {
        $order = $this->order();
        $result = $this->postJson('/api/v1/economy/orders/'.$order->id.'/ready', [], $this->headers('own-operation-key'))->assertOk();
        $other = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $other->givePermissionTo('economy.order_manage');
        Sanctum::actingAs($other, ['economy.operations.read']);
        $this->getJson('/api/v1/economy/operations/'.$result->json('operation_id'))->assertNotFound();
        Sanctum::actingAs($this->staff, ['economy.operations.read']);
        $this->staff->revokePermissionTo('economy.order_manage');
        $this->getJson('/api/v1/economy/operations/'.$result->json('operation_id'))->assertForbidden();
    }

    #[Test]
    public function economy_export_preserves_profile_and_returns_owner_checksum_without_invoice_state(): void
    {
        $order = $this->order(['status' => 'ready']);
        $this->line($order);
        $profile = app(EnsureDataExchangeProfileTemplates::class)->economyOrdersExportProfile($this->staff);
        $profile->update(['format' => 'json']);
        $preview = $this->getJson('/api/v1/economy/exports/preview')->assertOk()->assertJsonPath('data.format', 'json')->assertJsonPath('data.row_count', 1)->json('data');
        $headers = $this->headers('export-exact-file-key');
        $input = ['preview_hash' => $preview['preview_hash']];
        $result = $this->postJson('/api/v1/economy/exports', $input, $headers)->assertCreated()->assertJsonPath('data.status', 'succeeded')->assertJsonPath('data.provider_invoice_created', false)->assertJsonPath('data.delivery_dispatched', false);
        $this->postJson('/api/v1/economy/exports', $input, $headers)->assertCreated()->assertJsonPath('replayed', true);
        $this->assertDatabaseCount('data_exchange_runs', 1);
        $this->assertDatabaseCount('data_exchange_files', 1);
        $file = \App\Modules\DataExchange\Models\DataExchangeFile::firstOrFail();
        $this->assertSame(hash('sha256', Storage::disk($file->disk)->get($file->path)), $result->json('data.files.0.checksum'));
        $this->getJson('/api/v1/economy/exports/'.$result->json('data.id'))->assertOk()->assertJsonPath('data.files.0.id', $file->id);
        $this->get('/api/v1/data-exchange/files/'.$file->id.'/download')->assertOk();
        $this->assertSame('ready', $order->refresh()->status);
        $this->assertNull($order->exported_at);
        $this->assertSame('json', $profile->refresh()->format);
    }

    #[Test]
    public function export_requires_preview_and_owner_permissions_on_both_api_paths(): void
    {
        $profile = app(EnsureDataExchangeProfileTemplates::class)->economyOrdersExportProfile($this->staff);
        $this->postJson('/api/v1/economy/exports', ['preview_hash' => str_repeat('0', 64)], $this->headers('export-stale-key'))->assertConflict();
        $this->staff->revokePermissionTo('economy.view');
        $this->getJson('/api/v1/economy/exports/preview')->assertForbidden();
        $this->postJson('/api/v1/data-exchange/profiles/'.$profile->id.'/runs')->assertForbidden();
        $this->getJson('/api/v1/data-exchange/profiles')->assertOk()->assertJsonMissing(['key' => 'economy_orders_export']);
        $this->assertDatabaseCount('data_exchange_runs', 0);
    }

    private function portal(Client $client, bool $site = false): User
    {
        $contact = Contact::create(['type' => 'person', 'status' => 'active', 'display_name' => 'Synthetic Portal']);
        $email = Str::random(10).'@example.test';
        ContactEmail::create(['contact_id' => $contact->id, 'label' => 'work', 'email' => $email, 'is_primary' => true, 'is_verified' => true]);
        ContactRelation::create(['contact_id' => $contact->id, 'related_type' => $client->getMorphClass(), 'related_id' => $client->id, 'relation_type' => 'contact', 'is_primary' => true]);
        $user = User::factory()->create(['contact_id' => $contact->id, 'email' => $email, 'status' => User::STATUS_ACTIVE]);
        $account = CustomerPortalAccount::create(['user_id' => $user->id, 'contact_id' => $contact->id, 'status' => CustomerPortalAccount::STATUS_ACTIVE]);
        $siteRecord = $site ? ClientSite::factory()->create(['client_id' => $client->id]) : null;
        if ($siteRecord) {
            ContactRelation::create(['contact_id' => $contact->id, 'related_type' => $siteRecord->getMorphClass(), 'related_id' => $siteRecord->id, 'relation_type' => 'contact']);
        }
        CustomerPortalMembership::create(['customer_portal_account_id' => $account->id, 'client_id' => $client->id, 'site_id' => $siteRecord?->id, 'role' => CustomerPortalMembership::ROLE_VIEWER, 'status' => CustomerPortalMembership::STATUS_ACTIVE]);

        return $user;
    }

    #[Test]
    public function portal_reads_require_current_client_publication_and_safe_projection(): void
    {
        $visible = $this->order(['portal_visible_at' => now()]);
        $hidden = $this->order(['client_id' => $visible->client_id]);
        $foreign = $this->order(['portal_visible_at' => now()]);
        $this->line($visible, ['source_type' => 'private_source', 'source_id' => 123, 'metadata' => ['internal' => 'private']]);
        $user = $this->portal($visible->client);
        $this->actingAs($user, 'web')->getJson('/api/v1/economy/portal/orders')->assertOk()->assertJsonCount(1, 'data');
        $data = $this->actingAs($user, 'web')->getJson('/api/v1/economy/portal/orders/'.$visible->id)->assertOk()->json('data');
        foreach (['updated_by', 'created_by', 'revision', 'available_actions', 'stored_totals', 'portal_visible_by'] as $field) {
            $this->assertArrayNotHasKey($field, $data);
        }
        foreach (['source_type', 'source_id', 'metadata', 'ticket_id', 'decimals'] as $field) {
            $this->assertArrayNotHasKey($field, $data['lines'][0]);
        }
        $this->assertSame(125.0, (float) $data['total_inc_vat']);
        $this->getJson('/api/v1/economy/portal/orders/'.$hidden->id)->assertNotFound();
        $this->getJson('/api/v1/economy/portal/orders/'.$foreign->id)->assertNotFound();
        $this->getJson('/api/v1/economy/portal/orders/'.$visible->id.'/lines')->assertOk()->assertJsonCount(1, 'data');
        $this->assertDatabaseCount('economy_settings', 0);
    }

    #[Test]
    public function site_portal_and_revoked_membership_cannot_obtain_client_level_order_data(): void
    {
        $order = $this->order(['portal_visible_at' => now()]);
        $user = $this->portal($order->client, true);
        $this->actingAs($user, 'web')->getJson('/api/v1/economy/portal/orders')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/economy/portal/orders/'.$order->id)->assertNotFound();
        $this->getJson('/api/v1/economy/portal/orders/context')->assertOk()->assertJsonPath('data.client_level_orders_available', false);
        CustomerPortalMembership::query()->update(['status' => 'inactive']);
        $this->getJson('/api/v1/economy/portal/orders')->assertForbidden();
    }

    #[Test]
    public function quick_overuse_retry_and_line_correction_do_not_create_empty_drafts_or_double_charge(): void
    {
        $client = Client::factory()->create();
        $service = Services::create(['name' => 'Synthetic bank', 'sku' => 'API-BANK', 'status' => 'active', 'unitId' => 1, 'taxable' => 25, 'timebank_enabled' => true, 'timebank_minutes' => 60, 'timebank_interval' => 'monthly', 'created_by_user_id' => $this->staff->id]);
        $contract = Contracts::create(['client_id' => $client->id, 'created_by' => $this->staff->id, 'description' => 'Synthetic', 'approval_status' => 'won', 'start_date' => now()->startOfMonth(), 'end_date' => now()->endOfYear()]);
        $item = ContractItem::create(['contract_id' => $contract->id, 'service_id' => $service->id, 'name' => 'Synthetic bank', 'sku' => 'API-BANK', 'unit_price' => 0, 'quantity' => 1, 'unit' => 'month', 'billing_interval' => 'monthly']);
        $source = ClientContractTimeConsumption::create(['client_id' => $client->id, 'contract_id' => $contract->id, 'contract_item_id' => $item->id, 'user_id' => $this->staff->id, 'work_date' => now()->toDateString(), 'minutes' => 30, 'source' => 'quick_client', 'rate_name' => 'Synthetic overuse', 'rate_amount_ex_vat' => 600, 'rate_currency' => 'NOK', 'period_start' => now()->startOfMonth(), 'period_end' => now()->endOfMonth(), 'included_minutes_snapshot' => 60, 'used_before_minutes_snapshot' => 60, 'overused_minutes' => 30]);
        $this->postJson('/api/v1/economy/orders/generate')->assertCreated()->assertJsonPath('data.summary.lines_created', 1)->assertJsonPath('data.summary.quick_timebank_entries_ordered', 1);
        $line = EconomyOrderLine::firstOrFail();
        $this->postJson('/api/v1/economy/orders/generate')->assertCreated()->assertJsonPath('data.summary.lines_created', 0)->assertJsonPath('data.summary.quick_timebank_entries_ordered', 0)->assertJsonPath('data.summary.sources_reused', 1);
        $this->assertDatabaseCount('economy_orders', 1);
        $this->deleteJson('/api/v1/economy/orders/'.$line->economy_order_id.'/lines/'.$line->id)->assertOk()->assertJsonPath('data.deleted_order', true);
        $this->postJson('/api/v1/economy/orders/generate')->assertCreated()->assertJsonPath('data.summary.lines_created', 1);
        $order = EconomyOrder::firstOrFail();
        $this->postJson('/api/v1/economy/orders/'.$order->id.'/ready')->assertOk();
        $this->postJson('/api/v1/economy/orders/generate')->assertCreated()->assertJsonPath('data.summary.lines_created', 0)->assertJsonPath('data.summary.orders_touched', 0);
        $this->assertDatabaseCount('economy_orders', 1);
        $this->assertDatabaseCount('economy_order_lines', 1);
        $this->assertSame($source->id, EconomyOrderLine::firstOrFail()->source_id);
        $this->assertDatabaseCount('storage_movements', 0);
    }

    #[Test]
    public function confirmed_provider_period_deletion_and_regeneration_preserve_source_and_locked_history(): void
    {
        $client = Client::factory()->create();
        $integration = app(\App\Modules\Integration\Services\CloudFactory\CloudFactoryIntegration::class)->getOrCreate();
        $subscription = \App\Modules\Integration\Models\CloudFactory\Subscription::create(['integration_id' => $integration->id, 'client_id' => $client->id, 'provider_family' => 'synthetic', 'external_subscription_id' => (string) Str::uuid(), 'name' => 'Synthetic licence', 'quantity' => 2, 'status' => 'active', 'billing_state' => 'confirmed', 'unit_sale_price' => 123.4567, 'currency' => 'NOK']);
        $period = BillingPeriod::create(['subscription_id' => $subscription->id, 'client_id' => $client->id, 'period_start' => now()->startOfMonth(), 'period_end' => now()->endOfMonth(), 'quantity' => 2, 'unit_price_ex_vat' => 123.4567, 'currency' => 'NOK', 'status' => 'confirmed']);
        $this->postJson('/api/v1/economy/orders/generate')->assertCreated()->assertJsonPath('data.summary.cloudfactory_periods_ordered', 1);
        $line = EconomyOrderLine::firstOrFail();
        $this->assertSame('123.4567', $line->unit_price_ex_vat);
        $this->assertSame('queued', $period->refresh()->status);
        $this->deleteJson('/api/v1/economy/orders/'.$line->economy_order_id.'/lines/'.$line->id)->assertOk();
        $this->assertSame('confirmed', $period->refresh()->status);
        $this->postJson('/api/v1/economy/orders/generate')->assertCreated()->assertJsonPath('data.summary.lines_created', 1);
        $order = EconomyOrder::firstOrFail();
        $this->postJson('/api/v1/economy/orders/'.$order->id.'/ready')->assertOk();
        $period->update(['status' => 'confirmed']);
        $this->postJson('/api/v1/economy/orders/generate')->assertCreated()->assertJsonPath('data.summary.lines_created', 0);
        $this->assertDatabaseCount('economy_orders', 1);
        $this->assertDatabaseCount('economy_order_lines', 1);
        $this->assertSame('ready', $order->refresh()->status);
        $this->assertDatabaseCount('cloudfactory_operations', 0);
    }

    #[Test]
    public function no_billing_ticket_outcomes_stay_out_of_generation_and_preview(): void
    {
        foreach (['customer_declined', 'cancelled', 'no_sale'] as $outcome) {
            $source = $this->timeSource();
            $source->ticket->update(['close_outcome' => $outcome]);
        }
        $this->getJson('/api/v1/economy/orders/generate/preview')->assertOk()->assertJsonPath('data.candidate_counts.ticket_time', 0);
        $this->postJson('/api/v1/economy/orders/generate')->assertCreated()->assertJsonPath('data.summary.lines_created', 0);
        $this->assertDatabaseCount('ticket_time_entry_allocations', 0);
        $this->assertDatabaseCount('economy_orders', 0);
    }

    #[Test]
    public function export_failure_is_a_durable_failed_owner_result_and_does_not_retry_the_file_write(): void
    {
        $order = $this->order(['status' => 'ready']);
        $this->line($order);
        app(EnsureDataExchangeProfileTemplates::class)->economyOrdersExportProfile($this->staff);
        $preview = $this->getJson('/api/v1/economy/exports/preview')->assertOk()->json('data.preview_hash');
        $disk = Storage::disk('local');
        $disk->getAdapter();
        Storage::shouldReceive('disk')->with('local')->once()->andThrow(new \RuntimeException('synthetic storage unavailable'));
        $headers = $this->headers('export-failed-once');
        $input = ['preview_hash' => $preview];
        $result = $this->postJson('/api/v1/economy/exports', $input, $headers)->assertCreated()->assertJsonPath('data.status', 'failed')->assertJsonPath('data.error_code', 'export_failed')->assertJsonCount(0, 'data.files');
        $this->postJson('/api/v1/economy/exports', $input, $headers)->assertCreated()->assertJsonPath('replayed', true)->assertJsonPath('data.status', 'failed');
        $this->assertDatabaseCount('data_exchange_runs', 1);
        $this->assertDatabaseCount('data_exchange_files', 0);
        $this->assertSame('ready', $order->refresh()->status);
    }

    #[Test]
    public function owner_export_download_and_run_cannot_bypass_revoked_economy_rights(): void
    {
        $order = $this->order(['status' => 'ready']);
        $this->line($order);
        $profile = app(EnsureDataExchangeProfileTemplates::class)->economyOrdersExportProfile($this->staff);
        $preview = $this->getJson('/api/v1/economy/exports/preview')->json('data.preview_hash');
        $input = ['preview_hash' => $preview];
        $headers = $this->headers('canonical-owner-export-key');
        $first = $this->postJson('/api/v1/data-exchange/profiles/'.$profile->id.'/runs', $input, $headers)->assertCreated();
        $this->postJson('/api/v1/data-exchange/profiles/'.$profile->id.'/runs', $input, $headers)->assertCreated()->assertJsonPath('replayed', true);
        $file = \App\Modules\DataExchange\Models\DataExchangeFile::firstOrFail();
        $this->staff->revokePermissionTo('economy.view');
        $this->getJson('/api/v1/data-exchange/runs/'.$first->json('data.id'))->assertForbidden();
        $this->getJson('/api/v1/data-exchange/files/'.$file->id.'/download')->assertForbidden();
        $this->assertNull($file->refresh()->downloaded_at);
        $this->assertDatabaseCount('data_exchange_runs', 1);
    }

    #[Test]
    public function staff_bearer_token_cannot_establish_a_portal_session(): void
    {
        auth()->forgetGuards();
        config(['auth.defaults.guard' => 'web']);
        $token = $this->staff->createToken('synthetic-no-portal', ['*']);
        $this->withHeaders(['Authorization' => 'Bearer '.$token->plainTextToken])->getJson('/api/v1/economy/portal/orders')->assertUnauthorized();
        $token->accessToken->delete();
    }

    #[Test]
    public function every_economy_route_has_the_exact_typed_openapi_contract(): void
    {
        $contract = json_decode(file_get_contents(app_path('Modules/Economy/Docs/api-contract.json')), true, flags: JSON_THROW_ON_ERROR);
        $actual = [];
        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/v1/economy/')) {
                foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                    $actual[] = $method.' /'.$route->uri();
                }
            }
        }
        $expected = array_map(fn ($op) => $op['method'].' '.$op['path'], $contract['operations']);
        sort($actual);
        sort($expected);
        $this->assertSame($expected, $actual);
        $swagger = json_decode(file_get_contents(storage_path('api-docs/api-docs.json')), true, flags: JSON_THROW_ON_ERROR);
        foreach ($contract['operations'] as $operation) {
            $doc = $swagger['paths'][$operation['path']][strtolower($operation['method'])] ?? null;
            $this->assertIsArray($doc, $operation['path']);
            $this->assertSame($operation['operation_id'], $doc['operationId']);
            $this->assertArrayHasKey('403', $doc['responses']);
            $this->assertArrayHasKey('422', $doc['responses']);
            if ($operation['request_schema']) {
                $this->assertSame('#/components/schemas/'.$operation['request_schema'], $doc['requestBody']['content']['application/json']['schema']['$ref']);
            }
        }
        foreach ($contract['schemas'] as $name => $schema) {
            $this->assertArrayHasKey($name, $swagger['components']['schemas']);
            $this->assertFalse($swagger['components']['schemas'][$name]['additionalProperties']);
        }
    }

    #[Test]
    public function supplied_generation_revision_and_unknown_setting_fields_are_enforced(): void
    {
        $this->postJson('/api/v1/economy/orders/generate', [], $this->headers('generation-invalid-ifmatch', str_repeat('0', 64)))->assertConflict();
        $revision = $this->getJson('/api/v1/economy/settings')->json('data.revision');
        $this->patchJson('/api/v1/economy/settings', ['order_prefix' => ['unsupported' => 'nested']], $this->headers('settings-nested-input', $revision))->assertUnprocessable();
        $this->patchJson('/api/v1/economy/settings', ['created_by' => 9], $this->headers('settings-forged-actor', $revision))->assertUnprocessable();
        $this->assertDatabaseCount('economy_command_receipts', 0);
    }

    #[Test]
    public function custom_economy_profiles_keep_the_supported_data_exchange_owner_workflow(): void
    {
        $order = $this->order(['status' => 'ready']);
        $this->line($order);
        $canonical = app(EnsureDataExchangeProfileTemplates::class)->economyOrdersExportProfile($this->staff);
        $custom = $canonical->replicate();
        $custom->key = 'custom_economy_export';
        $custom->name = 'Synthetic custom Economy export';
        $custom->save();
        foreach (['sources', 'fields', 'filters'] as $relation) {
            foreach ($canonical->{$relation} as $record) {
                $copy = $record->replicate();
                $copy->profile_id = $custom->id;
                $copy->save();
            }
        }
        $previewHash = $this->getJson('/api/v1/data-exchange/profiles/'.$custom->id.'/runs/preview')->assertOk()->json('data.preview_hash');
        $this->postJson('/api/v1/data-exchange/profiles/'.$custom->id.'/runs', ['preview_hash' => $previewHash], ['Idempotency-Key' => 'custom-economy-export-001'])->assertCreated()->assertJsonPath('data.status', 'succeeded');
        $this->assertDatabaseCount('data_exchange_runs', 1);
        $this->assertDatabaseCount('data_exchange_files', 1);
        $this->assertSame('ready', $order->refresh()->status);
        $this->staff->revokePermissionTo('economy.view');
        $this->postJson('/api/v1/data-exchange/profiles/'.$custom->id.'/runs')->assertForbidden();
        $this->assertDatabaseCount('data_exchange_runs', 1);
    }

    #[Test]
    public function annual_timebank_crosses_the_contract_anniversary_without_resetting_each_order_month(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-25 12:00:00'));
        $source = $this->timeSource();
        $clientId = $source->ticket->client_id;
        $service = Services::create(['name' => 'Annual synthetic bank', 'sku' => 'API-YEAR', 'status' => 'active', 'unitId' => 1, 'taxable' => 25, 'timebank_enabled' => true, 'timebank_minutes' => 60, 'timebank_interval' => 'yearly', 'created_by_user_id' => $this->staff->id]);
        $contract = Contracts::create(['client_id' => $clientId, 'created_by' => $this->staff->id, 'description' => 'Annual synthetic', 'approval_status' => 'won', 'start_date' => '2025-06-15', 'end_date' => '2028-06-14']);
        $item = ContractItem::create(['contract_id' => $contract->id, 'service_id' => $service->id, 'name' => 'Annual synthetic bank', 'unit_price' => 0, 'quantity' => 1, 'unit' => 'year', 'billing_interval' => 'yearly']);
        $source->update(['work_date' => '2026-06-14', 'minutes' => 60, 'billing_basis' => 'contract', 'contract_id' => $contract->id, 'contract_item_id' => $item->id, 'rate_amount_ex_vat' => 600]);
        $next = $source->replicate();
        $next->work_date = '2026-06-15';
        $next->save();
        $later = $source->replicate();
        $later->work_date = '2026-10-25';
        $later->minutes = 30;
        $later->save();
        $this->postJson('/api/v1/economy/orders/generate', ['period_start' => '2026-06-01', 'period_end' => '2026-10-31'])->assertCreated()->assertJsonPath('data.summary.lines_created', 1);
        foreach ([[$source->id, '2025-06-15', '2026-06-14', 60, 0], [$next->id, '2026-06-15', '2027-06-14', 60, 0], [$later->id, '2026-06-15', '2027-06-14', 0, 30]] as [$id, $start, $end, $covered, $billable]) {
            $allocation = \App\Modules\Ticket\Models\TicketTimeEntryAllocation::query()->where('ticket_time_entry_id', $id)->firstOrFail();
            $this->assertSame($start, $allocation->period_start->toDateString());
            $this->assertSame($end, $allocation->period_end->toDateString());
            $this->assertSame($covered, (int) $allocation->covered_minutes);
            $this->assertSame($billable, (int) $allocation->billable_minutes);
        }
        $this->assertDatabaseHas('economy_order_lines', ['source_id' => $later->id, 'source_type' => $later->getMorphClass(), 'quantity' => 30, 'line_total_ex_vat' => 300]);
        $this->assertDatabaseCount('economy_order_lines', 1);
    }

    #[Test]
    public function malformed_or_overflowing_route_ids_return_not_found_instead_of_dispatch_errors(): void
    {
        $order = $this->order();
        foreach (['not-an-id', '0', '-1', '999999999999999999999999999999999'] as $id) {
            $this->getJson('/api/v1/economy/orders/'.$id)->assertNotFound();
        }
        $this->deleteJson('/api/v1/economy/orders/'.$order->id.'/lines/not-a-line')->assertNotFound();
        $this->getJson('/api/v1/economy/exports/not-a-run')->assertNotFound();
        $this->getJson('/api/v1/economy/operations/not-an-operation')->assertNotFound();
        $user = $this->portal($order->client);
        $this->actingAs($user, 'web')->getJson('/api/v1/economy/portal/orders/not-an-order')->assertNotFound();
        $this->assertSame('draft', $order->refresh()->status);
    }
}
