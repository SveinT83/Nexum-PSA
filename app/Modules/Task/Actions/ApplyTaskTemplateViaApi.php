<?php

namespace App\Modules\Task\Actions;

use App\Models\Core\User;
use App\Modules\Task\Models\TaskTemplateGroup;
use App\Modules\Task\Models\TaskTemplateRun;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** API keys are actor-bound; the shared generator's durable failure/history semantics stay intact. */
class ApplyTaskTemplateViaApi
{
    public function handle(TaskTemplateGroup $template, User $actor, Model $owner, array $data): TaskTemplateRun
    {
        $key = 'api-task-template:'.$actor->id.':'.hash('sha256', $data['idempotency_key']);
        $fingerprint = hash('sha256', json_encode([
            'template_id' => $template->id, 'owner_type' => $owner->getMorphClass(), 'owner_id' => $owner->getKey(),
            'snapshot' => $data['expected_template_snapshot'], 'anchor_at' => $data['anchor_at'] ?? null,
            'assigned_to' => isset($data['assigned_to']) ? (int) $data['assigned_to'] : null,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if ($existing = TaskTemplateRun::query()->where('idempotency_key', $key)->first()) {
            return $this->replay($existing, $fingerprint, $actor);
        }
        $options = $data;
        $options['_expected_template_snapshot'] = $data['expected_template_snapshot'];
        $options['metadata'] = ['api_fingerprint' => $fingerprint, 'api_requested_by' => $actor->id];
        if (! empty($options['anchor_at'])) {
            $options['anchor_at'] = \Carbon\Carbon::parse($options['anchor_at']);
        }
        try {
            return app(ApplyTaskTemplate::class)->handle($template, $actor, $owner, 'manual', $key, $options);
        } catch (UniqueConstraintViolationException $exception) {
            $winner = TaskTemplateRun::query()->where('idempotency_key', $key)->first();
            if (! $winner) {
                throw $exception;
            }

            return $this->replay($winner, $fingerprint, $actor);
        }
    }

    private function replay(TaskTemplateRun $run, string $fingerprint, User $actor): TaskTemplateRun
    {
        if ((int) $run->actor_id !== (int) $actor->id || ! hash_equals((string) ($run->metadata['api_fingerprint'] ?? ''), $fingerprint)) {
            throw new ConflictHttpException('The template application key is reserved for a different request.');
        }
        if ($run->status !== TaskTemplateRun::STATUS_COMPLETED) {
            throw new ConflictHttpException('This template application is running or failed. Reconcile its generation history before retrying.');
        }

        return $run;
    }
}
