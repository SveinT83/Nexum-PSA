<?php

namespace App\Modules\Email\Jobs;

use App\Modules\Email\Models\EmailRuleActionAttempt;
use App\Modules\Email\Models\EmailRuleExecutionAttempt;
use App\Modules\Email\Models\EmailRuleReprocessItem;
use App\Modules\Email\Models\EmailRuleReprocessRun;
use App\Modules\Email\Services\EmailRuleReprocessService;
use App\Modules\Email\Services\InboundEmailRuleEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProcessEmailRuleReprocessRun implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 30;

    public int $tries = 3;

    public function __construct(public int $runId)
    {
        $this->onQueue('email-rules');
    }

    public function handle(
        InboundEmailRuleEngine $engine,
        EmailRuleReprocessService $runs,
    ): void {
        $run = EmailRuleReprocessRun::query()->with(['rule', 'version', 'actor'])->find($this->runId);
        if (! $run || $run->status === EmailRuleReprocessRun::STATUS_CANCELLED || $run->finished_at) {
            return;
        }

        try {
            $runs->authorizeRun($run, $run->actor);
        } catch (Throwable) {
            $run->forceFill([
                'status' => EmailRuleReprocessRun::STATUS_FAILED,
                'failed_count' => $run->items()->count(),
                'finished_at' => now(),
            ])->save();

            return;
        }

        $run->forceFill([
            'status' => EmailRuleReprocessRun::STATUS_RUNNING,
            'started_at' => $run->started_at ?? now(),
        ])->save();

        $started = microtime(true);
        $itemIds = $run->items()
            ->where(function ($items): void {
                $items->where('status', 'previewed')
                    ->orWhere(function ($stale): void {
                        $stale->where('status', 'running')
                            ->where('started_at', '<', now()->subMinutes(2));
                    });
            })
            ->orderBy('id')
            ->limit(25)
            ->pluck('id');

        foreach ($itemIds as $itemId) {
            if ((microtime(true) - $started) >= 10 || $run->fresh()->status === EmailRuleReprocessRun::STATUS_CANCELLED) {
                break;
            }
            $item = $this->claimItem((int) $itemId);
            if (! $item) {
                continue;
            }
            $this->processItem($run, $item, $engine);
        }

        $run->refresh();
        if ($run->status === EmailRuleReprocessRun::STATUS_CANCELLED) {
            return;
        }

        $previewed = $run->items()->where('status', 'previewed')->exists();
        $running = $run->items()->where('status', 'running')->exists();
        $failed = $run->items()->where('status', 'failed')->count();
        $succeeded = $run->items()->whereIn('status', ['succeeded', 'skipped'])->count();
        $run->forceFill(['failed_count' => $failed, 'succeeded_count' => $succeeded])->save();

        if ($previewed || $running) {
            $next = self::dispatch($run->id);
            if (! $previewed) {
                $next->delay(now()->addMinutes(2));
            }

            return;
        }

        $run->forceFill([
            'status' => $failed === 0
                ? EmailRuleReprocessRun::STATUS_SUCCEEDED
                : ($succeeded > 0 ? EmailRuleReprocessRun::STATUS_PARTIAL : EmailRuleReprocessRun::STATUS_FAILED),
            'finished_at' => now(),
        ])->save();
    }

    private function claimItem(int $itemId): ?EmailRuleReprocessItem
    {
        return DB::transaction(function () use ($itemId): ?EmailRuleReprocessItem {
            $item = EmailRuleReprocessItem::query()->lockForUpdate()->find($itemId);
            if (! $item || ($item->status !== 'previewed'
                && ! ($item->status === 'running' && $item->started_at?->lt(now()->subMinutes(2))))) {
                return null;
            }

            $item->forceFill(['status' => 'running', 'started_at' => now()])->save();

            return $item->fresh(['message', 'placement']);
        }, 3);
    }

    private function processItem(
        EmailRuleReprocessRun $run,
        EmailRuleReprocessItem $item,
        InboundEmailRuleEngine $engine,
    ): void {
        if (! $item->message || ! $item->placement || ! $item->matched) {
            $item->forceFill([
                'status' => 'skipped',
                'reason_code' => $item->matched ? 'source_unavailable' : 'rule_not_matched',
                'finished_at' => now(),
            ])->save();

            return;
        }

        $item->forceFill(['status' => 'running', 'started_at' => now()])->save();
        $summary = [];
        $failed = false;

        foreach (array_values($run->version->actions_json ?? []) as $position => $action) {
            $type = (string) ($action['type'] ?? 'unknown');
            if ($failed) {
                $summary[] = compact('position', 'type') + ['status' => 'not_run', 'reason_code' => 'not_run_after_action_failure'];

                continue;
            }

            $logicalKey = hash('sha256', implode('|', [
                'email-rule-action', $run->email_rule_version_id, $item->email_message_id,
                $item->email_mailbox_placement_id, $position,
            ]));
            $existing = EmailRuleActionAttempt::query()
                ->where('logical_key', $logicalKey)
                ->whereIn('status', ['running', 'succeeded'])
                ->latest('id')
                ->first();
            if ($existing) {
                $status = $existing->status === 'succeeded' ? 'succeeded' : 'failed';
                $reason = $existing->status === 'succeeded' ? 'already_succeeded' : 'ambiguous_in_flight_action';
                $summary[] = compact('position', 'type', 'status') + ['reason_code' => $reason];
                $failed = $status === 'failed';

                continue;
            }

            $legacySuccess = $this->completedRuntimeAction($run, $item, $position);
            if ($legacySuccess) {
                $this->recordReusedSuccess($item, $run, $position, $action, $logicalKey, $legacySuccess->id);
                $summary[] = compact('position', 'type') + ['status' => 'succeeded', 'reason_code' => 'already_succeeded'];

                continue;
            }

            $attemptNumber = EmailRuleActionAttempt::query()->where('logical_key', $logicalKey)->count() + 1;
            $attempt = EmailRuleActionAttempt::query()->create([
                'email_rule_reprocess_item_id' => $item->id,
                'email_rule_version_id' => $run->email_rule_version_id,
                'email_message_id' => $item->email_message_id,
                'email_mailbox_placement_id' => $item->email_mailbox_placement_id,
                'action_position' => $position,
                'action_type' => $type,
                'action_snapshot_hash' => hash('sha256', json_encode($action, JSON_THROW_ON_ERROR)),
                'logical_key' => $logicalKey,
                'active_logical_key' => $logicalKey,
                'idempotency_key' => hash('sha256', implode('|', [$run->id, $item->id, $position, $attemptNumber])),
                'attempt_number' => $attemptNumber,
                'status' => 'running',
                'started_at' => now(),
            ]);

            $result = $engine->executePublishedVersionAction(
                $run->rule,
                $run->version,
                $item->message,
                $position,
                true,
            );
            $status = (string) ($result['status'] ?? 'failed');
            $succeeded = $status === EmailRuleExecutionAttempt::STATUS_SUCCEEDED;
            $attempt->forceFill([
                'status' => $status,
                'reason_code' => $result['reason'] ?? null,
                'result_json' => array_filter([
                    'remote_operation_id' => $result['remote_operation_id'] ?? null,
                    'remote_operation_status' => $result['remote_operation_status'] ?? null,
                ], fn ($value): bool => $value !== null),
                'active_logical_key' => $succeeded ? $logicalKey : null,
                'finished_at' => now(),
            ])->save();

            $summary[] = compact('position', 'type', 'status') + ['reason_code' => $result['reason'] ?? null];
            $failed = ! $succeeded;
        }

        $item->forceFill([
            'status' => $failed ? 'failed' : 'succeeded',
            'reason_code' => $failed ? 'one_or_more_actions_failed' : null,
            'action_summary_json' => $summary,
            'finished_at' => now(),
        ])->save();

        EmailRuleExecutionAttempt::query()->firstOrCreate(
            ['idempotency_key' => hash('sha256', 'email-rule-reprocess|'.$run->id.'|'.$item->id)],
            [
                'email_rule_id' => $run->email_rule_id,
                'email_rule_version_id' => $run->email_rule_version_id,
                'email_message_id' => $item->email_message_id,
                'email_mailbox_placement_id' => $item->email_mailbox_placement_id,
                'routing_phase' => $run->version->routing_phase,
                'status' => $failed
                    ? EmailRuleExecutionAttempt::STATUS_FAILED
                    : EmailRuleExecutionAttempt::STATUS_SUCCEEDED,
                'reason_code' => $failed ? 'one_or_more_actions_failed' : null,
                'matched' => true,
                'stop_processing' => $run->version->stop_processing,
                'conditions_json' => $run->version->conditions_json,
                'actions_json' => $run->version->actions_json,
                'action_results_json' => collect($summary)->map(fn (array $result): array => [
                    'position' => $result['position'],
                    'type' => $result['type'],
                    'status' => $result['status'],
                    'reason' => $result['reason_code'] ?? null,
                ])->values()->all(),
                'started_at' => $item->started_at,
                'finished_at' => now(),
            ],
        );
    }

    private function completedRuntimeAction(
        EmailRuleReprocessRun $run,
        EmailRuleReprocessItem $item,
        int $position,
    ): ?EmailRuleExecutionAttempt {
        return EmailRuleExecutionAttempt::query()
            ->where('email_rule_version_id', $run->email_rule_version_id)
            ->where('email_message_id', $item->email_message_id)
            ->where('email_mailbox_placement_id', $item->email_mailbox_placement_id)
            ->whereNotNull('finished_at')
            ->get()
            ->first(fn (EmailRuleExecutionAttempt $attempt): bool => collect($attempt->action_results_json ?? [])
                ->contains(fn (array $result): bool => (int) ($result['position'] ?? -1) === $position
                    && ($result['status'] ?? null) === EmailRuleExecutionAttempt::STATUS_SUCCEEDED));
    }

    /** @param array<string, mixed> $action */
    private function recordReusedSuccess(
        EmailRuleReprocessItem $item,
        EmailRuleReprocessRun $run,
        int $position,
        array $action,
        string $logicalKey,
        int $executionAttemptId,
    ): void {
        EmailRuleActionAttempt::query()->firstOrCreate(
            ['active_logical_key' => $logicalKey],
            [
                'email_rule_reprocess_item_id' => $item->id,
                'email_rule_version_id' => $run->email_rule_version_id,
                'email_message_id' => $item->email_message_id,
                'email_mailbox_placement_id' => $item->email_mailbox_placement_id,
                'action_position' => $position,
                'action_type' => (string) ($action['type'] ?? 'unknown'),
                'action_snapshot_hash' => hash('sha256', json_encode($action, JSON_THROW_ON_ERROR)),
                'logical_key' => $logicalKey,
                'idempotency_key' => hash('sha256', 'legacy|'.$executionAttemptId.'|'.$position),
                'attempt_number' => 1,
                'status' => 'succeeded',
                'reason_code' => 'already_succeeded',
                'result_json' => ['execution_attempt_id' => $executionAttemptId],
                'started_at' => now(),
                'finished_at' => now(),
            ],
        );
    }
}
