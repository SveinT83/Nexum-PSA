<?php

namespace App\Modules\Risk\Support;

use App\Models\Risk\RiskAssessment;

/** One parent-first transaction contract for both browser actions and API commands. */
final class RiskMutation
{
    public function run(string $permission, ?int $assessmentId, callable $apply): mixed
    {
        return app(RiskMutex::class)->run(function () use ($permission, $assessmentId, $apply) {
            RiskPolicy::actor($permission);
            $assessment = $assessmentId ? RiskAssessment::whereKey($assessmentId)->lockForUpdate()->firstOrFail() : null;

            return $apply($assessment);
        });
    }

    public static function reopen(RiskAssessment $assessment): void
    {
        if (in_array($assessment->status, ['new', 'approved'], true)) {
            $assessment->update(['status' => 'in_progress']);
        }
    }
}
