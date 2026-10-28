<?php

namespace App\Modules\Task\Actions;

use App\Models\Core\User;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskTimeEntry;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** The Task row lock serializes retries and the existing audit ledger records their result. */
class RegisterIdempotentTaskApiTime
{
    public function handle(Task $task, User $actor, array $data, ?array $rate): array
    {
        $fingerprint = hash('sha256', json_encode([
            'work_date' => $data['work_date'], 'minutes' => (int) $data['minutes'],
            'rate_key' => $data['rate_key'] ?? null, 'invoice_text' => $data['invoice_text'] ?? null, 'note' => $data['note'] ?? null,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return DB::transaction(function () use ($task, $actor, $data, $rate, $fingerprint) {
            $task = Task::query()->lockForUpdate()->findOrFail($task->id);
            app(\App\Modules\Task\Services\TaskApiScope::class)->assert($task, $actor);
            $existing = $task->activities()->where('type', 'api_time_entry_created')
                ->where('changes->idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                $entry = TaskTimeEntry::query()->where('task_id', $task->id)->find($existing->changes['time_entry_id'] ?? null);
                if (! $entry || (int) $existing->user_id !== (int) $actor->id
                    || ! hash_equals((string) ($existing->changes['fingerprint'] ?? ''), $fingerprint)) {
                    throw new ConflictHttpException('The idempotency key is reserved for a different or deleted Task time entry.');
                }

                return ['entry' => $entry, 'created' => false];
            }
            $entry = app(RegisterTaskTimeEntry::class)->handle($task, $actor, $data, $rate);
            $task->activities()->create([
                'user_id' => $actor->id, 'type' => 'api_time_entry_created', 'visibility' => Task::VISIBILITY_INTERNAL,
                'body' => 'Task API time registration confirmed.',
                'changes' => ['idempotency_key' => $data['idempotency_key'], 'fingerprint' => $fingerprint, 'time_entry_id' => $entry->id],
            ]);

            return ['entry' => $entry, 'created' => true];
        });
    }
}
