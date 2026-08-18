<?php

namespace App\Modules\Email\Jobs;

use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Services\EmailAccountProviderRuntimeResolver;
use App\Modules\Email\Services\EmailProviderDeletionReconciler;
use App\Modules\Email\Services\EmailProviderDeletionSettings;
use App\Modules\Email\Services\EmailProviderInventoryScanner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DispatchEmailProviderDeletionReconciliation implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $uniqueFor = 600;

    public function __construct()
    {
        $this->onQueue('email');
    }

    public function uniqueId(): string
    {
        return 'email-provider-deletion-dispatch';
    }

    public function handle(EmailProviderDeletionSettings $settings): void
    {
        if (! $settings->enabled()) {
            return;
        }

        EmailAccount::query()
            ->where('is_active', true)
            ->select('id')
            ->orderBy('id')
            ->chunkById(100, function ($accounts): void {
                foreach ($accounts as $account) {
                    $providerBindingVersion = app(EmailAccountProviderRuntimeResolver::class)
                        ->captureBindingVersion($account);
                    ReconcileEmailProviderDeletionAccount::dispatch(
                        (int) $account->id,
                        EmailProviderInventoryScanner::DEFAULT_MAX_FOLDERS,
                        EmailProviderInventoryScanner::DEFAULT_MAX_MESSAGES_PER_FOLDER,
                        EmailProviderInventoryScanner::DEFAULT_BATCH_SIZE,
                        EmailProviderDeletionReconciler::DEFAULT_GRACE_DAYS,
                        $providerBindingVersion,
                    );
                }
            });
    }
}
