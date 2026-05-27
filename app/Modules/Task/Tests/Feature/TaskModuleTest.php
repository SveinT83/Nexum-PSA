<?php

namespace App\Modules\Task\Tests\Feature;

use App\Models\Core\User;
use App\Models\Clients\Client;
use App\Modules\Task\Actions\CompleteTask;
use App\Modules\Task\Actions\EnsureTaskDefaults;
use App\Modules\Task\Actions\StoreTask;
use App\Modules\Task\Controllers\Tech\TaskController;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskDependency;
use App\Modules\Task\Models\TaskTimeEntry;
use App\Modules\Taxonomy\Models\Category;
use App\Modules\Taxonomy\Models\Tag;
use App\Modules\Commercial\Models\TimeRate;
use App\Modules\Integration\Models\AiAgent;
use App\Modules\Integration\Models\AiProvider;
use App\Modules\Ticket\Actions\EnsureTicketDefaults;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketPriority;
use App\Modules\Ticket\Models\TicketQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TaskModuleTest extends TestCase
{
    use RefreshDatabase;

    private User $tech;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Tech']);

        $this->tech = User::factory()->create([
            'status' => User::STATUS_ACTIVE,
        ]);
        $this->tech->assignRole('Tech');
    }

    #[Test]
    public function tech_user_can_open_task_index_from_task_module(): void
    {
        $route = Route::getRoutes()->getByName('tech.tasks.index');

        $this->assertSame(TaskController::class . '@index', $route->getActionName());

        $this->actingAs($this->tech)
            ->get(route('tech.tasks.index'))
            ->assertOk()
            ->assertViewIs('task::Tech.Tasks.index')
            ->assertSee('Task List')
            ->assertSee('data-bs-target="#taskFiltersCollapse"', false)
            ->assertSee('data-bs-target="#taskDocumentationModal"', false)
            ->assertSee('bi bi-funnel')
            ->assertSee('New Task');

        $this->assertDatabaseHas('task_statuses', [
            'slug' => 'open',
            'is_default' => true,
        ]);
    }

    #[Test]
    public function tech_user_can_create_standalone_task(): void
    {
        app(EnsureTaskDefaults::class)->handle();

        $queue = TicketQueue::query()->create([
            'name' => 'Service Desk',
            'slug' => 'service-desk',
            'is_active' => true,
        ]);
        $priority = TicketPriority::query()->create([
            'name' => 'Normal',
            'slug' => 'normal',
            'level' => 3,
            'is_active' => true,
        ]);

        $this->actingAs($this->tech)
            ->post(route('tech.tasks.store'), [
                'title' => 'Prepare workstation',
                'description' => 'Install standard apps.',
                'assigned_to' => $this->tech->id,
                'queue_id' => $queue->id,
                'priority_id' => $priority->id,
                'estimated_minutes' => 30,
                'checklist_text' => "Install apps\nRun updates",
                'tag_names' => ['Onboarding', 'Needs order'],
            ])
            ->assertRedirect();

        $task = Task::query()->firstOrFail();

        $this->assertSame('Prepare workstation', $task->title);
        $this->assertSame($this->tech->getMorphClass(), $task->owner_type);
        $this->assertSame($this->tech->id, $task->owner_id);
        $this->assertSame(2, $task->checklistItems()->count());
        $this->assertSame(['Needs order', 'Onboarding'], $task->tags()->orderBy('name')->pluck('name')->all());
        $this->assertSame(1, $task->activities()->where('type', 'created')->count());

        $this->assertDatabaseHas('tags', [
            'name' => 'Needs order',
            'slug' => 'needs-order',
        ]);
    }

    #[Test]
    public function task_create_form_uses_tag_chip_input_with_suggestions(): void
    {
        Tag::query()->create([
            'name' => 'Recurring',
            'slug' => 'recurring',
            'active' => true,
        ]);

        $this->actingAs($this->tech)
            ->get(route('tech.tasks.create'))
            ->assertOk()
            ->assertSee('data-task-tag-input', false)
            ->assertSee('data-bs-target="#taskDocumentationModal"', false)
            ->assertSee('list="taskTagSuggestions"', false)
            ->assertSee('<option value="Recurring"></option>', false)
            ->assertSee('Context')
            ->assertSee('id="task_client_search"', false)
            ->assertSee('id="task_site_search"', false)
            ->assertSee('id="task_ticket_search"', false)
            ->assertSee('id="parent_id"', false)
            ->assertSee('Workflow')
            ->assertSee('Schedule')
            ->assertSee('Add item')
            ->assertDontSee('name="tags[]"', false);
    }

    #[Test]
    public function completing_task_with_estimate_creates_estimated_time_entry(): void
    {
        $task = app(StoreTask::class)->handle([
            'title' => 'Document switch port',
            'estimated_minutes' => 15,
        ], $this->tech);

        app(CompleteTask::class)->handle($task, $this->tech);

        $task->refresh();

        $this->assertNotNull($task->completed_at);
        $this->assertTrue($task->status->is_done);
        $this->assertDatabaseHas('task_time_entries', [
            'task_id' => $task->id,
            'source_type' => 'estimated',
            'minutes' => 15,
        ]);
    }

    #[Test]
    public function checklist_items_are_clickable_and_block_completion_until_done(): void
    {
        $task = app(StoreTask::class)->handle([
            'title' => 'Prepare laptop',
            'checklist' => [
                ['title' => 'Install updates'],
            ],
        ], $this->tech);

        $item = $task->checklistItems()->firstOrFail();

        $this->actingAs($this->tech)
            ->get(route('tech.tasks.show', $task))
            ->assertOk()
            ->assertSee(route('tech.tasks.checklist.toggle', [$task, $item]), false)
            ->assertSee('Edit Task');

        $this->actingAs($this->tech)
            ->post(route('tech.tasks.complete', $task))
            ->assertSessionHasErrors('task');

        $this->actingAs($this->tech)
            ->patch(route('tech.tasks.checklist.toggle', [$task, $item]))
            ->assertRedirect();

        $this->assertTrue($item->fresh()->is_checked);

        $this->actingAs($this->tech)
            ->post(route('tech.tasks.complete', $task))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertNotNull($task->fresh()->completed_at);
    }

    #[Test]
    public function tech_user_can_edit_task_details(): void
    {
        $task = app(StoreTask::class)->handle([
            'title' => 'Old title',
            'checklist' => [
                ['title' => 'Old step'],
            ],
        ], $this->tech);

        $this->actingAs($this->tech)
            ->get(route('tech.tasks.edit', $task))
            ->assertOk()
            ->assertViewIs('task::Tech.Tasks.form')
            ->assertSee('Edit Task')
            ->assertSee('Old step');

        $this->actingAs($this->tech)
            ->patch(route('tech.tasks.update', $task), [
                'title' => 'Updated title',
                'description' => 'Updated description',
                'status_id' => $task->status_id,
                'estimated_minutes' => 45,
                'checklist_text' => "New step\nSecond step",
                'tag_names' => ['Updated tag'],
            ])
            ->assertRedirect(route('tech.tasks.show', $task));

        $task->refresh();

        $this->assertSame('Updated title', $task->title);
        $this->assertSame(45, $task->estimated_minutes);
        $this->assertSame(['New step', 'Second step'], $task->checklistItems()->orderBy('sort_order')->pluck('title')->all());
        $this->assertSame(['Updated tag'], $task->tags()->pluck('name')->all());
    }

    #[Test]
    public function tech_user_can_reassign_task_from_quick_modal_action(): void
    {
        $assignee = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $task = app(StoreTask::class)->handle(['title' => 'Assign me'], $this->tech);

        $this->actingAs($this->tech)
            ->patch(route('tech.tasks.assign', $task), [
                'assigned_to' => $assignee->id,
            ])
            ->assertRedirect();

        $this->assertSame($assignee->id, $task->fresh()->assigned_to);
        $this->assertSame(1, $task->activities()->where('type', 'assigned')->count());
    }

    #[Test]
    public function quick_create_can_create_task_owned_by_ticket_with_ticket_metadata(): void
    {
        $defaults = app(EnsureTicketDefaults::class)->handle();

        $ticket = Ticket::query()->create([
            'ticket_key' => 'TD-2026-123456',
            'queue_id' => $defaults['queue']->id,
            'ticket_type_id' => $defaults['type']->id,
            'type' => $defaults['type']->slug,
            'status_id' => $defaults['status']->id,
            'priority_id' => $defaults['priority']->id,
            'owner_id' => $this->tech->id,
            'created_by' => $this->tech->id,
            'updated_by' => $this->tech->id,
            'channel' => 'manual',
            'subject' => 'Ticket task context',
            'is_unread' => false,
        ]);
        $category = Category::query()->create([
            'name' => 'Access',
            'slug' => 'access-task-prefill-test',
            'type' => Category::TYPE_TICKET,
            'is_active' => true,
        ]);
        $tag = Tag::query()->create([
            'name' => 'VIP',
            'slug' => 'vip-task-prefill-test',
            'active' => true,
        ]);
        $ticket->forceFill(['category_id' => $category->id])->save();
        $ticket->tags()->syncWithPivotValues([$tag->id], ['module' => 'Ticket']);
        $rate = TimeRate::create([
            'name' => 'Quick task support',
            'slug' => 'quick-task-support-test',
            'code' => 'QUICK_TASK_SUPPORT_TEST',
            'rate_type' => 'labor',
            'unit' => 'hour',
            'amount_ex_vat' => 1000,
            'currency' => 'NOK',
            'applies_without_contract' => true,
            'applies_with_contract' => false,
            'is_active' => true,
            'sort_order' => 10,
        ]);

        $this->actingAs($this->tech)
            ->post(route('tech.tasks.store'), [
                'owner_type' => $ticket->getMorphClass(),
                'owner_id' => $ticket->id,
                'return_to' => route('tech.tickets.show', $ticket),
                'title' => 'Call supplier',
                'description' => 'Ask about delivery.',
                'due_at' => now()->addDay()->format('Y-m-d H:i:s'),
                'estimated_minutes' => 40,
                'ticket_rate_key' => 'global:' . $rate->id,
            ])
            ->assertRedirect(route('tech.tickets.show', $ticket));

        $task = Task::query()->firstOrFail();

        $this->assertSame($ticket->getMorphClass(), $task->owner_type);
        $this->assertSame($ticket->id, $task->owner_id);
        $this->assertSame($ticket->queue_id, $task->queue_id);
        $this->assertSame($ticket->priority_id, $task->priority_id);
        $this->assertSame($ticket->owner_id, $task->assigned_to);
        $this->assertSame($category->id, $task->category_id);
        $this->assertSame(['VIP'], $task->tags()->pluck('name')->all());
        $this->assertSame(40, $task->estimated_minutes);
        $this->assertSame('global:' . $rate->id, $task->metadata['ticket_rate_key']);
        $this->assertSame('Quick task support', $task->metadata['ticket_rate_label']);
    }

    #[Test]
    public function full_task_editor_prefills_ticket_category_and_tags(): void
    {
        $defaults = app(EnsureTicketDefaults::class)->handle();
        $category = Category::query()->create([
            'name' => 'Network',
            'slug' => 'network-task-editor-prefill-test',
            'type' => Category::TYPE_TICKET,
            'is_active' => true,
        ]);
        $tag = Tag::query()->create([
            'name' => 'Escalated',
            'slug' => 'escalated-task-editor-prefill-test',
            'active' => true,
        ]);
        $ticket = Ticket::query()->create([
            'ticket_key' => 'TD-2026-123459',
            'queue_id' => $defaults['queue']->id,
            'ticket_type_id' => $defaults['type']->id,
            'type' => $defaults['type']->slug,
            'status_id' => $defaults['status']->id,
            'priority_id' => $defaults['priority']->id,
            'category_id' => $category->id,
            'owner_id' => $this->tech->id,
            'created_by' => $this->tech->id,
            'updated_by' => $this->tech->id,
            'channel' => 'manual',
            'subject' => 'Ticket category prefill',
            'is_unread' => false,
        ]);
        $ticket->tags()->syncWithPivotValues([$tag->id], ['module' => 'Ticket']);

        $this->actingAs($this->tech)
            ->get(route('tech.tasks.create', [
                'owner_type' => $ticket->getMorphClass(),
                'owner_id' => $ticket->id,
            ]))
            ->assertOk()
            ->assertSee('value="'.$category->id.'" selected', false)
            ->assertSee('Escalated');
    }

    #[Test]
    public function full_task_editor_accepts_quick_modal_prefill_from_query(): void
    {
        $defaults = app(EnsureTicketDefaults::class)->handle();
        $ticket = Ticket::query()->create([
            'ticket_key' => 'TD-2026-123462',
            'queue_id' => $defaults['queue']->id,
            'ticket_type_id' => $defaults['type']->id,
            'type' => $defaults['type']->slug,
            'status_id' => $defaults['status']->id,
            'priority_id' => $defaults['priority']->id,
            'owner_id' => $this->tech->id,
            'created_by' => $this->tech->id,
            'updated_by' => $this->tech->id,
            'channel' => 'manual',
            'subject' => 'Quick modal prefill',
            'is_unread' => false,
        ]);
        $rate = TimeRate::query()->create([
            'name' => 'Time without contract',
            'slug' => 'time-without-contract-query-prefill-test',
            'code' => 'TIME_WITHOUT_CONTRACT_QUERY_PREFILL_TEST',
            'rate_type' => 'labor',
            'unit' => 'hour',
            'amount_ex_vat' => 950,
            'currency' => 'NOK',
            'applies_without_contract' => true,
            'applies_with_contract' => false,
            'is_active' => true,
            'sort_order' => 10,
        ]);

        $this->actingAs($this->tech)
            ->get(route('tech.tasks.create', [
                'owner_type' => $ticket->getMorphClass(),
                'owner_id' => $ticket->id,
                'title' => 'Løs skriverdriver',
                'description' => 'Undersøk driverfeil og vurder tiltak.',
                'assigned_to' => $this->tech->id,
                'estimated_minutes' => 35,
                'ticket_rate_key' => 'global:' . $rate->id,
                'checklist_text' => "Sjekk driver\nTest utskrift",
            ]))
            ->assertOk()
            ->assertSee('value="Løs skriverdriver"', false)
            ->assertSee('Undersøk driverfeil og vurder tiltak.')
            ->assertSee('value="'.$this->tech->id.'" selected', false)
            ->assertSee('Sjekk driver')
            ->assertSee('Test utskrift');
    }

    #[Test]
    public function completing_ticket_owned_task_requires_ticket_time_data(): void
    {
        $defaults = app(EnsureTicketDefaults::class)->handle();

        $ticket = Ticket::query()->create([
            'ticket_key' => 'TD-2026-123457',
            'queue_id' => $defaults['queue']->id,
            'ticket_type_id' => $defaults['type']->id,
            'type' => $defaults['type']->slug,
            'status_id' => $defaults['status']->id,
            'priority_id' => $defaults['priority']->id,
            'owner_id' => $this->tech->id,
            'created_by' => $this->tech->id,
            'updated_by' => $this->tech->id,
            'channel' => 'manual',
            'subject' => 'Ticket task billing context',
            'is_unread' => false,
        ]);

        $task = app(StoreTask::class)->handle([
            'title' => 'Complete with billing',
        ], $this->tech, $ticket);

        $this->actingAs($this->tech)
            ->post(route('tech.tasks.complete', $task))
            ->assertSessionHasErrors(['work_date', 'minutes', 'rate_key', 'invoice_text']);

        $this->assertNull($task->fresh()->completed_at);
    }

    #[Test]
    public function completing_ticket_owned_task_registers_ticket_time_and_task_time(): void
    {
        $defaults = app(EnsureTicketDefaults::class)->handle();

        $ticket = Ticket::query()->create([
            'ticket_key' => 'TD-2026-123458',
            'queue_id' => $defaults['queue']->id,
            'ticket_type_id' => $defaults['type']->id,
            'type' => $defaults['type']->slug,
            'status_id' => $defaults['status']->id,
            'priority_id' => $defaults['priority']->id,
            'owner_id' => $this->tech->id,
            'created_by' => $this->tech->id,
            'updated_by' => $this->tech->id,
            'channel' => 'manual',
            'subject' => 'Ticket task billing registration',
            'is_unread' => false,
        ]);
        $rate = TimeRate::create([
            'name' => 'Task support rate',
            'slug' => 'task-support-rate-test',
            'code' => 'TASK_SUPPORT_RATE_TEST',
            'rate_type' => 'labor',
            'unit' => 'hour',
            'amount_ex_vat' => 900,
            'currency' => 'NOK',
            'applies_without_contract' => true,
            'applies_with_contract' => false,
            'is_active' => true,
            'sort_order' => 10,
        ]);

        $task = app(StoreTask::class)->handle([
            'title' => 'Register time on completion',
            'estimated_minutes' => 25,
        ], $this->tech, $ticket);

        $this->actingAs($this->tech)
            ->post(route('tech.tasks.complete', $task), [
                'work_date' => '2026-05-26',
                'minutes' => 25,
                'rate_key' => 'global:' . $rate->id,
                'invoice_text' => 'Completed assigned ticket task.',
                'note' => 'Done from task completion.',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertNotNull($task->fresh()->completed_at);
        $this->assertDatabaseHas('ticket_time_entries', [
            'ticket_id' => $ticket->id,
            'user_id' => $this->tech->id,
            'minutes' => 25,
            'billing_basis' => 'without_contract',
            'billing_status' => 'pending',
            'timebank_status' => 'pending',
            'time_rate_id' => $rate->id,
            'invoice_text' => 'Completed assigned ticket task.',
        ]);
        $this->assertDatabaseHas('task_time_entries', [
            'task_id' => $task->id,
            'user_id' => $this->tech->id,
            'source_type' => 'ticket_time_entry',
            'minutes' => 25,
            'billable' => true,
        ]);
        $this->assertSame(1, $ticket->events()->where('type', 'time_entry_added')->count());
    }

    #[Test]
    public function quick_create_can_create_task_owned_by_client_with_client_metadata(): void
    {
        $client = Client::factory()->create();

        $this->actingAs($this->tech)
            ->post(route('tech.tasks.store'), [
                'owner_type' => $client->getMorphClass(),
                'owner_id' => $client->id,
                'return_to' => route('tech.clients.show', $client),
                'title' => 'Review customer documentation',
            ])
            ->assertRedirect(route('tech.clients.show', $client));

        $task = Task::query()->firstOrFail();

        $this->assertSame($client->getMorphClass(), $task->owner_type);
        $this->assertSame($client->id, $task->owner_id);
        $this->assertSame($client->id, $task->client_id);
    }

    #[Test]
    public function task_form_can_assign_parent_task(): void
    {
        $parent = app(StoreTask::class)->handle([
            'title' => 'Parent task',
        ], $this->tech);

        $this->actingAs($this->tech)
            ->post(route('tech.tasks.store'), [
                'title' => 'Child from form',
                'parent_id' => $parent->id,
            ])
            ->assertRedirect();

        $task = Task::query()->where('title', 'Child from form')->firstOrFail();

        $this->assertSame($parent->id, $task->parent_id);
    }

    #[Test]
    public function ai_assist_returns_task_form_suggestions(): void
    {
        app(EnsureTaskDefaults::class)->handle();
        $client = Client::factory()->create();
        $queue = TicketQueue::query()->create([
            'name' => 'Projects',
            'slug' => 'projects-ai-task-test',
            'is_active' => true,
        ]);
        $priority = TicketPriority::query()->create([
            'name' => 'High',
            'slug' => 'high-ai-task-test',
            'level' => 2,
            'is_active' => true,
        ]);
        $category = Category::query()->create([
            'name' => 'Onboarding',
            'slug' => 'onboarding-ai-task-test',
            'is_active' => true,
        ]);
        $provider = AiProvider::query()->create([
            'name' => 'OpenAI test',
            'provider_key' => 'openai',
            'base_url' => 'https://api.openai.test/v1',
            'default_model' => 'gpt-test',
            'status' => 'active',
        ]);
        $provider->setSecret('api_key', 'test-key');
        $provider->save();
        AiAgent::query()->create([
            'ai_provider_id' => $provider->id,
            'name' => 'Task Assistant',
            'slug' => 'task-assistant',
            'instructions' => 'Suggest task fields.',
            'default_domains' => ['tasks'],
            'is_default' => true,
            'is_active' => true,
        ]);

        Http::fake([
            'api.openai.test/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'title' => 'Prepare onboarding checklist',
                            'description' => 'Prepare the onboarding work and confirm prerequisites.',
                            'queue_id' => $queue->id,
                            'priority_id' => $priority->id,
                            'category_id' => $category->id,
                            'estimated_minutes' => 45,
                            'tag_names' => ['Onboarding', 'Checklist'],
                            'checklist_items' => ['Confirm access', 'Prepare workstation'],
                        ]),
                    ],
                ]],
            ]),
        ]);

        $this->actingAs($this->tech)
            ->postJson(route('tech.tasks.ai-suggest'), [
                'title' => 'onboard thing',
                'description' => 'fix new person setup maybe',
                'client_id' => $client->id,
            ])
            ->assertOk()
            ->assertJsonPath('suggestions.title', 'Prepare onboarding checklist')
            ->assertJsonPath('suggestions.queue_id', $queue->id)
            ->assertJsonPath('suggestions.estimated_minutes', 45)
            ->assertJsonPath('suggestions.checklist_items.0', 'Confirm access');
    }

    #[Test]
    public function ai_assist_normalizes_ticket_rate_label_to_rate_key(): void
    {
        $defaults = app(EnsureTicketDefaults::class)->handle();
        $ticket = Ticket::query()->create([
            'ticket_key' => 'TD-2026-123460',
            'queue_id' => $defaults['queue']->id,
            'ticket_type_id' => $defaults['type']->id,
            'type' => $defaults['type']->slug,
            'status_id' => $defaults['status']->id,
            'priority_id' => $defaults['priority']->id,
            'owner_id' => $this->tech->id,
            'created_by' => $this->tech->id,
            'updated_by' => $this->tech->id,
            'channel' => 'manual',
            'subject' => 'Rate normalization',
            'is_unread' => false,
        ]);
        $rate = TimeRate::query()->create([
            'name' => 'Remote support',
            'slug' => 'remote-support-ai-rate-test',
            'code' => 'REMOTE_SUPPORT_AI_RATE_TEST',
            'rate_type' => 'labor',
            'unit' => 'hour',
            'amount_ex_vat' => 1000,
            'currency' => 'NOK',
            'applies_without_contract' => true,
            'applies_with_contract' => false,
            'is_active' => true,
            'sort_order' => 10,
        ]);
        $provider = AiProvider::query()->create([
            'name' => 'OpenAI test',
            'provider_key' => 'openai',
            'base_url' => 'https://api.openai.test/v1',
            'default_model' => 'gpt-test',
            'status' => 'active',
        ]);
        $provider->setSecret('api_key', 'test-key');
        $provider->save();
        AiAgent::query()->create([
            'ai_provider_id' => $provider->id,
            'name' => 'Task Assistant',
            'slug' => 'task-assistant-rate',
            'instructions' => 'Suggest task fields.',
            'default_domains' => ['tasks'],
            'is_default' => true,
            'is_active' => true,
        ]);

        Http::fake([
            'api.openai.test/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'title' => 'Remote support follow-up',
                            'description' => 'Complete remote support work.',
                            'ticket_rate_key' => 'Remote support',
                        ]),
                    ],
                ]],
            ]),
        ]);

        $this->actingAs($this->tech)
            ->postJson(route('tech.tasks.ai-suggest'), [
                'title' => 'remote help',
                'description' => 'fix thing remote',
                'ticket_id' => $ticket->id,
            ])
            ->assertOk()
            ->assertJsonPath('suggestions.ticket_rate_key', 'global:' . $rate->id);
    }

    #[Test]
    public function ai_assist_defaults_to_standard_time_rate_when_ai_rate_is_unclear(): void
    {
        $defaults = app(EnsureTicketDefaults::class)->handle();
        $ticket = Ticket::query()->create([
            'ticket_key' => 'TD-2026-123461',
            'queue_id' => $defaults['queue']->id,
            'ticket_type_id' => $defaults['type']->id,
            'type' => $defaults['type']->slug,
            'status_id' => $defaults['status']->id,
            'priority_id' => $defaults['priority']->id,
            'owner_id' => $this->tech->id,
            'created_by' => $this->tech->id,
            'updated_by' => $this->tech->id,
            'channel' => 'manual',
            'subject' => 'Printer driver',
            'is_unread' => false,
        ]);
        $standardRate = TimeRate::query()->create([
            'name' => 'Time without contract',
            'slug' => 'time-without-contract-ai-default-test',
            'code' => 'TIME_WITHOUT_CONTRACT_AI_DEFAULT_TEST',
            'rate_type' => 'labor',
            'unit' => 'hour',
            'amount_ex_vat' => 950,
            'currency' => 'NOK',
            'applies_without_contract' => true,
            'applies_with_contract' => false,
            'is_active' => true,
            'sort_order' => 20,
        ]);
        TimeRate::query()->create([
            'name' => 'Driving',
            'slug' => 'driving-ai-default-test',
            'code' => 'DRIVING_AI_DEFAULT_TEST',
            'rate_type' => 'travel',
            'unit' => 'hour',
            'amount_ex_vat' => 520,
            'currency' => 'NOK',
            'applies_without_contract' => true,
            'applies_with_contract' => false,
            'is_active' => true,
            'sort_order' => 10,
        ]);
        $provider = AiProvider::query()->create([
            'name' => 'OpenAI test',
            'provider_key' => 'openai',
            'base_url' => 'https://api.openai.test/v1',
            'default_model' => 'gpt-test',
            'status' => 'active',
        ]);
        $provider->setSecret('api_key', 'test-key');
        $provider->save();
        AiAgent::query()->create([
            'ai_provider_id' => $provider->id,
            'name' => 'Task Assistant',
            'slug' => 'task-assistant-rate-default',
            'instructions' => 'Suggest task fields.',
            'default_domains' => ['tasks'],
            'is_default' => true,
            'is_active' => true,
        ]);

        Http::fake([
            'api.openai.test/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'title' => 'Resolve Printer Driver Issue',
                            'description' => 'Investigate the printer driver issue.',
                            'ticket_rate_key' => 'Printer troubleshooting',
                        ]),
                    ],
                ]],
            ]),
        ]);

        $response = $this->actingAs($this->tech)
            ->postJson(route('tech.tasks.ai-suggest'), [
                'title' => 'Resolve Printer Driver Issue',
                'description' => 'Investigate the printer driver issue to determine if troubleshooting or replacement is required.',
                'ticket_id' => $ticket->id,
            ])
            ->assertOk();

        $selectedRateKey = $response->json('suggestions.ticket_rate_key');
        $this->assertNotNull($selectedRateKey);
        $this->assertSame('Time without contract', TimeRate::query()->find((int) str_replace('global:', '', $selectedRateKey))?->name);
    }

    #[Test]
    public function required_dependencies_block_completion(): void
    {
        $first = app(StoreTask::class)->handle(['title' => 'Order hardware'], $this->tech);
        $second = app(StoreTask::class)->handle(['title' => 'Install hardware'], $this->tech);

        TaskDependency::query()->create([
            'task_id' => $second->id,
            'depends_on_task_id' => $first->id,
            'dependency_type' => TaskDependency::TYPE_BLOCKS_COMPLETION,
            'is_required' => true,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Task has required dependencies that are not complete.');

        app(CompleteTask::class)->handle($second, $this->tech);
    }

    #[Test]
    public function child_tasks_block_parent_completion(): void
    {
        $parent = app(StoreTask::class)->handle(['title' => 'Prepare office'], $this->tech);
        app(StoreTask::class)->handle([
            'title' => 'Install access point',
            'parent_id' => $parent->id,
        ], $this->tech);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Task has child tasks that are not complete.');

        app(CompleteTask::class)->handle($parent, $this->tech);
    }
}
