<?php

namespace App\Modules\Email\Jobs;

use App\Modules\Email\Actions\ProjectHistoricalEmailReadBaseline;
use App\Modules\Email\Models\EmailProviderReconciliationItem;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

/**
 * Project one bounded, local-only read-for-me baseline page.
 *
 * The payload contains only a durable item ID. It never resolves a provider,
 * takes the distributed account provider lock, or carries message content.
 */
final class ProjectEmailProviderHistoricalReadBaseline implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 45;

    public int $tries = 10;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 900;

    private const LIFETIME_HOURS = 24;

    public function __construct(public readonly int $itemId)
    {
        $this->onQueue('email');
    }

    public function uniqueId(): string
    {
        return 'email-provider-reconciliation-historical-baseline:'.$this->itemId;
    }

    public function handle(ProjectHistoricalEmailReadBaseline $baselines): void
    {
        $claimToken = $baselines->claimReconciliationBatch($this->itemId);
        if ($claimToken === null) {
            return;
        }

        try {
            $result = $baselines->projectReconciliationBatch(
                $this->itemId,
                $claimToken,
            );
        } catch (Throwable) {
            try {
                $baselines->releaseReconciliationClaim(
                    $this->itemId,
                    $claimToken,
                    ProjectHistoricalEmailReadBaseline::FAILURE_PROJECTION,
                );
            } catch (Throwable) {
                // A hard database failure leaves a token-owned running claim.
                // The 75-second orphan threshold reclaims it safely.
            }

            // Sever the original Throwable: SQL bindings may include private
            // mailbox data and must not enter queue failure serialization.
            throw new RuntimeException(ProjectHistoricalEmailReadBaseline::FAILURE_PROJECTION);
        }

        if ($result === ProjectHistoricalEmailReadBaseline::RECONCILIATION_PENDING) {
            self::dispatch($this->itemId)->afterCommit();
        }
    }

    /**
     * A timeout or explicitly failed delivery is terminal for this item, but
     * keeps its placement hidden for a later reconciliation cycle to repair.
     */
    public function failed(?Throwable $exception): void
    {
        app(ProjectHistoricalEmailReadBaseline::class)->failReconciliation(
            $this->itemId,
            ProjectHistoricalEmailReadBaseline::FAILURE_PROJECTION,
        );
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [5, 15, 60];
    }

    /**
     * Successful pages may enqueue indefinitely many bounded successors, but
     * one item cannot retry forever after a deterministic local fault.
     */
    public function retryUntil(): DateTimeInterface
    {
        $firstAttempt = EmailProviderReconciliationItem::query()
            ->whereKey($this->itemId)
            ->value('historical_baseline_first_attempt_at');

        return $firstAttempt
            ? CarbonImmutable::parse($firstAttempt)->addHours(self::LIFETIME_HOURS)
            : CarbonImmutable::now()->addHours(self::LIFETIME_HOURS);
    }
}
