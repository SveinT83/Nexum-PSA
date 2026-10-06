<?php

namespace App\Modules\Workday\Queries;

use App\Models\Core\User;
use App\Modules\Workday\Models\Workday;
use App\Modules\Workday\Models\WorkdayRevision;

class ReadWorkday
{
    public function own(User $user, string $uuid): Workday
    {
        return Workday::query()->where('user_id', $user->id)->where('uuid', $uuid)
            ->where('expires_at', '>', now())->firstOrFail();
    }

    public function revision(?WorkdayRevision $revision): ?array
    {
        return $revision ? ['id' => $revision->uuid, 'version' => $revision->version, 'state' => $revision->state,
            'snapshot' => $revision->snapshot, 'origin' => $revision->origin,
            'correction_reason' => $revision->correction_reason, 'created_at' => $revision->created_at->toIso8601String()] : null;
    }

    public function serialize(Workday $day, ?User $viewer = null): array
    {
        $day->load(['currentRevision', 'confirmedRevision']);
        $viewer ??= auth()->user();

        $mapping = app(\App\Modules\Integration\Services\Tripletex\TripletexTimeMapping::class)->forUser($day->user_id);
        $sync = $mapping ? \App\Modules\DataExchange\Models\TripletexWorkdaySyncState::where('connection_id', $mapping['connection_id'])->where('user_id', $day->user_id)->where('work_date', $day->work_date)->first() : null;

        return ['time_sync' => $mapping ? ['status' => $mapping['enabled'] ? ($sync?->status ?? 'pending') : 'paused', 'error' => $sync?->error_code] : null,
            'id' => $day->uuid, 'work_date' => $day->work_date, 'timezone' => $day->timezone,
            'version' => $day->version, 'expires_at' => $day->expires_at->toIso8601String(),
            'reconciliation' => app(\App\Modules\Workday\Actions\ReconcileSources::class)->status($viewer, $day, $day->currentRevision->snapshot),
            'confirmed_reconciliation' => $day->confirmedRevision ? app(\App\Modules\Workday\Actions\ReconcileSources::class)->status($viewer, $day, $day->confirmedRevision->snapshot) : null,
            'current' => $this->revision($day->currentRevision),
            'confirmed' => $this->revision($day->confirmedRevision),
            'absence_warnings' => app(AbsenceImpact::class)->forWorkday($day, $day->currentRevision->snapshot)['warnings']];
    }
}
