<?php

namespace App\Modules\Email\Jobs;

use App\Modules\Notification\Services\InboundEmailNotificationFanoutReadiness;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Preserve an ordinary inbound-rule intent across a code-before-schema deploy.
 * The payload carries no content and deliberately drops provider-mutation
 * authority before the potentially long wait.
 */
final class ResumeInboundRulesAfterFanoutReady implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 30;

    /** Unlimited attempts are bounded by the immutable retryUntil deadline. */
    public int $tries = 0;

    public int $uniqueFor = 691200;

    public string $expiresAt;

    public function __construct(public int $emailMessageId)
    {
        $this->onQueue('email');
        $this->delay(60);
        $this->expiresAt = now()->addDays(7)->toIso8601String();
    }

    public function uniqueId(): string
    {
        return 'resume-inbound-rules-after-fanout-ready:'.$this->emailMessageId;
    }

    public function retryUntil(): DateTimeInterface
    {
        return Carbon::parse($this->expiresAt);
    }

    public function handle(InboundEmailNotificationFanoutReadiness $readiness): void
    {
        if (! $readiness->ready()) {
            $this->release(60);

            return;
        }

        ProcessInboundRules::dispatch($this->emailMessageId, false);
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('Inbound-rule schema wait requires operator retry.', [
            'email_message_id' => $this->emailMessageId,
            'queue_job_id' => $this->job?->getJobId(),
            'error_code' => 'inbound_rules_fanout_readiness_deadline_exceeded',
            'recovery_command' => 'notification:resume-inbound-rules-after-fanout-ready '
                .$this->emailMessageId,
        ]);
    }
}
