<?php

namespace App\Modules\Email\Services;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailMailboxAccessEvent;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

class MailboxAccessUseGuard
{
    public function __construct(
        private readonly ResolveMailboxAccessDecision $decisions,
        private readonly EmailMailboxAccessEventRecorder $events,
    ) {}

    /**
     * Reauthorize at the actual use boundary and durably record emergency use before returning
     * control to code that can disclose mailbox content.
     *
     * @throws AuthorizationException
     */
    public function authorize(
        User $actor,
        EmailAccount $account,
        string $operation,
        string $resourceType,
        ?int $resourceId = null,
        ?string $requestKey = null,
    ): MailboxAccessDecision {
        $decision = $this->decisions->resolve($actor, $account, $operation);

        if (! $decision->allowed) {
            $this->events->recordExpiredAtUse($decision);

            throw new AuthorizationException('This mailbox resource is not available.');
        }

        if ($decision->usesBreakGlass()) {
            $this->events->recordBreakGlassUse(
                decision: $decision,
                eventType: $this->eventType($operation, $resourceType),
                resourceType: $resourceType,
                resourceId: $resourceId,
                requestKey: $requestKey,
            );
        }

        return $decision;
    }

    private function eventType(string $operation, string $resourceType): string
    {
        return match ($operation) {
            ResolveMailboxAccessDecision::CONTENT_VIEW => match ($resourceType) {
                'mailbox' => EmailMailboxAccessEvent::TYPE_MAILBOX_VIEW,
                'message' => EmailMailboxAccessEvent::TYPE_MESSAGE_VIEW,
                default => throw new InvalidArgumentException('Content view requires a mailbox or message resource.'),
            },
            ResolveMailboxAccessDecision::SEARCH => EmailMailboxAccessEvent::TYPE_SEARCH_EXECUTION,
            ResolveMailboxAccessDecision::ATTACHMENT_DOWNLOAD => EmailMailboxAccessEvent::TYPE_ATTACHMENT_DOWNLOAD,
            ResolveMailboxAccessDecision::RAW_SOURCE => EmailMailboxAccessEvent::TYPE_RAW_SOURCE_VIEW,
            default => throw new InvalidArgumentException('Only explicit content-use operations may create break-glass audit events.'),
        };
    }
}
