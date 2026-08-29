<?php

namespace App\Modules\Integration\Actions;

use App\Modules\Integration\Models\RmmAlertOccurrence;
use App\Modules\Integration\Models\RmmAlertRule;
use App\Modules\Integration\Models\RmmAlertRuleExecution;
use App\Modules\Integration\Support\RmmAlertExecutionError;
use App\Modules\Integration\Support\RmmAlertProcessingLeaseLost;
use App\Modules\Integration\Support\RmmAlertRuleMatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class ProcessRmmAlertRules
{
    public function __construct(
        private readonly RmmAlertRuleMatcher $matcher,
        private readonly ExecuteRmmAlertRuleAction $actions,
    ) {}

    public function handle(RmmAlertOccurrence $occurrence): int
    {
        $leaseToken = $this->claim($occurrence->id);
        if ($leaseToken === null) {
            return 0;
        }

        $occurrence = RmmAlertOccurrence::query()
            ->with(['alert.asset.client', 'alert.asset.site'])
            ->findOrFail($occurrence->id);
        // Never adopt a newer worker's lease when reloading occurrence context.
        $occurrence->setAttribute('processing_token', $leaseToken);
        $matchedRules = 0;
        $hadFailures = false;
        $stopAll = false;

        try {
            $rules = RmmAlertRule::query()
                ->where('is_active', true)
                ->orderBy('priority')
                ->orderBy('id')
                ->get();

            foreach ($rules as $rule) {
                $evaluation = $this->matcher->evaluate($occurrence, (array) $rule->conditions);
                $execution = $this->startExecution($occurrence, $rule, $evaluation);

                if (! $evaluation['matched']) {
                    $this->completeExecution($execution, [
                        'status' => 'not_matched',
                        'completed_at' => now(),
                    ]);

                    continue;
                }

                $matchedRules++;
                $results = [];
                $failed = false;
                $ignored = false;

                foreach ((array) $rule->actions as $index => $action) {
                    if ($failed || $ignored) {
                        $results[] = [
                            'action_index' => $index,
                            'type' => is_array($action) ? ($action['type'] ?? 'unknown') : 'unknown',
                            'status' => 'not_run',
                            'message' => 'Not run because an earlier action stopped this rule.',
                        ];

                        continue;
                    }

                    try {
                        $result = $this->actions->handle(
                            $occurrence,
                            $rule,
                            $execution,
                            (array) $action,
                            (int) $index,
                        );
                        $results[] = ['action_index' => $index, ...$result];
                        $ignored = (bool) ($result['stop_all'] ?? false);
                        $stopAll = $stopAll || $ignored;
                    } catch (RmmAlertProcessingLeaseLost $exception) {
                        throw $exception;
                    } catch (Throwable $exception) {
                        $failed = true;
                        $hadFailures = true;
                        $results[] = [
                            'action_index' => $index,
                            'type' => is_array($action) ? ($action['type'] ?? 'unknown') : 'unknown',
                            'status' => 'failed',
                            'message' => RmmAlertExecutionError::summarize($exception),
                        ];
                    }
                }

                $this->completeExecution($execution, [
                    'status' => $failed ? 'failed' : ($ignored ? 'ignored' : 'completed'),
                    'action_results' => $results,
                    'error' => $failed
                        ? collect($results)->firstWhere('status', 'failed')['message'] ?? 'RMM action failed.'
                        : null,
                    'completed_at' => now(),
                ]);

                $performedAction = collect($results)->contains(
                    fn (array $result): bool => ($result['status'] ?? null) === 'done'
                );
                if ($stopAll || (! $failed && $performedAction && $rule->stop_processing)) {
                    break;
                }
            }

            $completed = RmmAlertOccurrence::query()
                ->whereKey($occurrence->id)
                ->where('processing_status', 'processing')
                ->whereNull('processed_at')
                ->where('processing_token', $occurrence->processing_token)
                ->update([
                    'processing_status' => $hadFailures ? 'completed_with_failures' : 'completed',
                    'processing_started_at' => null,
                    'processing_token' => null,
                    'processed_at' => now(),
                    'processing_error' => null,
                    'updated_at' => now(),
                ]);
            if ($completed === 0) {
                return $matchedRules;
            }

            return $matchedRules;
        } catch (RmmAlertProcessingLeaseLost) {
            // A stale worker lost ownership to a newer claim or terminal recovery.
            // The current owner is authoritative; never overwrite its audit state.
            return $matchedRules;
        } catch (Throwable $exception) {
            $error = RmmAlertExecutionError::summarize($exception);
            $this->failCurrentLease($occurrence, $error);

            throw $exception;
        }
    }

    private function claim(int $occurrenceId): ?string
    {
        return DB::transaction(function () use ($occurrenceId): ?string {
            $occurrence = RmmAlertOccurrence::query()->lockForUpdate()->findOrFail($occurrenceId);

            if ($occurrence->processed_at) {
                return null;
            }
            if ($occurrence->executions()->exists()) {
                return null;
            }
            if ($occurrence->processing_status === 'processing'
                && $occurrence->processing_started_at?->isAfter(now()->subMinutes(15))) {
                return null;
            }

            $token = (string) Str::uuid();
            $occurrence->forceFill([
                'processing_status' => 'processing',
                'processing_started_at' => now(),
                'processing_token' => $token,
                'processing_error' => null,
            ])->save();

            return $token;
        });
    }

    /** @param array{matched: bool, results: array<int, array<string, mixed>>} $evaluation */
    private function startExecution(
        RmmAlertOccurrence $occurrence,
        RmmAlertRule $rule,
        array $evaluation,
    ): RmmAlertRuleExecution {
        return DB::transaction(function () use ($occurrence, $rule, $evaluation): RmmAlertRuleExecution {
            $this->assertCurrentLeaseLocked($occurrence);

            return RmmAlertRuleExecution::query()->create([
                'rmm_alert_occurrence_id' => $occurrence->id,
                'rule_key' => $rule->rule_key,
                'rmm_alert_rule_id' => $rule->id,
                'rule_revision' => $rule->revision,
                'rule_name' => $rule->name,
                'matched' => $evaluation['matched'],
                'status' => 'evaluating',
                'rule_snapshot' => [
                    'rule_key' => $rule->rule_key,
                    'revision' => $rule->revision,
                    'name' => $rule->name,
                    'priority' => $rule->priority,
                    'stop_processing' => $rule->stop_processing,
                    'conditions' => $rule->conditions,
                    'actions' => $rule->actions,
                    'created_by' => $rule->created_by,
                    'updated_by' => $rule->updated_by,
                ],
                'condition_results' => $evaluation['results'],
                'action_results' => null,
                'error' => null,
                'started_at' => now(),
                'completed_at' => null,
            ]);
        });
    }

    /** @param array<string, mixed> $values */
    private function completeExecution(RmmAlertRuleExecution $execution, array $values): void
    {
        $updated = RmmAlertRuleExecution::query()
            ->whereKey($execution->id)
            ->where('status', 'evaluating')
            ->update([...$values, 'updated_at' => now()]);

        if ($updated === 0) {
            throw new RmmAlertProcessingLeaseLost('RMM processing lease is no longer active.');
        }
    }

    private function assertCurrentLeaseLocked(RmmAlertOccurrence $lease): RmmAlertOccurrence
    {
        $locked = RmmAlertOccurrence::query()->lockForUpdate()->findOrFail($lease->id);
        if (! $this->sameLease($locked, $lease)) {
            throw new RmmAlertProcessingLeaseLost('RMM processing lease is no longer active.');
        }

        return $locked;
    }

    private function failCurrentLease(RmmAlertOccurrence $lease, string $error): void
    {
        DB::transaction(function () use ($lease, $error): void {
            $locked = RmmAlertOccurrence::query()->lockForUpdate()->findOrFail($lease->id);
            if (! $this->sameLease($locked, $lease)) {
                return;
            }

            $hasExecutions = $locked->executions()->exists();
            if ($hasExecutions) {
                $locked->executions()->where('status', 'evaluating')->update([
                    'status' => 'failed',
                    'error' => $error,
                    'completed_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $locked->forceFill([
                'processing_status' => $hasExecutions ? 'completed_with_failures' : 'failed',
                'processing_started_at' => null,
                'processing_token' => null,
                'processed_at' => $hasExecutions ? now() : null,
                'processing_error' => $error,
            ])->save();
        });
    }

    private function sameLease(RmmAlertOccurrence $current, RmmAlertOccurrence $lease): bool
    {
        return $current->processing_status === 'processing'
            && $current->processed_at === null
            && is_string($current->processing_token)
            && $current->processing_token !== ''
            && is_string($lease->processing_token)
            && hash_equals($current->processing_token, $lease->processing_token);
    }
}
