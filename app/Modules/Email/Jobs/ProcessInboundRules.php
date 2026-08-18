<?php

namespace App\Modules\Email\Jobs;

use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Services\InboundEmailRuleEngine;
use App\Modules\Email\Services\InboundEmailSignalClassifier;
use App\Modules\Email\Services\PersonalEmailRuleEngine;
use App\Modules\Notification\Actions\DispatchInboundEmailNotification;
use App\Modules\Notification\DTOs\InboundEmailNotificationIntent;
use App\Modules\Notification\Services\InboundEmailNotificationFanoutReadiness;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use RuntimeException;

class ProcessInboundRules implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    public const MAX_AI_TIME_CAP_SECONDS = 45;

    /**
     * Missing on legacy serialized jobs means false. Only an enumerated live
     * ingestion or authorized manual reprocess may opt into provider writes.
     */
    public bool $allowProviderMutation = false;

    public int $aiTimeCapSeconds = self::MAX_AI_TIME_CAP_SECONDS;

    /** Reconciliation defers the final notification into a durable fanout. */
    public bool $deferInboundNotification = false;

    public function __construct(
        public int $emailMessageId,
        bool $allowProviderMutation = false,
        ?int $aiTimeCapSeconds = null,
        bool $deferInboundNotification = false,
    ) {
        $this->allowProviderMutation = $allowProviderMutation === true;
        $this->aiTimeCapSeconds = max(1, min(
            self::MAX_AI_TIME_CAP_SECONDS,
            $aiTimeCapSeconds ?? self::MAX_AI_TIME_CAP_SECONDS,
        ));
        $this->deferInboundNotification = $deferInboundNotification === true;
    }

    public function handle(
        InboundEmailRuleEngine $ruleEngine,
        InboundEmailSignalClassifier $classifier,
        PersonalEmailRuleEngine $personalRuleEngine,
        DispatchInboundEmailNotification $dispatchInboundEmailNotification,
    ): ?InboundEmailNotificationIntent {
        $readiness = app(InboundEmailNotificationFanoutReadiness::class);
        if (! $readiness->ready()) {
            // Deployments may load queue code before the additive schema is
            // applied. Stop before any rule/Ticket/Signal side effect so the
            // message can be safely resumed after the migration gate opens.
            if ($this->job !== null
                && (! isset($this->deferInboundNotification)
                    || $this->deferInboundNotification !== true)) {
                // The unique wait job is the durable ordinary-automation
                // intent. Provider mutation is deliberately not carried over
                // a long deployment wait and must be re-authorized manually.
                Bus::dispatch(new ResumeInboundRulesAfterFanoutReady($this->emailMessageId));

                return null;
            }

            throw new RuntimeException('inbound_notification_fanout_schema_not_ready');
        }

        $message = EmailMessage::find($this->emailMessageId);
        if (! $message) {
            return null;
        }

        // Hidden/pending reconciliation history has not crossed its durable
        // visibility boundary. Fail closed before ticket, signal, personal
        // rule, notification, or provider-operation side effects.
        if (! $message->hasActiveProviderPlacement()) {
            return null;
        }

        if ($message->ticket_id !== null) {
            return $this->completeNotification($message, $dispatchInboundEmailNotification);
        }

        if (! $ruleEngine->allowsInboundAutomation($message)) {
            $personalRuleEngine->process($message, $this->providerMutationAllowed());

            if ($fresh = $message->fresh()) {
                $message = $fresh;
            }

            return $this->completeNotification($message, $dispatchInboundEmailNotification);
        }

        if ($ruleEngine->processPreclassification($message, $this->providerMutationAllowed())) {
            if ($fresh = $message->fresh()) {
                return $this->completeNotification($fresh, $dispatchInboundEmailNotification);
            }

            return null;
        }

        $message->refresh();
        if ($message->ticket_id !== null) {
            return $this->completeNotification($message, $dispatchInboundEmailNotification);
        }

        $signal = $classifier->classifyAndRecord($message, $this->nestedAiTimeCapSeconds());

        if ($classifier->shouldStopTicketRouting($signal)) {
            $message->forceFill(['state' => 'archived'])->save();

            return null;
        }

        $ruleEngine->process($message, $this->providerMutationAllowed());

        if ($fresh = $message->fresh()) {
            return $this->completeNotification($fresh, $dispatchInboundEmailNotification);
        }

        return null;
    }

    private function completeNotification(
        EmailMessage $message,
        DispatchInboundEmailNotification $dispatch,
    ): ?InboundEmailNotificationIntent {
        if (isset($this->deferInboundNotification) && $this->deferInboundNotification === true) {
            return new InboundEmailNotificationIntent((int) $message->id);
        }

        $dispatch->handle($message);

        return null;
    }

    private function providerMutationAllowed(): bool
    {
        return isset($this->allowProviderMutation)
            && $this->allowProviderMutation === true;
    }

    private function nestedAiTimeCapSeconds(): int
    {
        return max(1, min(
            self::MAX_AI_TIME_CAP_SECONDS,
            $this->aiTimeCapSeconds ?? self::MAX_AI_TIME_CAP_SECONDS,
        ));
    }
}
