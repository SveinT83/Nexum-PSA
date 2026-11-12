<?php

namespace App\Modules\Task\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Task\Actions\AssignTask;
use App\Modules\Task\Actions\ChangeTaskStatus;
use App\Modules\Task\Actions\CompleteTask;
use App\Modules\Task\Actions\ReplaceTaskChecklist;
use App\Modules\Task\Actions\SetTaskChecklistState;
use App\Modules\Task\Actions\StoreTask;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskStatus;
use App\Modules\Task\Resources\Api\V1\TaskResource;
use App\Modules\Ticket\Actions\MarkTicketRead;
use App\Modules\Ticket\Actions\StoreTicket;
use App\Modules\Ticket\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RecordActorAttributionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Queue::fake();
        Role::firstOrCreate(['name' => 'Tech', 'guard_name' => 'web']);
    }

    private function actor(string $name): User
    {
        $user = User::factory()->create(['name' => $name, 'status' => User::STATUS_ACTIVE]);
        $user->assignRole('Tech');
        $user->givePermissionTo([
            'task.view', 'task.create', 'task.update', 'task.assign', 'task.complete',
            'ticket.view', 'ticket.create', 'ticket.update', 'ticket.assign',
        ]);

        return $user;
    }

    private function task(User $actor, array $data = []): Task
    {
        return app(StoreTask::class)->handle($data + ['title' => 'Attribution task'], $actor);
    }

    private function ticket(User $actor): Ticket
    {
        return app(StoreTicket::class)->handle([
            'subject' => 'Attribution ticket', 'owner_id' => $actor->id,
            'suppress_notifications' => true,
        ], $actor);
    }

    public function test_both_api_creates_return_verified_actor_references(): void
    {
        $creator = $this->actor('Creator');
        Sanctum::actingAs($creator, ['tasks.read', 'tasks.create', 'tickets.read', 'tickets.create']);
        foreach ([
            ['api.v1.tasks.store', ['title' => 'Created task']],
            ['api.v1.tickets.store', ['subject' => 'Created ticket', 'owner_id' => $creator->id]],
        ] as [$route, $payload]) {
            $response = $this->postJson(route($route), $payload)->assertCreated()
                ->assertJsonPath('data.created_by', $creator->id)
                ->assertJsonPath('data.updated_by', $creator->id)
                ->assertJsonPath('data.creator.name', 'Creator')
                ->assertJsonPath('data.updater.name', 'Creator');
            $this->assertNotNull($response->json('data.actor_updated_at'));
            $this->assertSame(['id', 'name'], array_keys($response->json('data.creator')));
            $this->assertSame(['id', 'name'], array_keys($response->json('data.updater')));
        }
    }

    public function test_second_actor_api_edits_preserve_creator_and_noop_preserves_updater(): void
    {
        $creator = $this->actor('Creator');
        $editor = $this->actor('Editor');
        $third = $this->actor('No-op user');
        foreach ([
            ['tasks', $this->task($creator), ['title' => 'Changed task']],
            ['tickets', $this->ticket($creator), ['subject' => 'Changed ticket']],
        ] as [$domain, $record, $payload]) {
            $this->travel(5)->minutes();
            Sanctum::actingAs($editor, [$domain.'.read', $domain.'.update']);
            $response = $this->patchJson(route('api.v1.'.$domain.'.update', $record), $payload)->assertOk()
                ->assertJsonPath('data.created_by', $creator->id)
                ->assertJsonPath('data.updated_by', $editor->id)
                ->assertJsonPath('data.updater.name', 'Editor');
            $stamp = $response->json('data.actor_updated_at');
            $this->travel(5)->minutes();
            Sanctum::actingAs($third, [$domain.'.read', $domain.'.update']);
            $this->patchJson(route('api.v1.'.$domain.'.update', $record), $payload)->assertOk()
                ->assertJsonPath('data.updated_by', $editor->id)
                ->assertJsonPath('data.actor_updated_at', $stamp);
            $this->getJson(route('api.v1.'.$domain.'.show', $record))->assertOk()
                ->assertJsonPath('data.creator.name', 'Creator')
                ->assertJsonPath('data.updater.name', 'Editor');
        }
    }

    public function test_actor_input_cannot_be_spoofed_on_either_api(): void
    {
        $creator = $this->actor('Creator');
        $other = $this->actor('Other');
        foreach (['tasks', 'tickets'] as $domain) {
            Sanctum::actingAs($creator, [$domain.'.read', $domain.'.create', $domain.'.update']);
            $payload = $domain === 'tasks' ? ['title' => 'Spoofed'] : ['subject' => 'Spoofed'];
            $this->postJson(route('api.v1.'.$domain.'.store'), $payload + ['created_by' => $other->id])->assertUnprocessable();
            $record = $domain === 'tasks' ? $this->task($creator) : $this->ticket($creator);
            $this->patchJson(route('api.v1.'.$domain.'.update', $record), ['updated_by' => $other->id])->assertUnprocessable();
            $this->assertSame($creator->id, (int) $record->refresh()->created_by);
        }
    }

    public function test_technical_touch_and_mark_read_do_not_attribute_a_human_edit(): void
    {
        $creator = $this->actor('Creator');
        $reader = $this->actor('Reader');
        foreach ([$this->task($creator), $this->ticket($creator)] as $record) {
            $stamp = $record->actor_updated_at->toISOString();
            $this->travel(5)->minutes();
            $this->actingAs($reader);
            $record->touch();
            if ($record instanceof Ticket) {
                $record->forceFill(['is_unread' => true])->save();
                app(MarkTicketRead::class)->handle($record, $reader);
            }
            $record->refresh();
            $this->assertSame($creator->id, (int) $record->updated_by);
            $this->assertSame($stamp, $record->actor_updated_at->toISOString());
        }
    }

    public function test_unknown_writer_does_not_inherit_previous_or_ambient_actor(): void
    {
        $creator = $this->actor('Creator');
        $this->actingAs($this->actor('Ambient login'));
        foreach ([$this->task($creator), $this->ticket($creator)] as $record) {
            $record->forceFill(['description' => 'Background change'])->save();
            $this->assertNull($record->refresh()->updated_by);
            $this->assertNull($record->updater);
            $this->assertSame($creator->id, (int) $record->created_by);
        }
    }

    public function test_model_creator_is_immutable_during_ordinary_edits(): void
    {
        $creator = $this->actor('Creator');
        $editor = $this->actor('Editor');
        foreach ([$this->task($creator), $this->ticket($creator)] as $record) {
            $record->forceFill(['created_by' => $editor->id, 'updated_by' => $editor->id, 'description' => 'Edited'])->save();
            $this->assertSame($creator->id, (int) $record->refresh()->created_by);
            $this->assertSame($editor->id, (int) $record->updated_by);
        }
    }

    public function test_task_assignment_status_completion_and_related_edits_use_explicit_actor(): void
    {
        $creator = $this->actor('Creator');
        $editor = $this->actor('Editor');
        $task = $this->task($creator);
        $this->actingAs($creator); // Explicit action actor wins over ambient identity.
        app(AssignTask::class)->handle($task, $editor->id, $editor);
        $this->assertSame($editor->id, (int) $task->refresh()->updated_by);
        $status = TaskStatus::query()->where('is_done', false)->where('id', '!=', $task->status_id)->firstOrFail();
        app(ChangeTaskStatus::class)->handle($task, $status->id, $editor);
        $this->assertSame($editor->id, (int) $task->refresh()->updated_by);
        app(ReplaceTaskChecklist::class)->handle($task, [['title' => 'Check', 'sort_order' => 10]], $creator);
        $item = $task->checklistItems()->firstOrFail();
        app(SetTaskChecklistState::class)->handle($task, $item, true, $editor);
        $this->assertSame($editor->id, (int) $task->refresh()->updated_by);
        $stamp = $task->actor_updated_at->toISOString();
        $this->travel(1)->minutes();
        app(ReplaceTaskChecklist::class)->handle($task, [['title' => 'Check', 'sort_order' => 10]], $creator);
        $this->assertTrue($task->checklistItems()->firstOrFail()->is_checked);
        $this->assertSame($stamp, $task->refresh()->actor_updated_at->toISOString());
        app(CompleteTask::class)->handle($task, $creator);
        $this->assertSame($creator->id, (int) $task->refresh()->updated_by);
        $this->assertSame($creator->id, (int) $task->created_by);
    }

    public function test_task_tag_only_api_edit_changes_attribution_but_repeating_it_does_not(): void
    {
        $creator = $this->actor('Creator');
        $editor = $this->actor('Editor');
        $task = $this->task($creator);
        Sanctum::actingAs($editor, ['tasks.read', 'tasks.update']);
        $payload = ['tag_names' => ['Attribution']];
        $response = $this->patchJson(route('api.v1.tasks.update', $task), $payload)->assertOk()
            ->assertJsonPath('data.updated_by', $editor->id);
        $stamp = $response->json('data.actor_updated_at');
        $this->travel(2)->minutes();
        Sanctum::actingAs($creator, ['tasks.read', 'tasks.update']);
        $this->patchJson(route('api.v1.tasks.update', $task), $payload)->assertOk()
            ->assertJsonPath('data.updated_by', $editor->id)->assertJsonPath('data.actor_updated_at', $stamp);
    }

    public function test_private_task_attribution_is_not_disclosed_to_other_staff(): void
    {
        $creator = $this->actor('Creator');
        $task = $this->task($creator, ['visibility' => Task::VISIBILITY_PRIVATE]);
        Sanctum::actingAs($this->actor('Other'), ['tasks.read']);
        $this->getJson(route('api.v1.tasks.show', $task))->assertNotFound();
        $this->getJson(route('api.v1.tasks.index', ['q' => $task->title]))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_legacy_and_missing_user_references_render_unknown_without_fabricated_time(): void
    {
        $creator = $this->actor('<script>Creator</script>');
        $task = $this->task($creator);
        $html = Blade::render('<x-record-attribution :record="$record" />', ['record' => $task]);
        $this->assertStringContainsString('&lt;script&gt;Creator&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>Creator</script>', $html);
        DB::table('tasks')->where('id', $task->id)->update([
            'created_by' => null, 'updated_by' => 99999999, 'actor_updated_at' => null,
        ]);
        $task->refresh();
        $html = Blade::render('<x-record-attribution :record="$record" />', ['record' => $task]);
        $this->assertStringContainsString('Created by: Unknown', $html);
        $this->assertStringContainsString('Updated by: Unknown', $html);
        $data = (new TaskResource($task))->resolve(request());
        $this->assertNull($data['creator']);
        $this->assertNull($data['updater']);
        $this->assertNull($data['actor_updated_at']);
    }

    public function test_both_browser_detail_pages_show_the_verified_header(): void
    {
        $creator = $this->actor('Visible Creator');
        $editor = $this->actor('Visible Editor');
        foreach ([
            ['tasks', $this->task($creator)],
            ['tickets', $this->ticket($creator)],
        ] as [$domain, $record]) {
            $record->forceFill(['description' => 'Edited', 'updated_by' => $editor->id])->save();
            $this->actingAs($creator)->get(route('tech.'.$domain.'.show', $record))
                ->assertOk()->assertSee('data-record-attribution', false)
                ->assertSee('Created by: Visible Creator')
                ->assertSee('Updated by: Visible Editor');
        }
    }

    public function test_automatic_assignment_retains_the_verified_system_actor(): void
    {
        $creator = $this->actor('Creator');
        $assignee = $this->actor('Assignee');
        $ticket = $this->ticket($creator);
        $system = app(\App\Modules\Ticket\Actions\TicketRuleAutomationActor::class)->resolve();
        \App\Modules\Ticket\Models\TicketAssignmentRule::query()->create([
            'name' => 'Attribution assignment', 'is_active' => true, 'weight' => 1,
            'action_type' => 'assign_user', 'action_value' => (string) $assignee->id,
            'conditions_json' => [],
        ]);
        app(\App\Modules\Ticket\Services\TicketAssignmentEngine::class)->assign($ticket, force: true, actor: $system);
        $ticket->refresh();
        $this->assertSame($creator->id, (int) $ticket->created_by);
        $this->assertSame($assignee->id, (int) $ticket->owner_id);
        $this->assertSame($system->id, (int) $ticket->updated_by);
        $this->assertSame($system->name, $ticket->updater->name);
    }

    public function test_related_mutation_rollback_does_not_leave_false_attribution(): void
    {
        $creator = $this->actor('Creator');
        $editor = $this->actor('Editor');
        $task = $this->task($creator);
        $stamp = $task->actor_updated_at->toISOString();
        try {
            DB::transaction(function () use ($task, $editor): void {
                $task->recordRelatedMutation($editor);
                throw new \RuntimeException('Rollback synthetic mutation');
            });
        } catch (\RuntimeException) {
            // The actor and timestamp must follow the same transaction rollback.
        }
        $task->refresh();
        $this->assertSame($creator->id, (int) $task->updated_by);
        $this->assertSame($stamp, $task->actor_updated_at->toISOString());
    }

    public function test_schedule_only_change_attributes_editor_and_repeated_input_does_not(): void
    {
        $creator = $this->actor('Creator');
        $editor = $this->actor('Schedule editor');
        $ticket = $this->ticket($creator);
        $payload = [
            'is_scheduled' => true, 'planned_start_at' => now()->addDay()->startOfMinute()->toDateTimeString(),
            'schedule_type' => 'one_time', 'timezone' => 'UTC',
        ];
        app(\App\Modules\Ticket\Actions\UpdateTicketFields::class)->handle($ticket, $payload, $editor);
        $ticket->refresh();
        $stamp = $ticket->actor_updated_at->toISOString();
        $this->assertSame($editor->id, (int) $ticket->updated_by);
        $this->travel(1)->minutes();
        app(\App\Modules\Ticket\Actions\UpdateTicketFields::class)->handle($ticket, $payload, $creator);
        $this->assertSame($editor->id, (int) $ticket->refresh()->updated_by);
        $this->assertSame($stamp, $ticket->actor_updated_at->toISOString());
    }

    public function test_portal_publication_attributes_publisher_without_changing_creator(): void
    {
        $creator = $this->actor('Creator');
        $publisher = $this->actor('Publisher');
        $client = \App\Models\Clients\Client::factory()->create();
        $ticket = Ticket::factory()->create([
            'client_id' => $client->id, 'created_by' => $creator->id, 'updated_by' => $creator->id,
        ]);
        $action = app(\App\Modules\Ticket\Actions\PublishTicketToCustomerPortal::class);
        $action->handle($ticket, $publisher);
        $ticket->refresh();
        $stamp = $ticket->actor_updated_at->toISOString();
        $this->assertSame($creator->id, (int) $ticket->created_by);
        $this->assertSame($publisher->id, (int) $ticket->updated_by);
        $this->travel(1)->minutes();
        $action->handle($ticket, $creator);
        $this->assertSame($publisher->id, (int) $ticket->refresh()->updated_by);
        $this->assertSame($stamp, $ticket->actor_updated_at->toISOString());
    }

    public function test_task_collection_batches_actor_queries(): void
    {
        $actor = $this->actor('Creator');
        for ($i = 0; $i < 5; $i++) {
            $this->task($actor);
        }
        $records = Task::all();
        DB::enableQueryLog();
        DB::flushQueryLog();
        TaskResource::collection($records)->resolve(request());
        $actorQueries = collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], $actor->getTable()));
        $this->assertCount(2, $actorQueries);
        DB::disableQueryLog();
    }
}
