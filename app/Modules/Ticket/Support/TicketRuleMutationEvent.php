<?php

namespace App\Modules\Ticket\Support;

use InvalidArgumentException;

/**
 * Runtime-only normalized evidence for one authoritative Ticket mutation.
 *
 * Message bodies are deliberately excluded. The coordinator may sanitize
 * other exact before/after values before writing durable execution evidence.
 */
final readonly class TicketRuleMutationEvent
{
    private const FORBIDDEN_MESSAGE_BODY_KEYS = [
        'body',
        'body_html',
        'body_html_sanitized',
        'body_text',
        'content_html',
        'content_text',
        'message_body',
        'raw_body',
    ];

    /**
     * @param  list<string>  $changedFields
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  array<string, mixed>  $safeFacts
     * @param  array<string, mixed>  $classification
     */
    private function __construct(
        public int $ticketId,
        public string $eventKey,
        public array $changedFields,
        public array $before,
        public array $after,
        public array $safeFacts,
        public array $classification,
        public string $sourceChannel,
        public string $sourceAction,
        public string $deliveryIdentity,
        public ?string $relatedRecordType,
        public ?int $relatedRecordId,
        public ?string $correlationUuid,
        public ?string $causationUuid,
    ) {}

    /**
     * @param  list<string>  $changedFields
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  array<string, mixed>  $safeFacts
     * @param  array<string, mixed>  $classification
     */
    public static function make(
        int $ticketId,
        string $eventKey,
        array $changedFields,
        array $before,
        array $after,
        array $safeFacts,
        array $classification,
        string $sourceChannel,
        string $sourceAction,
        string $deliveryIdentity,
        ?string $relatedRecordType = null,
        ?int $relatedRecordId = null,
        ?string $correlationUuid = null,
        ?string $causationUuid = null,
    ): self {
        if ($ticketId < 1) {
            throw new InvalidArgumentException('A persisted Ticket is required for a normalized mutation event.');
        }

        $eventKey = self::identifier($eventKey, 120);
        if (! str_starts_with($eventKey, 'ticket.')) {
            throw new InvalidArgumentException('The normalized mutation event key must belong to the Ticket domain.');
        }

        $changedFields = collect($changedFields)
            ->filter(fn (mixed $field): bool => is_string($field) && trim($field) !== '')
            ->map(fn (string $field): string => self::identifier($field, 120))
            ->unique()
            ->sort()
            ->values()
            ->all();
        if ($changedFields === []) {
            throw new InvalidArgumentException('A normalized Ticket mutation event requires an actual changed field.');
        }

        self::assertNoMessageBody($before);
        self::assertNoMessageBody($after);
        self::assertNoMessageBody($safeFacts);
        self::assertNoMessageBody($classification);

        $deliveryIdentity = trim($deliveryIdentity);
        if ($deliveryIdentity === '') {
            throw new InvalidArgumentException('A normalized Ticket mutation event requires a delivery identity.');
        }

        if (($relatedRecordType === null) !== ($relatedRecordId === null)) {
            throw new InvalidArgumentException('Related record type and identifier must be supplied together.');
        }
        if ($relatedRecordType !== null && ($relatedRecordId < 1 || trim($relatedRecordType) === '')) {
            throw new InvalidArgumentException('The related record reference is invalid.');
        }

        return new self(
            ticketId: $ticketId,
            eventKey: $eventKey,
            changedFields: $changedFields,
            before: $before,
            after: $after,
            safeFacts: $safeFacts,
            classification: $classification,
            sourceChannel: self::identifier($sourceChannel, 64),
            sourceAction: self::identifier($sourceAction, 120),
            deliveryIdentity: mb_substr($deliveryIdentity, 0, 500),
            relatedRecordType: $relatedRecordType,
            relatedRecordId: $relatedRecordId,
            correlationUuid: self::nullableUuid($correlationUuid),
            causationUuid: self::nullableUuid($causationUuid),
        );
    }

    /** @return array<string, mixed> */
    public function coordinatorContext(): array
    {
        return [
            'event_key' => $this->eventKey,
            'changed_fields' => $this->changedFields,
            'before' => $this->before,
            'after' => $this->after,
            'facts' => $this->safeFacts,
            'classification' => $this->classification,
            'source_channel' => $this->sourceChannel,
            'source_action' => $this->sourceAction,
            'delivery_identity' => $this->deliveryIdentity,
            'related_record_type' => $this->relatedRecordType,
            'related_record_id' => $this->relatedRecordId,
            'correlation_uuid' => $this->correlationUuid,
            'causation_uuid' => $this->causationUuid,
        ];
    }

    private static function identifier(string $value, int $maximumLength): string
    {
        $normalized = preg_replace('/[^A-Za-z0-9_.:-]+/', '_', trim($value)) ?? '';
        $normalized = trim($normalized, '_.:-');
        if ($normalized === '') {
            throw new InvalidArgumentException('A normalized Ticket mutation identifier is required.');
        }

        return mb_substr($normalized, 0, $maximumLength);
    }

    private static function nullableUuid(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = strtolower(trim($value));
        if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $value) !== 1) {
            throw new InvalidArgumentException('The Ticket mutation correlation identifier is invalid.');
        }

        return $value;
    }

    private static function assertNoMessageBody(mixed $value): void
    {
        if (! is_array($value)) {
            return;
        }

        foreach ($value as $key => $item) {
            if (is_string($key) && in_array(strtolower($key), self::FORBIDDEN_MESSAGE_BODY_KEYS, true)) {
                throw new InvalidArgumentException('Raw message bodies are forbidden in normalized Ticket mutation events.');
            }

            self::assertNoMessageBody($item);
        }
    }
}
