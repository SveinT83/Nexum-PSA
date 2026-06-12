<?php

namespace App\Modules\Signal\Actions;

use App\Modules\Signal\Models\Signal;
use App\Modules\Signal\Models\SignalRule;

class ProcessSignalRules
{
    public function __construct(private readonly ExecuteSignalAction $actions)
    {
    }

    public function handle(Signal $signal): int
    {
        $executed = 0;

        SignalRule::query()
            ->where('is_active', true)
            ->orderBy('priority')
            ->orderBy('id')
            ->get()
            ->each(function (SignalRule $rule) use ($signal, &$executed): void {
                if (! $this->matches($signal, $rule->conditions ?? [])) {
                    return;
                }

                $results = [];
                $status = 'executed';
                $error = null;

                try {
                    foreach ((array) $rule->actions as $action) {
                        $results[] = $this->actions->handle($signal, $rule, (array) $action);
                    }
                } catch (\Throwable $e) {
                    $status = 'failed';
                    $error = $e->getMessage();
                }

                $signal->executions()->create([
                    'signal_rule_id' => $rule->id,
                    'status' => $status,
                    'actions' => $rule->actions ?? [],
                    'results' => $results,
                    'error' => $error,
                    'executed_at' => now(),
                ]);

                $executed++;
            });

        return $executed;
    }

    private function matches(Signal $signal, array $conditions): bool
    {
        foreach (['source_domain', 'signal_type', 'severity', 'status'] as $field) {
            if (! empty($conditions[$field]) && ! in_array($signal->{$field}, (array) $conditions[$field], true)) {
                return false;
            }
        }

        if (array_key_exists('min_confidence', $conditions) && $signal->confidence < (int) $conditions['min_confidence']) {
            return false;
        }

        if (array_key_exists('has_client', $conditions) && ((bool) $conditions['has_client']) !== filled($signal->client_id)) {
            return false;
        }

        if (array_key_exists('has_contact', $conditions) && ((bool) $conditions['has_contact']) !== filled($signal->contact_id)) {
            return false;
        }

        foreach ((array) ($conditions['payload_equals'] ?? []) as $key => $expected) {
            if (data_get($signal->payload ?? [], $key) !== $expected) {
                return false;
            }
        }

        foreach ((array) ($conditions['payload_contains'] ?? []) as $key => $expected) {
            $actual = data_get($signal->payload ?? [], $key);
            if (! is_scalar($actual) || ! str_contains(mb_strtolower((string) $actual), mb_strtolower((string) $expected))) {
                return false;
            }
        }

        return true;
    }
}
