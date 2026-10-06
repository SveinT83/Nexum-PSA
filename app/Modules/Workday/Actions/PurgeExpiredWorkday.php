<?php

namespace App\Modules\Workday\Actions;

use App\Models\Core\User;
use App\Modules\Calendar\Actions\PurgeWorkdayAbsenceProjection;
use App\Modules\Notification\Actions\PurgeWorkdayReminderCopies;
use App\Modules\Workday\Models\Workday;
use App\Modules\Workday\Models\WorkdayAbsence;
use App\Modules\Workday\Models\WorkdayReminder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PurgeExpiredWorkday
{
    private const ROOTS = [
        'workdays' => Workday::class,
        'absences' => WorkdayAbsence::class,
        'reminders' => WorkdayReminder::class,
    ];

    /** Metadata only: no worker IDs, source labels, work dates, descriptions or absence categories. */
    public function preview(): array
    {
        $cutoff = CarbonImmutable::now('UTC');
        $counts = [];
        foreach (self::ROOTS as $kind => $model) {
            $counts[$kind] = $model::where('expires_at', '<=', $cutoff)->count();
        }
        $counts['detached_receipts'] = $this->detachedReceipts($cutoff)->count();
        $counts['notification_copies'] = app(PurgeWorkdayReminderCopies::class)->expiredOrOrphaned($cutoff)->count();

        $counts['diagnostic_copies'] = app(PurgeWorkdayDiagnostics::class)->count();

        $untracked = app(PurgeWorkdayReminderCopies::class)->untracked();

        return ['evaluated_at' => $cutoff->toIso8601String(), 'work_retention_years' => 3, 'absence_retention_years' => 3,
            'cleanup_enabled' => (bool) config('workday.retention_enabled', false), 'eligible' => $counts,
            'untracked_notification_copies' => $untracked,
            'restore_ready' => array_sum($counts) === 0 && $untracked === 0];
    }

    /** Trusted local command/scheduler actor; no HTTP purge endpoint and no employee impersonation. */
    public function handle(int $limit = 100): array
    {
        if ($limit < 1 || $limit > 200) {
            throw new InvalidArgumentException('workday_retention_limit_invalid');
        }
        $summary = ['enabled' => (bool) config('workday.retention_enabled', false), 'attempted' => 0, 'removed' => 0, 'blocked' => 0, 'reason_codes' => []];
        if (! $summary['enabled']) {
            return $summary;
        }
        $cutoff = CarbonImmutable::now('UTC');
        foreach (self::ROOTS as $kind => $model) {
            $ids = $model::where('expires_at', '<=', $cutoff)->orderBy('expires_at')->orderBy('id')
                ->limit(max(0, $limit - $summary['attempted']))->get(['id', 'user_id']);
            foreach ($ids as $candidate) {
                $this->attempt($summary, function () use ($model, $candidate, $kind, $cutoff) {
                    // Same lock order as mutations, confirmation, snooze and channel delivery.
                    User::whereKey($candidate->user_id)->lockForUpdate()->firstOrFail(['id']);
                    $row = $model::whereKey($candidate->id)->lockForUpdate()->first();
                    if (! $row || $row->expires_at->gt($cutoff)) {
                        return false;
                    }
                    if ($kind === 'workdays') {
                        $this->day($row);
                    } elseif ($kind === 'absences') {
                        $this->absence($row, $cutoff);
                    } else {
                        app(PurgeWorkdayReminderCopies::class)->forReminder($row, $cutoff);
                        DB::table('workday_reminder_deliveries')->where('reminder_id', $row->id)->delete();
                        $row->delete();
                    }

                    return true;
                });
            }
        }
        // Settings receipts have no workday; failed/partial restores may leave receipts without a source.
        $receipts = $this->detachedReceipts($cutoff)->orderBy('id')->limit(max(0, $limit - $summary['attempted']))->get(['id', 'actor_id']);
        foreach ($receipts as $receipt) {
            $this->attempt($summary, function () use ($receipt, $cutoff) {
                User::whereKey($receipt->actor_id)->lockForUpdate()->firstOrFail(['id']);

                return $this->detachedReceipts($cutoff)->where('id', $receipt->id)->delete() > 0;
            });
        }
        $copies = app(PurgeWorkdayReminderCopies::class)->expiredOrOrphaned($cutoff)
            ->orderBy('n.id')->limit(max(0, $limit - $summary['attempted']))->get(['n.id', 'n.notifiable_id']);
        foreach ($copies as $copy) {
            $this->attempt($summary, function () use ($copy, $cutoff) {
                // A deleted account may leave a generic Notification row; no source record is changed.
                User::whereKey($copy->notifiable_id)->lockForUpdate()->first(['id']);
                app(PurgeWorkdayReminderCopies::class)->purgeCopy($copy->id, $cutoff);

                return true;
            });
        }
        try {
            $removed = app(PurgeWorkdayDiagnostics::class)->handle(max(0, $limit - $summary['attempted']));
            $summary['attempted'] += $removed;
            $summary['removed'] += $removed;
        } catch (\Throwable) {
            $summary['blocked']++;
            $summary['reason_codes'][] = 'diagnostic_store_failed';
        }
        $summary['reason_codes'] = array_values(array_unique($summary['reason_codes']));

        return $summary;
    }

    private function detachedReceipts(CarbonImmutable $cutoff)
    {
        return DB::table('workday_mutation_receipts')->where('expires_at', '<=', $cutoff)
            ->where(fn ($q) => $q->whereNull('workday_id')->orWhereNotIn('workday_id', Workday::select('id')));
    }

    private function day(Workday $day): void
    {
        // Retention only removes local delivery evidence; it never calls the provider.
        DB::table('tripletex_workday_sync_states')->where('user_id', $day->user_id)->where('work_date', $day->work_date)->delete();
        $revisions = DB::table('workday_revisions')->where('workday_id', $day->id)->select('id');
        DB::table('workday_task_conversion_previews')->where('workday_id', $day->id)->delete();
        DB::table('workday_previews')->where('workday_id', $day->id)->delete();
        DB::table('workday_source_allocations')->whereIn('revision_id', $revisions)->delete();
        DB::table('workday_mutation_receipts')->where('workday_id', $day->id)->delete();
        $day->update(['current_revision_id' => null, 'confirmed_revision_id' => null]);
        DB::table('workday_revisions')->where('workday_id', $day->id)->delete();
        $day->delete();
    }

    private function absence(WorkdayAbsence $absence, CarbonImmutable $cutoff): void
    {
        app(PurgeWorkdayAbsenceProjection::class)->handle($absence, $cutoff);
        DB::table('workday_absence_receipts')->where('absence_id', $absence->id)->delete();
        DB::table('workday_absence_revisions')->where('absence_id', $absence->id)->delete();
        $absence->delete();
    }

    private function attempt(array &$summary, callable $operation): void
    {
        $summary['attempted']++;
        try {
            $summary['removed'] += DB::transaction($operation, 3) ? 1 : 0;
        } catch (\Throwable $exception) {
            // Never persist SQL bindings, employee identities or exception messages in operational output.
            $summary['blocked']++;
            $summary['reason_codes'][] = $exception instanceof \RuntimeException && $exception->getMessage() === 'workday_projection_mismatch'
                ? 'projection_integrity' : 'transaction_failed';
        }
    }
}
