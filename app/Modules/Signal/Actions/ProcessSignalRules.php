<?php

namespace App\Modules\Signal\Actions;

use App\Modules\Signal\Models\Signal;
use App\Modules\Signal\Models\SignalRule;
use App\Modules\Signal\Models\SignalRuleExecution;
use Illuminate\Support\Arr;

class ProcessSignalRules
{
    private const SUCCESS_STATUSES = ['done', 'queued', 'skipped'];

    public function __construct(private readonly ExecuteSignalAction $actions)
    {
    }

    public function handle(Signal $signal): int
    {
        $executed = 0;

        foreach (SignalRule::query()->where('is_active', true)->orderBy('priority')->orderBy('id')->get() as $rule) {
            if (! $this->matches($signal, $rule->conditions ?? [])) {
                continue;
            }

            $execution = $this->executeRule($signal, $rule, (array) $rule->actions);
            $executed++;

            if ($execution->status === 'executed' && $rule->stop_processing) {
                break;
            }
        }

        return $executed;
    }

    public function retry(SignalRuleExecution $execution, bool $allActions = false): ?SignalRuleExecution
    {
        $root = $execution->rootExecution()->loadMissing(['signal', 'rule', 'retries']);
        if (! $root->signal || ! $root->rule) {
            return null;
        }

        $actions = (array) $root->actions;
        $indexes = array_keys($actions);

        if (! $allActions) {
            $successful = collect([$root, ...$root->retries])
                ->flatMap(fn (SignalRuleExecution $attempt) => $this->indexedResults($attempt))
                ->filter(fn (array $result): bool => in_array($result['status'] ?? null, self::SUCCESS_STATUSES, true))
                ->pluck('action_index')
                ->unique()
                ->all();
            $indexes = array_values(array_diff($indexes, $successful));
        }

        if ($indexes === []) {
            return null;
        }

        return $this->executeRule($root->signal, $root->rule, $actions, $root, $indexes);
    }

    public function executeRule(
        Signal $signal,
        SignalRule $rule,
        array $actions,
        ?SignalRuleExecution $retryOf = null,
        ?array $selectedIndexes = null,
    ): SignalRuleExecution {
        $selectedIndexes ??= array_keys($actions);
        $results = [];
        $error = null;
        $failed = false;

        foreach ($actions as $index => $action) {
            if (! in_array($index, $selectedIndexes, true)) {
                continue;
            }

            if ($failed) {
                $results[] = [
                    'action_index' => $index,
                    'type' => is_array($action) ? ($action['type'] ?? 'unknown') : 'unknown',
                    'status' => 'not_run',
                    'message' => 'Not run because an earlier action in this attempt failed.',
                ];
                continue;
            }

            try {
                $result = $this->actions->handle($signal, $rule, (array) $action, $index);
                $results[] = ['action_index' => $index, ...$result];
            } catch (\Throwable $exception) {
                $failed = true;
                $error = $exception->getMessage();
                $results[] = [
                    'action_index' => $index,
                    'type' => is_array($action) ? ($action['type'] ?? 'unknown') : 'unknown',
                    'status' => 'failed',
                    'message' => $exception->getMessage(),
                ];
            }
        }

        $root = $retryOf?->rootExecution();
        $attempt = $root ? ((int) $root->retries()->max('attempt') ?: 1) + 1 : 1;

        return $signal->executions()->create([
            'signal_rule_id' => $rule->id,
            'retry_of_execution_id' => $root?->id,
            'attempt' => $attempt,
            'status' => $failed ? 'failed' : 'executed',
            'actions' => $actions,
            'results' => $results,
            'error' => $error,
            'executed_at' => now(),
        ]);
    }

    private function indexedResults(SignalRuleExecution $execution): array
    {
        return collect((array) $execution->results)
            ->values()
            ->map(fn (mixed $result, int $index): array => is_array($result)
                ? ['action_index' => $result['action_index'] ?? $index, ...$result]
                : ['action_index' => $index, 'status' => 'unknown'])
            ->all();
    }

    private function matches(Signal $signal, array $conditions): bool
    {
        if (array_key_exists('groups', $conditions)) {
            return $this->matchesGroups($signal, $conditions);
        }

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

    private function matchesGroups(Signal $signal, array $definition): bool
    {
        $groups = (array) ($definition['groups'] ?? []);
        if ($groups === []) {
            return true;
        }

        $groupResults = collect($groups)->map(function (mixed $group) use ($signal): bool {
            if (! is_array($group)) {
                return false;
            }
            $rows = (array) ($group['conditions'] ?? []);
            if ($rows === []) {
                return true;
            }
            $results = collect($rows)->map(fn (mixed $row): bool => is_array($row) && $this->matchesRow($signal, $row));

            return ($group['match'] ?? 'all') === 'any' ? $results->contains(true) : ! $results->contains(false);
        });

        return ($definition['match'] ?? 'all') === 'any'
            ? $groupResults->contains(true)
            : ! $groupResults->contains(false);
    }

    private function matchesRow(Signal $signal, array $row): bool
    {
        $field = (string) ($row['field'] ?? '');
        $operator = (string) ($row['operator'] ?? 'equals');
        $expected = $row['value'] ?? null;
        $exists = true;

        if ($field === 'payload') {
            $path = (string) ($row['path'] ?? '');
            $exists = Arr::has($signal->payload ?? [], $path);
            $actual = data_get($signal->payload ?? [], $path);
        } elseif ($field === 'has_client') {
            $actual = filled($signal->client_id);
        } elseif ($field === 'has_contact') {
            $actual = filled($signal->contact_id);
        } else {
            $actual = $signal->{$field};
        }

        return match ($operator) {
            'equals' => (string) $actual === (string) $expected,
            'not_equals' => (string) $actual !== (string) $expected,
            'in' => in_array((string) $actual, array_map('strval', (array) $expected), true),
            'not_in' => ! in_array((string) $actual, array_map('strval', (array) $expected), true),
            'contains' => is_scalar($actual) && str_contains(mb_strtolower((string) $actual), mb_strtolower((string) $expected)),
            'not_contains' => ! is_scalar($actual) || ! str_contains(mb_strtolower((string) $actual), mb_strtolower((string) $expected)),
            'greater_or_equal' => is_numeric($actual) && (float) $actual >= (float) $expected,
            'less_or_equal' => is_numeric($actual) && (float) $actual <= (float) $expected,
            'greater' => is_numeric($actual) && (float) $actual > (float) $expected,
            'less' => is_numeric($actual) && (float) $actual < (float) $expected,
            'is_true' => (bool) $actual,
            'is_false' => ! (bool) $actual,
            'exists' => $exists,
            'missing' => ! $exists,
            default => false,
        };
    }
}
