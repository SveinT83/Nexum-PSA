<?php

namespace App\Modules\Integration\Services;

use App\Modules\Integration\Models\AiWorkloadProfile;

class CoordinatorPseudonymizer
{
    public function alias(AiWorkloadProfile $workload, string $subjectType, int|string|null $subjectId): ?string
    {
        if ($subjectId === null || $subjectId === '') {
            return null;
        }

        $digest = hash_hmac('sha256', $workload->id.'|'.$subjectType.'|'.$subjectId, (string) config('app.key'));

        return match ($subjectType) {
            'technician' => 'tech_'.substr($digest, 0, 12),
            'ticket' => 'ticket_'.substr($digest, 0, 12),
            'task' => 'task_'.substr($digest, 0, 12),
            default => $subjectType.'_'.substr($digest, 0, 12),
        };
    }
}
