<?php

namespace App\Modules\Email\Services;

use App\Modules\Email\Models\EmailBreakGlassAccess;
use App\Modules\Email\Models\EmailMailboxAccessEvent;
use App\Modules\Email\Models\EmailMailboxDelegation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use InvalidArgumentException;

class EmailMailboxAccessEventRecorder
{
    private const REQUEST_WINDOW_SECONDS = 30;

    /** @var list<string> */
    private const RESOURCE_TYPES = ['mailbox', 'message', 'attachment', 'raw_source', 'search'];

    /** @var list<string> */
    private const METADATA_KEYS = [
        'operations',
        'starts_at',
        'expires_at',
        'duration_minutes',
        'revocation_source',
    ];

    public function recordDelegationCreated(EmailMailboxDelegation $delegation): EmailMailboxAccessEvent
    {
        return $this->record(
            idempotencySeed: 'delegation-created:'.$delegation->id,
            values: [
                'email_account_id' => $delegation->email_account_id,
                'actor_id' => $delegation->created_by,
                'affected_user_id' => $delegation->delegate_id,
                'email_mailbox_delegation_id' => $delegation->id,
                'event_type' => EmailMailboxAccessEvent::TYPE_DELEGATION_CREATED,
                'reason_code' => 'owner_delegation',
                'metadata_json' => [
                    'operations' => $this->delegationOperations($delegation),
                    'starts_at' => $delegation->starts_at?->utc()->toIso8601String(),
                    'expires_at' => $delegation->expires_at?->utc()->toIso8601String(),
                ],
            ],
        );
    }

    public function recordDelegationRevoked(EmailMailboxDelegation $delegation): EmailMailboxAccessEvent
    {
        return $this->record(
            idempotencySeed: 'delegation-revoked:'.$delegation->id,
            values: [
                'email_account_id' => $delegation->email_account_id,
                'actor_id' => $delegation->revoked_by,
                'affected_user_id' => $delegation->delegate_id,
                'email_mailbox_delegation_id' => $delegation->id,
                'event_type' => EmailMailboxAccessEvent::TYPE_DELEGATION_REVOKED,
                'reason_code' => 'owner_revocation',
                'metadata_json' => [],
            ],
        );
    }

    public function recordBreakGlassActivated(EmailBreakGlassAccess $access): EmailMailboxAccessEvent
    {
        return $this->record(
            idempotencySeed: 'break-glass-activated:'.$access->id,
            values: [
                'email_account_id' => $access->email_account_id,
                'actor_id' => $access->actor_id,
                'affected_user_id' => $access->account?->owner_id,
                'email_break_glass_access_id' => $access->id,
                'event_type' => EmailMailboxAccessEvent::TYPE_BREAK_GLASS_ACTIVATED,
                'reason_code' => 'emergency_activation',
                'metadata_json' => [
                    'operations' => $this->breakGlassOperations($access),
                    'starts_at' => $access->starts_at?->utc()->toIso8601String(),
                    'expires_at' => $access->expires_at?->utc()->toIso8601String(),
                    'duration_minutes' => $access->starts_at && $access->expires_at
                        ? $access->starts_at->diffInMinutes($access->expires_at)
                        : null,
                ],
            ],
        );
    }

    public function recordBreakGlassRevoked(
        EmailBreakGlassAccess $access,
        string $revocationSource,
    ): EmailMailboxAccessEvent {
        return $this->record(
            idempotencySeed: 'break-glass-revoked:'.$access->id,
            values: [
                'email_account_id' => $access->email_account_id,
                'actor_id' => $access->revoked_by,
                'affected_user_id' => $access->account?->owner_id,
                'email_break_glass_access_id' => $access->id,
                'event_type' => EmailMailboxAccessEvent::TYPE_BREAK_GLASS_REVOKED,
                'reason_code' => 'emergency_revocation',
                'metadata_json' => [
                    'revocation_source' => $revocationSource,
                ],
            ],
        );
    }

    public function recordExpiredAtUse(MailboxAccessDecision $decision): ?EmailMailboxAccessEvent
    {
        if ($decision->expiredBreakGlassAccessId !== null) {
            $access = EmailBreakGlassAccess::query()
                ->with('account:id,owner_id')
                ->find($decision->expiredBreakGlassAccessId);

            if (! $access) {
                return null;
            }

            return $this->record(
                idempotencySeed: 'break-glass-expired-at-use:'.$access->id.':'.$decision->actorId.':'.$decision->operation,
                values: [
                    'email_account_id' => $access->email_account_id,
                    'actor_id' => $decision->actorId,
                    'affected_user_id' => $access->account?->owner_id,
                    'email_break_glass_access_id' => $access->id,
                    'event_type' => EmailMailboxAccessEvent::TYPE_BREAK_GLASS_EXPIRED_AT_USE,
                    'operation' => $decision->operation,
                    'reason_code' => 'expired_at_use',
                    'metadata_json' => [],
                ],
            );
        }

        if ($decision->expiredDelegationId !== null) {
            $delegation = EmailMailboxDelegation::query()->find($decision->expiredDelegationId);

            if (! $delegation) {
                return null;
            }

            return $this->record(
                idempotencySeed: 'delegation-expired-at-use:'.$delegation->id.':'.$decision->actorId.':'.$decision->operation,
                values: [
                    'email_account_id' => $delegation->email_account_id,
                    'actor_id' => $decision->actorId,
                    'affected_user_id' => $delegation->delegate_id,
                    'email_mailbox_delegation_id' => $delegation->id,
                    'event_type' => EmailMailboxAccessEvent::TYPE_DELEGATION_EXPIRED_AT_USE,
                    'operation' => $decision->operation,
                    'reason_code' => 'expired_at_use',
                    'metadata_json' => [],
                ],
            );
        }

        return null;
    }

    public function recordBreakGlassUse(
        MailboxAccessDecision $decision,
        string $eventType,
        string $resourceType,
        ?int $resourceId,
        ?string $requestKey = null,
    ): EmailMailboxAccessEvent {
        if (! $decision->usesBreakGlass() || $decision->breakGlassAccessId === null) {
            throw new InvalidArgumentException('A current break-glass decision is required for a use event.');
        }

        if (! in_array($resourceType, self::RESOURCE_TYPES, true)) {
            throw new InvalidArgumentException('Unsupported mailbox audit resource type.');
        }

        $access = EmailBreakGlassAccess::query()
            ->with('account:id,owner_id')
            ->findOrFail($decision->breakGlassAccessId);
        $window = intdiv(now()->getTimestamp(), self::REQUEST_WINDOW_SECONDS);
        $requestIdentity = $requestKey !== null && trim($requestKey) !== ''
            ? hash('sha256', trim($requestKey))
            : (string) $window;

        return $this->record(
            idempotencySeed: implode(':', [
                'break-glass-use',
                $access->id,
                $decision->actorId,
                $decision->operation,
                $resourceType,
                $resourceId ?? 0,
                $requestIdentity,
            ]),
            values: [
                'email_account_id' => $decision->accountId,
                'actor_id' => $decision->actorId,
                'affected_user_id' => $access->account?->owner_id,
                'email_break_glass_access_id' => $access->id,
                'event_type' => $eventType,
                'operation' => $decision->operation,
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
                'reason_code' => 'break_glass_use',
                'metadata_json' => [],
            ],
        );
    }

    /**
     * Store only the fixed metadata fields defined by this service. User-controlled mail or search
     * content must never be passed to the access-event table.
     *
     * @param  array<string, mixed>  $values
     */
    private function record(string $idempotencySeed, array $values): EmailMailboxAccessEvent
    {
        $metadata = $this->sanitizeMetadata($values['metadata_json'] ?? []);
        $reasonCode = $values['reason_code'] ?? null;

        if ($reasonCode !== null
            && (! is_string($reasonCode) || ! preg_match('/\A[a-z0-9_:-]{1,80}\z/', $reasonCode))) {
            throw new InvalidArgumentException('Mailbox audit reason codes must be stable identifiers.');
        }

        $attributes = [
            ...Arr::except($values, ['metadata_json']),
            'metadata_json' => $metadata,
            'occurred_at' => CarbonImmutable::instance(now())->utc(),
        ];

        return EmailMailboxAccessEvent::query()->firstOrCreate(
            ['idempotency_key' => hash('sha256', $idempotencySeed)],
            $attributes,
        );
    }

    /** @param  array<string, mixed>  $metadata */
    private function sanitizeMetadata(array $metadata): array
    {
        $unknown = array_diff(array_keys($metadata), self::METADATA_KEYS);
        if ($unknown !== []) {
            throw new InvalidArgumentException('Mailbox access audit metadata contains an unsupported field.');
        }

        $clean = [];

        if (array_key_exists('operations', $metadata)) {
            if (! is_array($metadata['operations'])) {
                throw new InvalidArgumentException('Mailbox access audit operations must be a list.');
            }

            $clean['operations'] = collect($metadata['operations'])
                ->filter(fn (mixed $value): bool => is_string($value)
                    && in_array($value, ResolveMailboxAccessDecision::OPERATIONS, true))
                ->unique()
                ->sort()
                ->values()
                ->all();
        }

        foreach (['starts_at', 'expires_at'] as $key) {
            if (($metadata[$key] ?? null) !== null) {
                $clean[$key] = CarbonImmutable::parse((string) $metadata[$key])->utc()->toIso8601String();
            }
        }

        if (($metadata['duration_minutes'] ?? null) !== null) {
            $duration = (int) $metadata['duration_minutes'];
            if ($duration < 1 || $duration > EmailBreakGlassAccess::MAX_DURATION_MINUTES) {
                throw new InvalidArgumentException('Mailbox access audit duration is outside its allowed bound.');
            }

            $clean['duration_minutes'] = $duration;
        }

        if (($metadata['revocation_source'] ?? null) !== null) {
            $source = (string) $metadata['revocation_source'];
            if (! in_array($source, ['actor', 'owner', 'operator'], true)) {
                throw new InvalidArgumentException('Mailbox access audit revocation source is invalid.');
            }

            $clean['revocation_source'] = $source;
        }

        return $clean;
    }

    /** @return list<string> */
    private function delegationOperations(EmailMailboxDelegation $delegation): array
    {
        return array_keys(array_filter([
            MailboxAccess::VIEW => $delegation->can_view,
            MailboxAccess::ORGANIZE => $delegation->can_organize,
            MailboxAccess::SEND => $delegation->can_send,
            ResolveMailboxAccessDecision::RAW_SOURCE => $delegation->can_view_raw_source,
        ]));
    }

    /** @return list<string> */
    private function breakGlassOperations(EmailBreakGlassAccess $access): array
    {
        return array_keys(array_filter([
            ResolveMailboxAccessDecision::CONTENT_VIEW => $access->can_view_content,
            ResolveMailboxAccessDecision::SEARCH => $access->can_search,
            ResolveMailboxAccessDecision::ATTACHMENT_DOWNLOAD => $access->can_download_attachments,
            ResolveMailboxAccessDecision::RAW_SOURCE => $access->can_view_raw_source,
        ]));
    }
}
