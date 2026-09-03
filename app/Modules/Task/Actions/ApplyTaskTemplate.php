<?php

namespace App\Modules\Task\Actions;

use App\Models\Clients\Client;
use App\Models\Core\User;
use App\Modules\Task\Models\TaskDependency;
use App\Modules\Task\Models\TaskTemplateGroup;
use App\Modules\Task\Models\TaskTemplateRun;
use App\Modules\Ticket\Models\Ticket;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class ApplyTaskTemplate
{
    public function __construct(
        private readonly StoreTask $storeTask,
        private readonly ValidateTaskTemplateGraph $graphs,
    ) {}

    public function handle(
        TaskTemplateGroup $template,
        User $actor,
        Model $owner,
        string $triggerType,
        string $idempotencyKey,
        array $options = [],
    ): TaskTemplateRun {
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '' || mb_strlen($idempotencyKey) > 191) {
            throw ValidationException::withMessages(['idempotency_key' => 'Provide a stable idempotency key of at most 191 characters.']);
        }

        if ($existing = TaskTemplateRun::query()->where('idempotency_key', $idempotencyKey)->first()) {
            if ($existing->status === TaskTemplateRun::STATUS_COMPLETED) {
                return $existing->load('tasks');
            }
            throw ValidationException::withMessages(['template' => 'This template application has already been attempted.']);
        }

        $run = TaskTemplateRun::query()->create([
            'template_group_id' => $template->id,
            'actor_id' => $actor->id,
            'trigger_type' => $triggerType,
            'source_type' => $options['source_type'] ?? null,
            'source_id' => $options['source_id'] ?? null,
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getKey(),
            'template_name' => $template->name,
            'template_updated_at' => $template->updated_at,
            'idempotency_key' => $idempotencyKey,
            'status' => TaskTemplateRun::STATUS_RUNNING,
            'scheduled_for' => $options['scheduled_for'] ?? null,
            'started_at' => now(),
            'metadata' => $options['metadata'] ?? null,
        ]);

        try {
            return DB::transaction(function () use ($run, $template, $actor, $owner, $options): TaskTemplateRun {
                $locked = TaskTemplateGroup::query()->lockForUpdate()->findOrFail($template->id);
                if (! $locked->is_active) {
                    throw ValidationException::withMessages(['template' => 'The selected Task template is inactive.']);
                }

                $items = $locked->allItems()->with(['checklistItems', 'dependencies', 'tags'])->get();
                $this->graphs->handle($items);
                $created = [];
                $anchor = $this->anchor($options['anchor_at'] ?? null);

                foreach ($this->parentOrdered($items) as $item) {
                    $task = $this->storeTask->handle([
                        'parent_id' => $item->parent_id ? $created[(int) $item->parent_id]->id : null,
                        'title' => $this->render((string) $item->title, $owner, $anchor),
                        'description' => $this->render($item->description, $owner, $anchor),
                        'status_id' => $item->status_id,
                        'queue_id' => $item->queue_id,
                        'priority_id' => $item->priority_id,
                        'category_id' => $item->category_id,
                        'assigned_to' => $options['assigned_to'] ?? $item->assigned_to,
                        'estimated_minutes' => $item->estimated_minutes,
                        'due_at' => $this->offset($anchor, $options['due_offset_minutes'] ?? $item->due_offset_minutes),
                        'scheduled_start_at' => $this->offset($anchor, $item->scheduled_start_offset_minutes),
                        'scheduled_end_at' => $this->offset($anchor, $item->scheduled_end_offset_minutes),
                        'blocks_owner_completion' => $item->blocks_owner_completion,
                        'sort_order' => $item->sort_order,
                        'source_type' => $run->trigger_type,
                        'source_id' => $run->source_id,
                        'template_group_id' => $locked->id,
                        'template_item_id' => $item->id,
                        'task_template_run_id' => $run->id,
                        'checklist' => $item->checklistItems->map->only(['title', 'description', 'sort_order'])->all(),
                        'metadata' => ['task_template_run_id' => $run->id],
                    ], $actor, $owner);
                    $task->tags()->syncWithPivotValues($item->tags->pluck('id')->all(), ['module' => 'Task']);
                    $created[(int) $item->id] = $task;
                }

                foreach ($items as $item) {
                    foreach ($item->dependencies as $dependency) {
                        TaskDependency::query()->create([
                            'task_id' => $created[(int) $item->id]->id,
                            'depends_on_task_id' => $created[(int) $dependency->depends_on_template_item_id]->id,
                            'dependency_type' => $dependency->dependency_type,
                            'is_required' => $dependency->is_required,
                        ]);
                    }
                }

                $first = reset($created);
                $run->update([
                    'client_id' => $first?->client_id,
                    'site_id' => $first?->site_id,
                    'work_context_id' => $first?->work_context_id,
                    'status' => TaskTemplateRun::STATUS_COMPLETED,
                    'task_count' => count($created),
                    'completed_at' => now(),
                ]);

                return $run->fresh('tasks');
            });
        } catch (Throwable $exception) {
            $run->forceFill([
                'status' => TaskTemplateRun::STATUS_FAILED,
                'failure_reason' => $exception instanceof ValidationException
                    ? Str::limit((string) collect($exception->errors())->flatten()->first(), 1000)
                    : 'Task template generation failed.',
                'completed_at' => now(),
            ])->save();
            throw $exception;
        }
    }

    private function parentOrdered(Collection $items): array
    {
        $remaining = $items->keyBy(fn ($item): int => (int) $item->id);
        $ordered = [];
        while ($remaining->isNotEmpty()) {
            $ready = $remaining->filter(fn ($item): bool => ! $item->parent_id || isset($ordered[(int) $item->parent_id]));
            if ($ready->isEmpty()) {
                throw ValidationException::withMessages(['template' => 'Task template parent relationships cannot be resolved.']);
            }
            foreach ($ready->sortBy([['sort_order', 'asc'], ['id', 'asc']]) as $id => $item) {
                $ordered[(int) $id] = $item;
                $remaining->forget($id);
            }
        }

        return $ordered;
    }

    private function anchor(mixed $value): CarbonInterface
    {
        return $value instanceof CarbonInterface ? $value->copy() : now();
    }

    private function offset(CarbonInterface $anchor, mixed $minutes): ?CarbonInterface
    {
        return $minutes === null ? null : $anchor->copy()->addMinutes((int) $minutes);
    }

    private function render(?string $value, Model $owner, CarbonInterface $anchor): ?string
    {
        if ($value === null) {
            return null;
        }
        $client = $owner instanceof Client ? $owner : ($owner instanceof Ticket ? $owner->client : null);

        return strtr($value, [
            '{client}' => (string) ($client?->name ?? ''),
            '{ticket.key}' => $owner instanceof Ticket ? (string) $owner->ticket_key : '',
            '{ticket.subject}' => $owner instanceof Ticket ? (string) $owner->subject : '',
            '{date}' => $anchor->toDateString(),
        ]);
    }
}
