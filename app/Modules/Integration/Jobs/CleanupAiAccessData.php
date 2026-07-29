<?php

namespace App\Modules\Integration\Jobs;

use App\Modules\Integration\Models\AiAccessEvent;
use App\Modules\Integration\Models\AiDataEgressPolicy;
use App\Modules\Integration\Models\AiRetainedPayload;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CleanupAiAccessData implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        $policy = AiDataEgressPolicy::installation();

        AiRetainedPayload::query()->where('expires_at', '<=', now())->delete();
        AiAccessEvent::query()
            ->where('created_at', '<=', now()->subDays($policy->audit_retention_days))
            ->delete();
    }
}
