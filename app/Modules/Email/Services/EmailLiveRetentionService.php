<?php

namespace App\Modules\Email\Services;

use App\Modules\Email\Models\EmailLiveProjectionChange;
use App\Modules\Email\Models\EmailLiveProjectionDelivery;
use App\Modules\Email\Models\EmailLiveProjectionPublication;
use App\Modules\Email\Models\EmailLiveProjectionStream;
use Illuminate\Support\Facades\DB;

class EmailLiveRetentionService
{
    /**
     * Prune only a contiguous, terminal prefix from each durable stream.
     * Unfinished fanout, blocked evidence and unacknowledged user versions
     * deliberately stop that stream even when nominal limits are exceeded.
     *
     * @return array{changes: int, publications: int, deliveries: int}
     */
    public function prune(int $changeBudget = 100): array
    {
        $remaining = min(500, max(1, $changeBudget));
        $totals = ['changes' => 0, 'publications' => 0, 'deliveries' => 0];
        $hours = max(1, (int) config('email_live.retention_hours', 72));
        $retainedCount = max(1, (int) config('email_live.retention_changes_per_stream', 10_000));

        DB::table('email_live_projection_streams as stream')
            ->join('email_live_projection_changes as candidate', function ($join): void {
                $join->on('candidate.stream_id', '=', 'stream.id')
                    ->on('candidate.version', '=', 'stream.oldest_retained_version');
            })
            ->leftJoin('email_live_projection_publications as publication', function ($join): void {
                $join->on('publication.source_change_id', '=', 'candidate.id');
            })
            ->where('candidate.publication_status', EmailLiveProjectionChange::STATUS_SEALED)
            ->whereNotNull('candidate.retention_ready_at')
            ->where(function ($query) use ($hours, $retainedCount): void {
                $query->where('candidate.created_at', '<=', now()->subHours($hours))
                    ->orWhereRaw('candidate.version + ? <= stream.current_version', [$retainedCount]);
            })
            ->where(function ($query): void {
                $query->where('stream.stream_type', '<>', EmailLiveProjectionStream::TYPE_USER)
                    ->orWhereColumn('candidate.version', '<=', 'stream.acknowledged_version');
            })
            ->where(function ($query): void {
                $query->whereNull('publication.id')
                    ->orWhere(function ($query): void {
                        $query->where('publication.status', EmailLiveProjectionPublication::STATUS_SEALED)
                            ->where('publication.delivery_summary_status', 'sealed')
                            ->whereColumn('publication.delivery_cursor_id', 'publication.delivery_through_id');
                    });
            })
            ->orderBy('stream.id')
            ->limit(min(100, $remaining))
            ->pluck('stream.id')
            ->each(function (int $streamId) use (&$remaining, &$totals): void {
                while ($remaining > 0) {
                    $deleted = $this->pruneOne($streamId);
                    if ($deleted === null) {
                        break;
                    }

                    $remaining--;
                    foreach ($totals as $key => $_) {
                        $totals[$key] += $deleted[$key];
                    }
                }
            });

        return $totals;
    }

    /** @return array{changes: int, publications: int, deliveries: int}|null */
    private function pruneOne(int $streamId): ?array
    {
        return DB::transaction(function () use ($streamId): ?array {
            $stream = EmailLiveProjectionStream::query()->lockForUpdate()->find($streamId);
            if (! $stream || (int) $stream->oldest_retained_version > (int) $stream->current_version) {
                return null;
            }

            $change = EmailLiveProjectionChange::query()
                ->where('stream_id', $stream->id)
                ->where('version', $stream->oldest_retained_version)
                ->lockForUpdate()
                ->first();
            if (! $change || ! $this->eligible($stream, $change)) {
                return null;
            }

            $publication = EmailLiveProjectionPublication::query()
                ->where('source_change_id', $change->id)
                ->lockForUpdate()
                ->first();
            if ($publication && ! $this->terminalPublication($publication)) {
                return null;
            }

            $deliveryCount = 0;
            if ($publication) {
                $hasUnfinishedDelivery = EmailLiveProjectionDelivery::query()
                    ->where('publication_id', $publication->id)
                    ->whereNotIn('status', [
                        EmailLiveProjectionDelivery::STATUS_APPENDED,
                        EmailLiveProjectionDelivery::STATUS_SUPPRESSED,
                    ])
                    ->exists();
                if ($hasUnfinishedDelivery) {
                    return null;
                }

                $deliveryCount = EmailLiveProjectionDelivery::query()
                    ->where('publication_id', $publication->id)
                    ->delete();
                $publication->delete();
            }

            // The delete guard requires the advertised retention floor to
            // advance first; transaction rollback restores it on any failure.
            $stream->update([
                'oldest_retained_version' => (int) $change->version + 1,
            ]);
            $change->delete();

            return [
                'changes' => 1,
                'publications' => $publication ? 1 : 0,
                'deliveries' => $deliveryCount,
            ];
        });
    }

    private function eligible(
        EmailLiveProjectionStream $stream,
        EmailLiveProjectionChange $change,
    ): bool {
        if ($change->publication_status !== EmailLiveProjectionChange::STATUS_SEALED
            || ! $change->retention_ready_at) {
            return false;
        }

        if ($stream->stream_type === EmailLiveProjectionStream::TYPE_USER
            && (int) $change->version > (int) $stream->acknowledged_version) {
            return false;
        }

        $hours = max(1, (int) config('email_live.retention_hours', 72));
        $retainedCount = max(1, (int) config('email_live.retention_changes_per_stream', 10_000));
        $expired = $change->created_at?->lessThanOrEqualTo(now()->subHours($hours)) ?? false;
        $overCount = (int) $change->version <= max(
            0,
            (int) $stream->current_version - $retainedCount,
        );

        return $expired || $overCount;
    }

    private function terminalPublication(EmailLiveProjectionPublication $publication): bool
    {
        return $publication->status === EmailLiveProjectionPublication::STATUS_SEALED
            && $publication->delivery_summary_status === 'sealed'
            && (int) $publication->delivery_cursor_id === (int) $publication->delivery_through_id
            && (int) $publication->delivery_count
                === (int) $publication->delivery_appended_count
                    + (int) $publication->delivery_suppressed_count;
    }
}
