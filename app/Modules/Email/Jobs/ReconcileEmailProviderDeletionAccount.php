<?php

namespace App\Modules\Email\Jobs;

use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Services\EmailAccountProviderRuntimeResolver;
use App\Modules\Email\Services\EmailProviderDeletionReconciler;
use App\Modules\Email\Services\EmailProviderDeletionSettings;
use App\Modules\Email\Services\EmailProviderInventoryScanner;
use App\Modules\Email\Support\EmailAccountProviderLock;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class ReconcileEmailProviderDeletionAccount implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $uniqueFor = 600;

    public int $tries = 40;

    public int $maxExceptions = 10;

    public string $queuedAt;

    public function __construct(
        public int $accountId,
        public int $maxFolders = EmailProviderInventoryScanner::DEFAULT_MAX_FOLDERS,
        public int $maxMessagesPerFolder = EmailProviderInventoryScanner::DEFAULT_MAX_MESSAGES_PER_FOLDER,
        public int $batchSize = EmailProviderInventoryScanner::DEFAULT_BATCH_SIZE,
        public int $graceDays = EmailProviderDeletionReconciler::DEFAULT_GRACE_DAYS,
        public ?int $providerBindingVersion = null,
    ) {
        // Constructor capture protects all new dispatches, while an older
        // serialized job keeps the null class default and is rejected safely.
        if ($this->providerBindingVersion === null) {
            $account = EmailAccount::query()->find($this->accountId);
            $this->providerBindingVersion = $account
                ? app(EmailAccountProviderRuntimeResolver::class)->captureBindingVersion($account)
                : null;
        }

        $this->queuedAt = now()->toIso8601String();
        $this->onQueue('email');
    }

    public function retryUntil(): DateTimeInterface
    {
        return Carbon::parse($this->queuedAt)->addMinutes(10);
    }

    public function uniqueId(): string
    {
        return 'email-provider-deletion-account:'.$this->accountId.':'.($this->providerBindingVersion ?? 'missing');
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            EmailAccountProviderLock::middleware($this->accountId, $this->timeout),
            (new WithoutOverlapping($this->uniqueId()))
                ->releaseAfter(30)
                ->expireAfter($this->timeout + 60),
        ];
    }

    public function handle(
        EmailProviderDeletionSettings $settings,
        EmailProviderDeletionReconciler $reconciler,
    ): void {
        if (! $settings->enabled()) {
            return;
        }

        $account = EmailAccount::query()->find($this->accountId);

        if (! $account || ! $account->is_active) {
            return;
        }

        if (! $this->providerBindingVersion || $this->providerBindingVersion < 1) {
            $reconciler->recordBindingBlocked(
                $account,
                app(EmailAccountProviderRuntimeResolver::class)->captureBindingVersion($account),
                'provider_binding_snapshot_missing',
            );

            return;
        }

        if (app(EmailAccountProviderRuntimeResolver::class)->bindingVersion($account)
            !== $this->providerBindingVersion) {
            $reconciler->recordBindingStale($account, $this->providerBindingVersion);

            return;
        }

        $reconciler->reconcileAccount(
            $account,
            $this->maxFolders,
            $this->maxMessagesPerFolder,
            $this->batchSize,
            $this->graceDays,
            $this->providerBindingVersion,
        );
    }
}
