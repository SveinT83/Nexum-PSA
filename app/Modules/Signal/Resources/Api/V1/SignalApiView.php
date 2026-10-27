<?php

namespace App\Modules\Signal\Resources\Api\V1;

use App\Modules\Signal\Models\Signal;
use App\Modules\Signal\Models\SignalRule;
use App\Modules\Signal\Models\SignalRuleExecution;
use App\Modules\Signal\Support\SignalApiInput;

/** Explicit projections avoid exposing provider credentials and raw SQL exceptions. */
class SignalApiView
{
    public static function safe(mixed $value): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => &$item) {
                $item = is_string($key) && preg_match('/password|secret|authorization|credential|(^token$)|(_token$)|api_key|private_key/i', $key)
                    ? '[redacted]' : self::safe($item);
            }
        } elseif (is_string($value) && preg_match('~^https?://~i', $value)) {
            $value = preg_replace('~(https?://)[^/@]+:[^/@]+@~i', '$1[redacted]@', $value);
            $value = preg_replace('/([?&](?:token|key|secret|password|signature|access_token|api_key)=)[^&#]*/i', '$1[redacted]', $value);
        }

        return $value;
    }

    public static function signal(Signal $signal): array
    {
        $signal->loadMissing(['client', 'contact'])->loadCount('executions');

        return self::safe([
            'id' => $signal->id, 'source_domain' => $signal->source_domain,
            'source_type' => $signal->source_type, 'source_id' => $signal->source_id,
            'subject_type' => $signal->subject_type, 'subject_id' => $signal->subject_id,
            'client_id' => $signal->client_id, 'contact_id' => $signal->contact_id,
            'client_name' => $signal->client?->name, 'contact_name' => $signal->contact?->display_name,
            'signal_type' => $signal->signal_type, 'severity' => $signal->severity,
            'confidence' => $signal->confidence, 'status' => $signal->status,
            'summary' => $signal->summary, 'payload' => $signal->payload,
            'occurred_at' => $signal->occurred_at?->toISOString(),
            'created_at' => $signal->created_at?->toISOString(),
            'executions_count' => $signal->executions_count,
            'revision' => SignalApiInput::revision([$signal->fresh()->getAttributes(), $signal->executions()->orderBy('id')->get()->map->getAttributes()->all()]),
            'links' => ['self' => route('api.v1.signals.show', $signal), 'admin' => route('tech.admin.system.signals.show', $signal)],
        ]);
    }

    public static function rule(SignalRule $rule): array
    {
        $rule->loadCount('executions');

        return self::safe([
            'id' => $rule->id, 'name' => $rule->name, 'description' => $rule->description,
            'is_active' => (bool) $rule->is_active, 'stop_processing' => (bool) $rule->stop_processing,
            'priority' => (int) $rule->priority, 'conditions' => $rule->conditions ?? [],
            'actions' => $rule->actions ?? [], 'created_by' => $rule->created_by, 'updated_by' => $rule->updated_by,
            'created_at' => $rule->created_at?->toISOString(), 'updated_at' => $rule->updated_at?->toISOString(),
            'executions_count' => $rule->executions_count,
            'revision' => SignalApiInput::revision($rule->fresh()->getAttributes()),
        ]);
    }

    public static function execution(SignalRuleExecution $execution): array
    {
        $execution->loadMissing(['rule', 'retryOf', 'retries']);
        $results = [];
        foreach (array_values((array) $execution->results) as $index => $result) {
            // June-era attempts did not persist action_index; use the same fallback as web/retry.
            $result = ['action_index' => $index, 'type' => $execution->actions[$index]['type'] ?? 'unknown',
                'status' => 'unknown', ...(is_array($result) ? $result : [])];
            if ($result['status'] === 'failed') {
                $result['message'] = 'Action failed; review the controlled execution log.';
            }
            $results[] = $result;
        }

        return self::safe([
            'id' => $execution->id, 'signal_id' => $execution->signal_id,
            'signal_rule_id' => $execution->signal_rule_id, 'rule_name' => $execution->rule?->name,
            'retry_of_execution_id' => $execution->retry_of_execution_id,
            'attempt' => (int) $execution->attempt, 'status' => $execution->status,
            'actions' => $execution->actions ?? [], 'results' => $results,
            'error' => $execution->error ? 'Action failed; review the controlled execution log.' : null,
            'executed_at' => $execution->executed_at?->toISOString(),
            'retry_ids' => $execution->retries->pluck('id')->all(),
            'has_retryable_actions' => $execution->hasRetryableActions(),
        ]);
    }

    public static function page($page, callable $map): array
    {
        return ['data' => $page->getCollection()->map($map)->values()->all(),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(),
                'total' => $page->total(), 'last_page' => $page->lastPage()]];
    }
}
