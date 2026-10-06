<?php

namespace App\Modules\Commercial\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Core\User;
use App\Modules\Commercial\Models\Contracts\ClientContractTimeConsumption;
use App\Modules\Commercial\Models\Contracts\ContractItem;
use App\Modules\Commercial\Models\Contracts\Contracts;
use App\Modules\Integration\Models\AiDataEgressPolicy;
use App\Modules\Integration\Models\AiWorkloadProfile;
use App\Modules\Integration\Models\AiWorkloadTokenBinding;
use App\Modules\Task\Actions\StoreTask;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketTimeEntryAllocation;
use App\Modules\WorkContext\Models\WorkContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CommercialWorklogTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Client $client;

    private WorkContext $context;

    private AiWorkloadProfile $workload;

    private string $token;

    private Contracts $contract;

    private ContractItem $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        foreach (['report.view', 'ticket.view', 'task.view', 'commercial.view', 'commercial.timebank.view'] as $permission) {
            $this->actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $this->client = Client::factory()->create();
        $this->context = WorkContext::query()->create(['type' => 'client', 'client_id' => $this->client->id, 'name' => 'Sensitive client context']);
        $this->contract = Contracts::query()->create(['client_id' => $this->client->id, 'description' => 'Private agreement text', 'start_date' => '2025-01-01', 'approval_status' => 'approved', 'created_by' => $this->actor->id]);
        $this->item = ContractItem::withoutEvents(fn () => ContractItem::query()->create(['contract_id' => $this->contract->id, 'name' => 'Private contract line', 'unit_price' => 100, 'quantity' => 1, 'unit' => 'hour', 'billing_interval' => 'monthly']));
        AiDataEgressPolicy::installation()->update(['ai_enabled' => true, 'allowed_processing_modes' => ['local_only'], 'maximum_data_profile' => 'pseudonymized', 'context_scope' => 'selected_clients', 'maximum_query_days' => 31, 'maximum_page_size' => 50, 'maximum_results' => 200, 'requests_per_minute' => 60]);
        $abilities = ['time-entries.read', 'worklog.read', 'commercial.worklog.read', 'commercial.worklog-links.read'];
        $this->workload = AiWorkloadProfile::query()->create(['name' => 'History test', 'slug' => 'history-test', 'purpose' => 'Approved synthetic reconciliation', 'workload_type' => 'coordinator_api', 'processing_mode' => 'local_only', 'maximum_data_profile' => 'pseudonymized', 'abilities' => $abilities, 'allowed_client_ids' => [$this->client->id], 'is_active' => true, 'is_approved' => true, 'expires_at' => now()->addDay(), 'created_by' => $this->actor->id]);
        $token = $this->actor->createToken('Synthetic history', $abilities);
        $this->token = $token->plainTextToken;
        AiWorkloadTokenBinding::query()->create(['personal_access_token_id' => $token->accessToken->id, 'ai_workload_profile_id' => $this->workload->id, 'expires_at' => now()->addDay(), 'requests_per_minute' => 60, 'created_by' => $this->actor->id]);
    }

    #[Test]
    public function actual_time_quick_consumption_and_billing_links_reconcile_without_double_counting(): void
    {
        $ticket = Ticket::factory()->create(['client_id' => $this->client->id, 'work_context_id' => $this->context->id]);
        $direct = $ticket->timeEntries()->create(['user_id' => $this->actor->id, 'work_date' => '2025-09-01', 'minutes' => 40, 'billable' => true, 'contract_id' => $this->contract->id, 'contract_item_id' => $this->item->id, 'note' => 'Never disclose me']);
        $task = app(StoreTask::class)->handle(['title' => 'Private task', 'estimated_minutes' => 30], $this->actor, $ticket);
        $task->timeEntries()->create(['user_id' => $this->actor->id, 'work_date' => '2025-09-01', 'minutes' => 5, 'billable' => true, 'source_type' => 'ticket_time_entry']);
        $ticket->timeEntries()->create(['task_id' => $task->id, 'user_id' => $this->actor->id, 'work_date' => '2025-09-01', 'minutes' => 30, 'billable' => true, 'contract_id' => $this->contract->id, 'contract_item_id' => $this->item->id]);
        TicketTimeEntryAllocation::query()->create(['ticket_time_entry_id' => $direct->id, 'ticket_id' => $ticket->id, 'client_id' => $this->client->id, 'contract_id' => $this->contract->id, 'contract_item_id' => $this->item->id, 'period_start' => '2025-09-01', 'period_end' => '2025-09-30', 'included_minutes' => 100, 'covered_minutes' => 30, 'billable_minutes' => 10, 'status' => 'allocated']);
        $this->quick('2025-09-01', 20);
        $actual = $this->read('/api/v1/worklog/time-entries')->assertOk()->assertJsonPath('meta.total', 2);
        $quick = $this->read('/api/v1/commercial/worklog/time-consumptions')->assertOk()->assertJsonPath('meta.total', 1);
        $links = $this->read('/api/v1/commercial/worklog/contract-links')->assertOk()->assertJsonPath('meta.total', 2);
        $this->assertSame(45, array_sum(array_column($actual->json('data'), 'minutes')));
        $this->assertSame(20, array_sum(array_column($quick->json('data'), 'minutes')));
        $this->assertSame(70, array_sum(array_column($links->json('data'), 'basis_minutes')));
        $this->assertSame('direct_timebank_consumption', $quick->json('data.0.fact_type'));
        $this->assertSame($actual->json('data.0.client_alias'), $quick->json('data.0.client_alias'));
        foreach ($links->json('data') as $link) {
            $this->assertSame($quick->json('data.0.contract.contract_alias'), $link['contract']['contract_alias']);
            $source = $link['source'] === 'ticket' ? 'ticket' : 'task';
            $work = collect($actual->json('data'))->firstWhere('source', $source);
            $this->assertSame($work['record_alias'], $link['record_alias']);
            if ($source === 'ticket') {
                $this->assertSame($work['entry_alias'], $link['entry_alias']);
                $this->assertSame(30, $link['allocation']['covered_minutes']);
            }
        }
        foreach ([$actual, $quick, $links] as $response) {
            foreach (['Never disclose me', 'Private agreement text', 'Private contract line', 'Private task', 'client_id', 'contract_id', 'unit_price', 'secure_token'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $response->getContent());
            }
            $response->assertJsonPath('meta.truncated', false);
        }
    }

    #[Test]
    public function commercial_history_reports_overflow_and_recovers_disjoint_periods(): void
    {
        AiDataEgressPolicy::installation()->update(['maximum_results' => 2]);
        $this->quick('2025-09-01', 10);
        $this->quick('2025-09-01', 10);
        $this->quick('2025-09-02', 10);
        $this->read('/api/v1/commercial/worklog/time-consumptions')->assertOk()->assertJsonPath('meta.total', 3)->assertJsonPath('meta.available_total', 2)->assertJsonPath('meta.truncated', true);
        foreach (['2025-09-01' => 2, '2025-09-02' => 1] as $date => $count) {
            $this->withToken($this->token)->getJson('/api/v1/commercial/worklog/time-consumptions?date_from='.$date.'&date_to='.$date)
                ->assertOk()->assertJsonPath('meta.truncated', false)->assertJsonPath('meta.total', $count);
        }
    }

    #[Test]
    public function commercial_scope_and_inconsistent_contract_links_fail_closed(): void
    {
        $entry = $this->quick('2025-09-01', 20);
        $otherClient = Client::factory()->create();
        $otherContract = Contracts::query()->create(['client_id' => $otherClient->id, 'description' => 'Other private agreement', 'start_date' => '2025-01-01', 'created_by' => $this->actor->id]);
        $entry->update(['contract_id' => $otherContract->id]);
        $this->read('/api/v1/commercial/worklog/time-consumptions')->assertOk()
            ->assertJsonPath('data.0.contract.link_status', 'inconsistent')->assertJsonPath('data.0.contract.contract_alias', null);
        $entry->update(['client_id' => $otherClient->id]);
        $this->read('/api/v1/commercial/worklog/time-consumptions')->assertOk()->assertJsonCount(0, 'data');
        AiDataEgressPolicy::installation()->update(['context_scope' => 'internal_only']);
        $this->read('/api/v1/commercial/worklog/time-consumptions')->assertOk()->assertJsonCount(0, 'data');
        $this->workload->update(['abilities' => ['time-entries.read']]);
        $this->read('/api/v1/commercial/worklog/time-consumptions')->assertForbidden()->assertJsonPath('reason_code', 'required_scope_missing');
    }

    #[Test]
    public function missing_commercial_permission_is_denied_and_context_lists_intersect(): void
    {
        $this->quick('2025-09-01', 20);
        AiDataEgressPolicy::installation()->update(['context_scope' => 'selected_work_contexts']);
        $this->read('/api/v1/commercial/worklog/time-consumptions')->assertForbidden()->assertJsonPath('reason_code', 'workload_context_scope_missing');
        $this->workload->update(['allowed_work_context_ids' => [$this->context->id]]);
        $this->read('/api/v1/commercial/worklog/time-consumptions')->assertOk()->assertJsonCount(1, 'data');
        $this->workload->update(['allowed_client_ids' => [$this->client->id + 100000]]);
        $this->read('/api/v1/commercial/worklog/time-consumptions')->assertOk()->assertJsonCount(0, 'data');
        $this->actor->revokePermissionTo('commercial.timebank.view');
        app('auth')->forgetGuards();
        $this->read('/api/v1/commercial/worklog/time-consumptions')->assertForbidden();
        $this->read('/api/v1/commercial/worklog/contract-links')->assertForbidden();
    }

    private function quick(string $date, int $minutes): ClientContractTimeConsumption
    {
        return ClientContractTimeConsumption::query()->create(['client_id' => $this->client->id, 'contract_id' => $this->contract->id, 'contract_item_id' => $this->item->id, 'user_id' => $this->actor->id, 'work_date' => $date, 'minutes' => $minutes, 'source' => 'quick_client', 'period_start' => '2025-09-01', 'period_end' => '2025-09-30', 'included_minutes_snapshot' => 100, 'used_before_minutes_snapshot' => 0, 'overused_minutes' => 0]);
    }

    private function read(string $path): \Illuminate\Testing\TestResponse
    {
        return $this->withToken($this->token)->getJson($path.'?date_from=2025-09-01&date_to=2025-09-30');
    }
}
