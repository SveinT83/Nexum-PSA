<?php

namespace App\Modules\Email\Jobs;

use App\Models\Settings\CommonSetting;
use App\Modules\Email\Services\EmailProviderDeletionCleanupService;
use App\Modules\Email\Services\EmailProviderDeletionSettings;
use App\Modules\Email\Services\EmailRetentionEligibilityService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class CleanupEmailProviderDeletionCache implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $uniqueFor = 600;

    public function __construct(public int $limit = 100)
    {
        $this->onQueue('email');
    }

    public function uniqueId(): string
    {
        return 'email-provider-deletion-cleanup';
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->uniqueId()))
                ->dontRelease()
                ->expireAfter($this->timeout + 60),
        ];
    }

    public function handle(
        EmailProviderDeletionSettings $settings,
        EmailProviderDeletionCleanupService $cleanup,
        EmailRetentionEligibilityService $eligibility,
    ): void {
        if (! $settings->enabled()) {
            return;
        }

        $months = max(1, (int) (CommonSetting::query()
            ->where('type', 'emailhub')
            ->where('name', 'retention_months')
            ->value('value') ?? 24));

        $cleanup->cleanupDue($eligibility, $months, max(1, $this->limit));
    }
}
