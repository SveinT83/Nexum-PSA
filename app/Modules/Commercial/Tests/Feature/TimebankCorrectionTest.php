<?php

namespace App\Modules\Commercial\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Core\User;
use App\Modules\Clients\Queries\ClientTimeReportQuery;
use App\Modules\Commercial\Actions\CalculateClientTimebankBalances;
use App\Modules\Commercial\Models\Contracts\ClientContractTimeConsumption;
use App\Modules\Commercial\Models\Contracts\ContractItem;
use App\Modules\Commercial\Models\Contracts\Contracts;
use App\Modules\Commercial\Models\Economy\Units;
use App\Modules\Commercial\Models\Services\Services;
use App\Modules\Economy\Models\EconomyOrder;
use App\Modules\Economy\Models\EconomyOrderLine;
use App\Modules\Economy\Support\EconomyMutex;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketTimeEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Synthetic customer corrections; work records and financial evidence must survive. */
class TimebankCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Client $client;

    private ContractItem $item;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->travelTo(now()->setDate(2026, 11, 9)->startOfDay());
        config(['commercial.timebank_corrections_enabled' => true]);
        $this->actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->actor->givePermissionTo(['client.view', 'commercial.timebank.view',
            'commercial.timebank.adjust', 'commercial.timebank.reverse', 'ticket.view', 'task.view', 'economy.order_manage']);
        Sanctum::actingAs($this->actor, ['commercial.timebank.read', 'commercial.timebank.adjust', 'commercial.timebank.reverse', 'economy.update']);
        $this->client = Client::factory()->create();
        $unit = Units::create(['name' => 'Hour', 'short' => 'h']);
        $service = Services::create(['sku' => 'CORRECTION-TEST', 'name' => 'Support bank', 'unitId' => $unit->id,
            'status' => 'Active', 'billing_cycle' => 'monthly', 'price_ex_vat' => 100, 'price_currency' => 'NOK',
            'timebank_enabled' => true, 'timebank_minutes' => 600, 'timebank_interval' => 'yearly',
            'created_by_user_id' => $this->actor->id, 'updated_by_user_id' => $this->actor->id]);
        $contract = Contracts::create(['client_id' => $this->client->id, 'description' => 'Test bank',
            'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'approval_status' => 'won', 'created_by' => $this->actor->id]);
        $this->item = ContractItem::create(['contract_id' => $contract->id, 'service_id' => $service->id,
            'name' => 'Annual support', 'quantity' => 1, 'unit_price' => 100, 'price_currency' => 'NOK',
            'billing_interval' => 'monthly', 'unit' => 'h']);
    }

    private function url(string $suffix): string
    {
        return '/api/v1/commercial/clients/'.$this->client->id.'/timebank/'.$suffix;
    }

    private function quick(int $minutes = 480): ClientContractTimeConsumption
    {
        return ClientContractTimeConsumption::create(['client_id' => $this->client->id,
            'contract_id' => $this->item->contract_id, 'contract_item_id' => $this->item->id,
            'user_id' => $this->actor->id, 'work_date' => '2026-11-01', 'minutes' => $minutes,
            'source' => 'quick_client', 'note' => 'Original employee work',
            'period_start' => '2026-01-01', 'period_end' => '2026-12-31',
            'included_minutes_snapshot' => 600, 'used_before_minutes_snapshot' => 0,
            'overused_minutes' => max(0, $minutes - 600)]);
    }

    private function adjustment(string $mode = 'restore', ?int $target = null): array
    {
        return ['contract_item_id' => $this->item->id, 'effective_date' => '2026-11-09',
            'mode' => $mode, 'target_minutes' => $target, 'reason' => 'Agreed customer correction'];
    }

    private function confirm(string $kind, array $input, ?array $preview = null, string $key = 'correction-command-001')
    {
        $preview ??= $this->postJson($this->url($kind.'-preview'), $input)->assertOk()->json('data');

        return $this->postJson($this->url($kind === 'adjustment' ? 'adjustments' : 'reversals'),
            $input + ['preview_token' => $preview['preview_token']],
            ['If-Match' => $preview['revision'], 'Idempotency-Key' => $key]);
    }

    private function balance(): array
    {
        return app(CalculateClientTimebankBalances::class)->forClient($this->client)->first();
    }

    public function test_restore_is_append_only_and_preview_does_not_write(): void
    {
        $source = $this->quick();
        $before = $source->fresh()->getRawOriginal();
        $revision = EconomyMutex::revision();
        $p = $this->postJson($this->url('adjustment-preview'), $this->adjustment())->assertOk()
            ->assertJsonPath('data.allowed', true)->assertJsonPath('data.adjustment_minutes', 480)->json('data');
        $this->assertSame($revision, EconomyMutex::revision());
        $this->assertDatabaseCount('commercial_timebank_corrections', 0);
        $result = $this->confirm('adjustment', $this->adjustment(), $p)->assertCreated()
            ->assertJsonPath('data.preserved_employee_time', true);
        $this->assertSame(600, $this->balance()['remaining_minutes']);
        $this->assertSame(480, $this->balance()['used_minutes']);
        $this->assertLessThan(100, $this->balance()['usage_percent'], 'A restored bank with remaining time must not look exhausted.');
        $this->assertSame($before, $source->fresh()->getRawOriginal());
        $this->getJson($this->url('corrections/'.$result->json('data.id')))->assertOk();
        $this->getJson($this->url('operations/'.$result->json('operation_id')))->assertOk();
        $this->confirm('adjustment', $this->adjustment(), $p)->assertCreated()->assertJsonPath('replayed', true);
        $this->assertDatabaseCount('commercial_timebank_corrections', 1);
    }

    public function test_zero_overused_and_custom_targets_use_signed_balance(): void
    {
        $this->quick(720);
        $this->confirm('adjustment', $this->adjustment('zero'))->assertCreated()
            ->assertJsonPath('data.effects.0.adjustment_minutes', 120);
        $this->assertSame(0, $this->balance()['overused_minutes']);
        $this->confirm('adjustment', $this->adjustment('custom', 90), key: 'custom-command-002')->assertCreated();
        $this->assertSame(90, $this->balance()['remaining_minutes']);
    }

    public function test_reversal_retains_work_once_and_readback_and_history(): void
    {
        $row = $this->quick();
        $before = $row->fresh()->getRawOriginal();
        $input = ['source_type' => 'quick', 'source_id' => $row->id, 'reason' => 'Wrong customer charge'];
        $p = $this->postJson($this->url('reversal-preview'), $input)->assertOk()->json('data');
        $this->confirm('reversal', $input, $p)->assertCreated()->assertJsonPath('data.effects.0.reversed_usage_minutes', 480);
        $this->assertSame(600, $this->balance()['remaining_minutes']);
        $this->assertSame($before, $row->fresh()->getRawOriginal());
        $this->confirm('reversal', $input, $p)->assertCreated()->assertJsonPath('replayed', true);
        $this->postJson($this->url('reversal-preview'), $input)->assertOk()->assertJsonPath('data.allowed', false);
        $this->getJson($this->url('history'))->assertOk()->assertJsonFragment(['kind' => 'reversal']);
        $this->assertDatabaseCount('commercial_timebank_corrections', 1);
    }

    public function test_stale_previews_missing_preconditions_and_current_rights(): void
    {
        $this->quick();
        $p = $this->postJson($this->url('adjustment-preview'), $this->adjustment())->assertOk()->json('data');
        $this->postJson($this->url('adjustments'), $this->adjustment())->assertStatus(428);
        $this->quick(10);
        $this->confirm('adjustment', $this->adjustment(), $p)->assertStatus(409);
        $this->actor->revokePermissionTo('commercial.timebank.adjust');
        $this->postJson($this->url('adjustment-preview'), $this->adjustment())->assertForbidden();
        $this->assertDatabaseCount('commercial_timebank_corrections', 0);
    }

    public function test_scopes_feature_flag_parent_and_input_are_enforced(): void
    {
        $row = $this->quick();
        Sanctum::actingAs($this->actor, ['commercial.timebank.read']);
        $this->postJson($this->url('adjustment-preview'), $this->adjustment())->assertForbidden();
        Sanctum::actingAs($this->actor, ['commercial.timebank.adjust', 'commercial.timebank.reverse']);
        $this->postJson($this->url('adjustment-preview'), $this->adjustment() + ['employee_minutes' => 0])->assertUnprocessable();
        $this->postJson($this->url('adjustment-preview'), array_replace($this->adjustment(), ['effective_date' => '2027-01-01']))->assertUnprocessable();
        $other = Client::factory()->create();
        $this->postJson('/api/v1/commercial/clients/'.$other->id.'/timebank/reversal-preview',
            ['source_type' => 'quick', 'source_id' => $row->id, 'reason' => 'Wrong parent'])->assertNotFound();
        config(['commercial.timebank_corrections_enabled' => false]);
        $this->postJson($this->url('adjustment-preview'), $this->adjustment())->assertStatus(503);
    }

    public function test_draft_line_is_retained_and_finalized_line_blocks_all_effects(): void
    {
        $row = $this->quick(720);
        $order = EconomyOrder::create(['client_id' => $this->client->id, 'order_number' => 'TEST-001',
            'status' => 'draft', 'period_start' => '2026-11-01', 'period_end' => '2026-11-30']);
        $line = EconomyOrderLine::create(['economy_order_id' => $order->id, 'client_id' => $this->client->id,
            'source_type' => $row->getMorphClass(), 'source_id' => $row->id, 'line_type' => 'quick_timebank_overuse',
            'description' => 'Overuse', 'quantity' => 120, 'unit' => 'min', 'unit_price_ex_vat' => 10,
            'line_total_ex_vat' => 1200, 'total_inc_vat' => 1500, 'currency' => 'NOK', 'status' => 'active']);
        $input = ['source_type' => 'quick', 'source_id' => $row->id, 'reason' => 'Erroneous billing'];
        $order->forceFill(['status' => 'invoiced'])->save();
        $this->postJson($this->url('reversal-preview'), $input)->assertOk()->assertJsonPath('data.allowed', false)
            ->assertJsonFragment(['financial_correction_required']);
        $this->assertDatabaseCount('commercial_timebank_corrections', 0);
        $order->forceFill(['status' => 'draft'])->save();
        $review = $this->postJson($this->url('reversal-preview'), $input)->assertOk()->json('data');
        $applied = $this->confirm('reversal', $input, $review)->assertCreated();
        $this->assertSame('reversed', $line->fresh()->status);
        $this->assertSame('0.00', $order->fresh()->subtotal_ex_vat);
        $this->assertSame(720, $row->fresh()->minutes);
        $this->assertDatabaseHas('economy_order_lines', ['id' => $line->id]);
        $this->actor->revokePermissionTo('economy.order_manage');
        $this->confirm('reversal', $input, $review)->assertForbidden();
        $this->getJson($this->url('corrections/'.$applied->json('data.id')))->assertOk()
            ->assertJsonPath('data.result.draft_line_ids', []);
    }

    public function test_expired_preview_and_conflicting_key_cannot_apply(): void
    {
        $this->quick();
        $p = $this->postJson($this->url('adjustment-preview'), $this->adjustment())->assertOk()->json('data');
        $this->travel(11)->minutes();
        $this->confirm('adjustment', $this->adjustment(), $p)->assertStatus(409);
        $this->confirm('adjustment', $this->adjustment())->assertCreated();
        $this->confirm('adjustment', $this->adjustment('zero'))->assertStatus(409);
        $this->assertDatabaseCount('commercial_timebank_corrections', 1);
    }

    public function test_task_projection_reversal_preserves_actual_work_and_hides_private_sources(): void
    {
        $context = app(\App\Modules\WorkContext\Actions\ResolveWorkContext::class)->fromClientId($this->client->id);
        $ticket = Ticket::factory()->create(['client_id' => $this->client->id, 'work_context_id' => $context->id]);
        $task = app(\App\Modules\Task\Actions\StoreTask::class)->handle(['title' => 'Actual work retained'], $this->actor, $ticket);
        $actual = \App\Modules\Task\Models\TaskTimeEntry::create(['task_id' => $task->id,
            'user_id' => $this->actor->id, 'source_type' => 'manual', 'work_date' => '2026-11-01',
            'minutes' => 5, 'billable' => true, 'note' => 'Five actual minutes']);
        $billing = TicketTimeEntry::create(['ticket_id' => $ticket->id, 'task_id' => $task->id,
            'user_id' => $this->actor->id, 'type' => 'task_billing', 'work_date' => '2026-11-01', 'minutes' => 30,
            'billable' => true, 'billing_status' => 'pending', 'timebank_status' => 'pending',
            'contract_id' => $this->item->contract_id, 'contract_item_id' => $this->item->id, 'invoice_text' => 'Minimum charge']);
        $actualBefore = $actual->fresh()->getRawOriginal();
        $billingBefore = $billing->fresh()->getRawOriginal();
        $input = ['source_type' => 'task_billing', 'source_id' => $billing->id, 'reason' => 'Wrong minimum customer charge'];
        $this->confirm('reversal', $input)->assertCreated();
        $this->assertSame($actualBefore, $actual->fresh()->getRawOriginal());
        $this->assertSame($billingBefore, $billing->fresh()->getRawOriginal());
        $report = app(ClientTimeReportQuery::class)->build($this->client, $this->actor, null, null);
        $this->assertSame(5, $report['totals']['work_minutes']);
        $this->assertSame(0, $report['totals']['basis_minutes']);
        $this->assertSame(30, $report['entries']->firstWhere('source', 'ticket')['gross_basis_minutes']);
        $input['source_type'] = 'ticket';
        $this->postJson($this->url('reversal-preview'), $input)->assertOk()->assertJsonPath('data.allowed', false);
        $other = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $task->forceFill(['visibility' => \App\Modules\Task\Models\Task::VISIBILITY_PRIVATE,
            'created_by' => $other->id, 'assigned_to' => $other->id])->save();
        $this->postJson($this->url('reversal-preview'), $input)->assertNotFound();
    }

    public function test_generation_does_not_recreate_reversed_quick_overuse(): void
    {
        $source = $this->quick(720);
        $source->forceFill(['rate_amount_ex_vat' => 600, 'rate_currency' => 'NOK'])->save();
        $this->confirm('reversal', ['source_type' => 'quick', 'source_id' => $source->id,
            'reason' => 'Do not bill this customer'])->assertCreated();
        $summary = app(\App\Modules\Economy\Actions\GenerateOrders::class)->handle(
            \Carbon\Carbon::parse('2026-11-01'), \Carbon\Carbon::parse('2026-11-30'), $this->actor);
        $this->assertSame(0, $summary['quick_timebank_entries_seen']);
        $this->assertDatabaseMissing('economy_order_lines', ['source_type' => $source->getMorphClass(), 'source_id' => $source->id]);
        $this->assertSame(720, $source->fresh()->minutes);
    }

    public function test_browser_workspace_preview_and_confirmation_use_same_workflow(): void
    {
        \Spatie\Permission\Models\Role::findOrCreate('Tech', 'web');
        $this->actor->assignRole('Tech');
        $this->actingAs($this->actor);
        $this->quick();
        $this->get('/tech/clients/'.$this->client->id.'/timebank-corrections')
            ->assertOk()->assertSee('Employee work time will remain unchanged');
        $response = $this->post('/tech/clients/'.$this->client->id.'/timebank-corrections/adjustment/preview', $this->adjustment())
            ->assertOk()->assertSee('Confirm adjustment');
        $preview = $response->viewData('preview');
        $this->post('/tech/clients/'.$this->client->id.'/timebank-corrections/adjustment',
            $this->adjustment() + ['preview_token' => $preview['preview_token'], 'revision' => $preview['revision'],
                'command_key' => 'browser-command-001'])->assertOk()->assertSee('recorded');
        $this->assertSame(600, $this->balance()['remaining_minutes']);
    }

    public function test_same_day_reset_preserves_processed_work_and_orders_later_allocation(): void
    {
        $context = app(\App\Modules\WorkContext\Actions\ResolveWorkContext::class)->fromClientId($this->client->id);
        $ticket = Ticket::factory()->create(['client_id' => $this->client->id, 'work_context_id' => $context->id]);
        $entry = TicketTimeEntry::create(['ticket_id' => $ticket->id, 'user_id' => $this->actor->id,
            'type' => 'manual', 'work_date' => '2026-11-09', 'minutes' => 60, 'billable' => true,
            'billing_status' => 'covered', 'timebank_status' => 'covered', 'billing_basis' => 'contract',
            'contract_id' => $this->item->contract_id, 'contract_item_id' => $this->item->id]);
        $allocation = \App\Modules\Ticket\Models\TicketTimeEntryAllocation::create([
            'ticket_time_entry_id' => $entry->id, 'ticket_id' => $ticket->id, 'client_id' => $this->client->id,
            'contract_id' => $this->item->contract_id, 'contract_item_id' => $this->item->id,
            'period_start' => '2026-01-01', 'period_end' => '2026-12-31',
            'included_minutes' => 600, 'covered_minutes' => 60, 'billable_minutes' => 0, 'status' => 'covered']);
        $before = $allocation->fresh()->getRawOriginal();
        $result = $this->confirm('adjustment', $this->adjustment())->assertCreated();
        $this->assertSame(600, $this->balance()['remaining_minutes']);
        $this->assertSame($before, $allocation->fresh()->getRawOriginal());
        $this->assertSame(0, \App\Modules\Commercial\Support\TimebankLedger::adjustments(
            $this->item->id, '2026-01-01', '2026-12-31', '2026-11-09', 0));
        $this->assertSame(60, \App\Modules\Commercial\Support\TimebankLedger::adjustments(
            $this->item->id, '2026-01-01', '2026-12-31', '2026-11-09', $result->json('data.id')));
        $reversal = ['source_type' => 'ticket', 'source_id' => $entry->id, 'reason' => 'Wrong customer charge'];
        $this->confirm('reversal', $reversal, key: 'covered-reversal-001')->assertCreated()
            ->assertJsonPath('data.effects.0.reversed_covered_minutes', 60);
        $this->assertSame($before, $allocation->fresh()->getRawOriginal());
        $this->assertSame(60, $entry->fresh()->minutes);
        $resource = (new \App\Modules\Ticket\Resources\Api\V1\TicketTimeEntryResource($entry->fresh()))->resolve(request());
        $this->assertTrue($resource['customer_charge_reversed']);
    }

    public function test_reversed_source_readback_and_original_edit_guard(): void
    {
        $row = $this->quick();
        $this->confirm('reversal', ['source_type' => 'quick', 'source_id' => $row->id,
            'reason' => 'Keep original work'])->assertCreated();
        $this->getJson($this->url('consumptions/'.$row->id))->assertOk()
            ->assertJsonPath('data.customer_charge_reversed', true)->assertJsonPath('data.minutes', 480);
        try {
            $row->forceFill(['minutes' => 1])->save();
            $this->fail('A reversed source must not be editable.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertSame(480, $row->fresh()->minutes);
        $this->getJson($this->url('corrections/999999999999999999999'))->assertNotFound();
    }

    public function test_database_restricts_deleting_original_source_and_customer(): void
    {
        $row = $this->quick();
        $this->confirm('reversal', ['source_type' => 'quick', 'source_id' => $row->id,
            'reason' => 'Retain source through parent deletions'])->assertCreated();
        foreach ([[$row->getTable(), $row->id], ['clients', $this->client->id], ['contract_items', $this->item->id]] as [$table, $id]) {
            try {
                \Illuminate\Support\Facades\DB::table($table)->where('id', $id)->delete();
                $this->fail('Original correction dependencies must not be deleted.');
            } catch (\Illuminate\Database\QueryException $e) {
                $this->assertStringStartsWith('23', (string) $e->getCode());
            }
        }
        $this->assertDatabaseHas($row->getTable(), ['id' => $row->id, 'minutes' => 480]);
    }

    public function test_corrected_report_downloads_keep_work_and_explain_net_customer_totals(): void
    {
        $row = $this->quick();
        $applied = $this->confirm('reversal', ['source_type' => 'quick', 'source_id' => $row->id,
            'reason' => 'Wrong customer; employee work retained'])->assertCreated();
        $this->actor->givePermissionTo('report.view');
        Sanctum::actingAs($this->actor, ['clients.reports.time.read', 'clients.reports.time.export']);
        $base = '/api/v1/clients/'.$this->client->id.'/reports/time/export';
        $xlsx = $this->get($base.'?format=xlsx', ['Accept' => 'application/json'])->assertOk();
        $path = sys_get_temp_dir().'/timebank-correction-'.\Illuminate\Support\Str::uuid().'.xlsx';
        file_put_contents($path, $xlsx->streamedContent());
        try {
            $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
            $this->assertSame(480, $book->getSheetByName('Summary')->getCell('G9')->getValue());
            $this->assertSame(0, $book->getSheetByName('Summary')->getCell('H9')->getValue());
            $this->assertSame(480, $book->getSheetByName('Time entries')->getCell('J2')->getValue());
            $this->assertSame(0, $book->getSheetByName('Time entries')->getCell('K2')->getValue());
            $this->assertSame($applied->json('data.id'), $book->getSheetByName('Time entries')->getCell('R2')->getValue());
            $this->assertSame('reversal', $book->getSheetByName('Corrections')->getCell('B2')->getValue());
            $this->assertSame(480, $book->getSheetByName('Corrections')->getCell('I2')->getValue());
            $book->disconnectWorksheets();
        } finally {
            @unlink($path);
        }
        $pdf = $this->get($base.'?format=pdf', ['Accept' => 'application/json'])->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->assertSame(480, $row->fresh()->minutes);
    }
}
