<?php

namespace App\Modules\Task\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Core\User;
use App\Modules\Commercial\Models\TimeRate;
use App\Modules\Task\Actions\EnsureTaskDefaults;
use App\Modules\Task\Actions\StoreTask;
use App\Modules\Task\Models\Task;
use App\Modules\Ticket\Actions\EnsureTicketDefaults;
use App\Modules\Ticket\Actions\StoreTicket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TaskFormApiTest extends TestCase
{
    use RefreshDatabase;

    private User $tech;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Role::findOrCreate('Tech', 'web');
        $this->tech = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->tech->assignRole('Tech');
        foreach (['task.view', 'task.create', 'task.update', 'ticket.view'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $this->tech->givePermissionTo($permission);
        }
        app(EnsureTaskDefaults::class)->handle();
        app(EnsureTicketDefaults::class)->handle();
        Sanctum::actingAs($this->tech, ['tasks.read', 'tasks.create', 'tasks.update', 'tickets.read']);
    }

    public function test_create_and_patch_persist_checklist_tags_and_explicit_clears(): void
    {
        $id = $this->postJson(route('api.v1.tasks.store'), ['title' => 'Full API form', 'checklist_text' => "First check\nSecond check", 'tag_names' => ['API review', 'api review']])
            ->assertCreated()->assertJsonPath('data.checklist_items.0.title', 'First check')->assertJsonPath('data.tags.0.name', 'API review')->json('data.id');
        $task = Task::findOrFail($id);
        $this->assertSame(1, $task->tags()->count());
        $this->patchJson(route('api.v1.tasks.update', $task), ['checklist_text' => 'Replacement check', 'tag_names' => ['Changed tag']])->assertOk()
            ->assertJsonPath('data.checklist_items.0.title', 'Replacement check')->assertJsonPath('data.tags.0.name', 'Changed tag');
        $this->assertSame('Full API form', $task->refresh()->title);
        $this->patchJson(route('api.v1.tasks.update', $task), ['checklist_text' => null, 'tag_names' => []])->assertOk()->assertJsonPath('data.checklist_items', [])->assertJsonPath('data.tags', []);
    }

    public function test_ticket_rate_is_resolved_and_cannot_be_injected_via_metadata(): void
    {
        $ticket = app(StoreTicket::class)->handle(['subject' => 'Ticket owner', 'client_id' => null], $this->tech);
        $rate = TimeRate::create(['name' => 'Allowed rate', 'slug' => 'allowed-rate', 'code' => 'API', 'rate_type' => 'hourly', 'unit' => 'hour', 'amount_ex_vat' => 900, 'currency' => 'NOK', 'applies_without_contract' => true, 'is_active' => true]);
        $payload = ['title' => 'Ticket Task', 'owner_type' => 'ticket', 'owner_id' => $ticket->id, 'ticket_rate_key' => 'global:'.$rate->id];
        $id = $this->postJson(route('api.v1.tasks.store'), $payload)->assertCreated()->assertJsonPath('data.metadata.ticket_rate_key', 'global:'.$rate->id)->json('data.id');
        $this->patchJson(route('api.v1.tasks.update', $id), ['ticket_rate_key' => null])->assertOk()->assertJsonMissingPath('data.metadata.ticket_rate_key');
        $payload['metadata'] = ['ticket_rate_key' => 'global:999999'];
        $this->postJson(route('api.v1.tasks.store'), $payload)->assertUnprocessable()->assertJsonValidationErrors('metadata');
        unset($payload['metadata']);
        $payload['ticket_rate_key'] = 'global:999999';
        $this->postJson(route('api.v1.tasks.store'), $payload)->assertUnprocessable()->assertJsonValidationErrors('ticket_rate_key');
    }

    public function test_owner_client_and_site_context_are_validated_before_any_write(): void
    {
        $client = Client::factory()->create();
        $other = Client::factory()->create();
        $site = ClientSite::factory()->create(['client_id' => $other->id]);
        $payload = ['title' => 'Invalid Task', 'owner_type' => 'client', 'owner_id' => $client->id, 'client_id' => $other->id];
        $this->postJson(route('api.v1.tasks.store'), $payload)->assertUnprocessable()->assertJsonValidationErrors('client_id');
        $this->postJson(route('api.v1.tasks.store'), ['title' => 'Invalid site', 'site_id' => $site->id])->assertUnprocessable()->assertJsonValidationErrors('site_id');
        $this->assertSame(0, Task::count());
        $task = app(StoreTask::class)->handle(['title' => 'Client Task'], $this->tech, $client);
        $this->patchJson(route('api.v1.tasks.update', $task), ['client_id' => $other->id])->assertUnprocessable();
        $this->assertSame($client->id, $task->refresh()->client_id);
    }

    public function test_private_parent_cycles_and_partial_schedule_inversion_are_rejected(): void
    {
        $task = app(StoreTask::class)->handle(['title' => 'Parent', 'scheduled_start_at' => '2026-10-20 10:00:00', 'scheduled_end_at' => '2026-10-20 11:00:00'], $this->tech);
        $child = app(StoreTask::class)->handle(['title' => 'Child', 'parent_id' => $task->id], $this->tech);
        $this->patchJson(route('api.v1.tasks.update', $task), ['parent_id' => $child->id])->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        $this->patchJson(route('api.v1.tasks.update', $task), ['scheduled_start_at' => '2026-10-20 12:00:00'])->assertUnprocessable()->assertJsonValidationErrors('scheduled_end_at');
        $other = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $private = app(StoreTask::class)->handle(['title' => 'Private parent', 'visibility' => 'private'], $other);
        $this->postJson(route('api.v1.tasks.store'), ['title' => 'Cannot expose parent', 'parent_id' => $private->id])->assertNotFound();
    }

    public function test_list_and_show_exclude_private_tasks_and_bounded_sort_matches_browser_options(): void
    {
        $a = app(StoreTask::class)->handle(['title' => 'Alpha'], $this->tech);
        $b = app(StoreTask::class)->handle(['title' => 'Beta'], $this->tech);
        $other = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $private = app(StoreTask::class)->handle(['title' => 'A private record', 'visibility' => 'private'], $other);
        $this->getJson(route('api.v1.tasks.index', ['sort' => 'title', 'direction' => 'asc', 'per_page' => 1]))->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.id', $a->id);
        $this->getJson(route('api.v1.tasks.show', $private))->assertNotFound();
        $this->getJson(route('api.v1.tasks.index', ['per_page' => 101]))->assertUnprocessable();
        $this->getJson(route('api.v1.tasks.index', ['sort' => 'raw_sql']))->assertUnprocessable();
    }

    public function test_legacy_crud_requires_active_domain_rights_even_with_full_token(): void
    {
        $withoutRights = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        Sanctum::actingAs($withoutRights, ['*']);
        $this->getJson(route('api.v1.tasks.index'))->assertForbidden();
        $this->postJson(route('api.v1.tasks.store'), ['title' => 'Denied'])->assertForbidden();
    }

    public function test_ai_provider_suggestions_are_typed_and_unrecognized_ids_are_removed(): void
    {
        $agent = new \App\Modules\Integration\Models\AiAgent;
        $this->mock(\App\Modules\Integration\Services\AiAgentResolver::class)->shouldReceive('defaultAgent')->once()->andReturn($agent);
        $this->mock(\App\Modules\Integration\Services\AiChatResponder::class)->shouldReceive('complete')->once()->andReturn(json_encode([
            'title' => ['invalid'], 'description' => 'Safe draft', 'ticket_id' => 999999, 'assigned_to' => 999999,
            'estimated_minutes' => '25', 'ticket_rate_key' => ['invalid'], 'checklist_items' => ['Check result', ['invalid'], 5],
            'tag_names' => ['API suggestion', ['invalid']], 'status_id' => 99,
        ]));
        $client = Client::factory()->create();
        Sanctum::actingAs($this->tech, ['tasks.ai.suggest', 'tickets.read']);
        $result = $this->postJson(route('api.v1.tasks.ai-suggestions.store'), ['title' => 'Draft', 'description' => 'Work detail', 'client_id' => $client->id])
            ->assertOk()->assertJsonPath('data.description', 'Safe draft')->assertJsonPath('data.estimated_minutes', 25)
            ->assertJsonPath('data.checklist_items', ['Check result'])->assertJsonPath('data.tag_names', ['API suggestion']);
        foreach (['title', 'ticket_id', 'assigned_to', 'ticket_rate_key', 'status_id'] as $field) {
            $result->assertJsonMissingPath('data.'.$field);
        }
        $this->assertSame(0, Task::count());
    }

    public function test_legacy_internal_ticket_owner_without_stored_context_supports_task_create_read_and_edit(): void
    {
        $ticket = app(StoreTicket::class)->handle(['subject' => 'Legacy internal owner', 'client_id' => null], $this->tech);
        $ticket->forceFill(['work_context_id' => null])->save();
        $id = $this->postJson(route('api.v1.tasks.store'), ['title' => 'Legacy owner Task', 'owner_type' => 'ticket', 'owner_id' => $ticket->id])
            ->assertCreated()->json('data.id');
        $this->getJson(route('api.v1.tasks.show', $id))->assertOk()->assertJsonPath('data.client_id', null);
        $this->patchJson(route('api.v1.tasks.update', $id), ['title' => 'Edited legacy owner Task'])->assertOk()->assertJsonPath('data.title', 'Edited legacy owner Task');
        $this->assertNull($ticket->refresh()->work_context_id);
    }
}
