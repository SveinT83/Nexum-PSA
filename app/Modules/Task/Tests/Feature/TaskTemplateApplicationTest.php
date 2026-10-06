<?php

namespace App\Modules\Task\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Core\User;
use App\Modules\Task\Actions\ApplyTaskTemplate;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskDependency;
use App\Modules\Task\Models\TaskTemplateChecklistItem;
use App\Modules\Task\Models\TaskTemplateDependency;
use App\Modules\Task\Models\TaskTemplateGroup;
use App\Modules\Task\Models\TaskTemplateItem;
use App\Modules\Task\Models\TaskTemplateRun;
use App\Modules\Taxonomy\Models\Tag;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TaskTemplateApplicationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_atomically_creates_a_group_with_context_checklists_tags_and_dependencies(): void
    {
        $actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $client = Client::factory()->create(['name' => 'Northwind']);
        $template = TaskTemplateGroup::query()->create(['name' => 'Monthly care', 'slug' => 'monthly-care']);
        $parent = TaskTemplateItem::query()->create([
            'template_group_id' => $template->id,
            'title' => 'Review {client} on {date}',
            'estimated_minutes' => 30,
            'due_offset_minutes' => 1440,
            'sort_order' => 10,
        ]);
        $child = TaskTemplateItem::query()->create([
            'template_group_id' => $template->id,
            'parent_id' => $parent->id,
            'title' => 'Document findings',
            'sort_order' => 20,
        ]);
        TaskTemplateChecklistItem::query()->create([
            'template_item_id' => $parent->id,
            'title' => 'Check backups',
            'sort_order' => 10,
        ]);
        TaskTemplateDependency::query()->create([
            'template_item_id' => $child->id,
            'depends_on_template_item_id' => $parent->id,
            'dependency_type' => TaskDependency::TYPE_BLOCKS_START,
            'is_required' => true,
        ]);
        $tag = Tag::query()->create(['name' => 'Routine', 'slug' => 'routine', 'active' => true]);
        $parent->tags()->syncWithPivotValues([$tag->id], ['module' => 'Task']);

        $run = app(ApplyTaskTemplate::class)->handle(
            $template,
            $actor,
            $client,
            'manual',
            'manual:test:1',
            ['anchor_at' => CarbonImmutable::parse('2026-09-03 09:00:00')],
        );

        $this->assertSame(TaskTemplateRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(2, $run->task_count);
        $tasks = Task::query()->where('task_template_run_id', $run->id)->orderBy('sort_order')->get();
        $this->assertSame('Review Northwind on 2026-09-03', $tasks[0]->title);
        $this->assertSame('2026-09-04 09:00:00', $tasks[0]->due_at?->format('Y-m-d H:i:s'));
        $this->assertSame($tasks[0]->id, $tasks[1]->parent_id);
        $this->assertSame(['Check backups'], $tasks[0]->checklistItems()->pluck('title')->all());
        $this->assertSame(['Routine'], $tasks[0]->tags()->pluck('name')->all());
        $this->assertDatabaseHas('task_dependencies', [
            'task_id' => $tasks[1]->id,
            'depends_on_task_id' => $tasks[0]->id,
            'dependency_type' => TaskDependency::TYPE_BLOCKS_START,
        ]);
    }

    #[Test]
    public function current_template_edits_apply_only_to_future_tasks(): void
    {
        $actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $template = TaskTemplateGroup::query()->create(['name' => 'Mutable', 'slug' => 'mutable']);
        $item = TaskTemplateItem::query()->create(['template_group_id' => $template->id, 'title' => 'Before']);

        $first = app(ApplyTaskTemplate::class)->handle($template, $actor, $actor, 'manual', 'mutable:1');
        $item->update(['title' => 'After']);
        $second = app(ApplyTaskTemplate::class)->handle($template->fresh(), $actor, $actor, 'manual', 'mutable:2');

        $this->assertSame('Before', $first->tasks()->firstOrFail()->title);
        $this->assertSame('After', $second->tasks()->firstOrFail()->title);
        $this->assertSame(2, Task::query()->count());
    }

    #[Test]
    public function completed_idempotency_key_returns_the_original_group(): void
    {
        $actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $template = TaskTemplateGroup::query()->create(['name' => 'Once', 'slug' => 'once']);
        TaskTemplateItem::query()->create(['template_group_id' => $template->id, 'title' => 'Only once']);

        $first = app(ApplyTaskTemplate::class)->handle($template, $actor, $actor, 'signal_rule', 'signal:7:action:1');
        $again = app(ApplyTaskTemplate::class)->handle($template, $actor, $actor, 'signal_rule', 'signal:7:action:1');

        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, Task::query()->count());
    }

    #[Test]
    public function cycles_fail_without_leaving_partial_tasks(): void
    {
        $actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $template = TaskTemplateGroup::query()->create(['name' => 'Invalid', 'slug' => 'invalid']);
        $first = TaskTemplateItem::query()->create(['template_group_id' => $template->id, 'title' => 'First']);
        $second = TaskTemplateItem::query()->create(['template_group_id' => $template->id, 'parent_id' => $first->id, 'title' => 'Second']);
        $first->update(['parent_id' => $second->id]);

        try {
            app(ApplyTaskTemplate::class)->handle($template, $actor, $actor, 'manual', 'invalid:cycle');
            $this->fail('Cycle validation should fail.');
        } catch (ValidationException) {
            $this->assertSame(0, Task::query()->count());
            $this->assertDatabaseHas('task_template_runs', [
                'idempotency_key' => 'invalid:cycle',
                'status' => TaskTemplateRun::STATUS_FAILED,
            ]);
        }
    }
}
