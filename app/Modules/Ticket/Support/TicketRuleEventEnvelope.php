<?php

namespace App\Modules\Ticket\Support;

use App\Models\Core\User;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Services\TicketRuleAuditSanitizer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

final readonly class TicketRuleEventEnvelope
{
    /**
     * @param  list<string>  $changedFields
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  array<string, mixed>  $facts  Runtime-only facts; never persisted directly.
     */
    public function __construct(
        public int $ticketId,
        public string $eventKey,
        public string $sourceChannel,
        public string $sourceAction,
        public array $changedFields,
        public array $before,
        public array $after,
        public array $facts,
        public ?string $initiatorType,
        public ?int $initiatorId,
        public int $automationActorId,
        public string $correlationUuid,
        public ?string $causationUuid,
        public ?int $parentEventId,
        public ?int $parentActionResultId,
        public int $chainDepth,
        public CarbonImmutable $occurredAt,
        public string $fingerprint,
        public string $idempotencyKey,
    ) {}

    /** @param array<string, mixed> $context */
    public static function created(
        Ticket $ticket,
        array $context,
        ?User $initiator,
        User $automationActor,
        ?string $correlationUuid = null,
        ?string $causationUuid = null,
    ): self {
        $correlationUuid = self::uuid($correlationUuid) ?? (string) Str::uuid();
        $causationUuid = self::uuid($causationUuid);
        $sourceChannel = self::identifier($context['channel'] ?? $ticket->channel, 'manual', 64);
        $sourceAction = self::identifier($context['_source_action'] ?? null, 'StoreTicket', 120);
        $initiatorType = $initiator ? 'user' : self::nullableIdentifier($context['_initiator_type'] ?? null, 80);
        $initiatorId = $initiator?->id ?? self::positiveInteger($context['_initiator_id'] ?? null);
        if ($initiatorType === null) {
            $initiatorId = null;
        }
        $deliveryKey = is_scalar($context['_delivery_key'] ?? null) ? trim((string) $context['_delivery_key']) : null;

        if ($deliveryKey === '') {
            $deliveryKey = null;
        }

        $runtimeContext = Arr::except($context, [
            '_source_action',
            '_initiator_type',
            '_initiator_id',
            '_delivery_key',
            '_created_by_id',
            '_skip_initial_description_note',
            '_sla_planned_start_at',
            '_workflow_version_id',
        ]);
        $sanitizer = app(TicketRuleAuditSanitizer::class);
        $tagIds = $ticket->tags()
            ->pluck('tags.id')
            ->map(fn ($id): int => (int) $id)
            ->sort()
            ->values()
            ->all();
        $after = [
            'ticket_key' => $ticket->ticket_key,
            'ticket_type_id' => $ticket->ticket_type_id,
            'queue_id' => $ticket->queue_id,
            'status_id' => $ticket->status_id,
            'priority_id' => $ticket->priority_id,
            'sla_id' => $ticket->sla_id,
            'category_id' => $ticket->category_id,
            'client_id' => $ticket->client_id,
            'site_id' => $ticket->site_id,
            'contact_id' => $ticket->contact_id,
            'asset_id' => $ticket->asset_id,
            'impact' => $ticket->impact,
            'urgency' => $ticket->urgency,
            'owner_id' => $ticket->owner_id,
            'tag_ids' => $tagIds,
            'channel' => $ticket->channel,
            'workflow_id' => $ticket->workflow_id,
            'workflow_version_id' => $ticket->workflow_version_id,
            'workflow_state_key' => $ticket->workflow_state_key,
            'subject' => $context['subject'] ?? $ticket->subject,
            'description' => $context['description'] ?? $ticket->description,
        ];
        $safeAfter = $sanitizer->map($after);
        $fingerprint = TicketRuleStableJson::checksum([
            'ticket_id' => (int) $ticket->id,
            'event_key' => TicketRuleDefinitionRegistry::TRIGGER_CREATED,
            'source_channel' => $sourceChannel,
            'source_action' => $sourceAction,
            'changed_fields' => ['created'],
            'before' => [],
            'after' => $safeAfter,
            'initiator_type' => $initiatorType,
            'initiator_id' => $initiatorId,
            'chain_depth' => 0,
        ]);
        // The implicit identity must survive Ticket mutations made by the first
        // delivery. Callers with a transport identity may provide the explicit key.
        $idempotencyKey = TicketRuleStableJson::checksum([
            'ticket_id' => (int) $ticket->id,
            'event_key' => TicketRuleDefinitionRegistry::TRIGGER_CREATED,
            'delivery_identity' => $deliveryKey === null ? 'canonical-created-event' : hash('sha256', $deliveryKey),
        ]);

        return new self(
            ticketId: (int) $ticket->id,
            eventKey: TicketRuleDefinitionRegistry::TRIGGER_CREATED,
            sourceChannel: $sourceChannel,
            sourceAction: $sourceAction,
            changedFields: ['created'],
            before: [],
            after: $safeAfter,
            facts: array_merge($runtimeContext, [
                'ticket_id' => (int) $ticket->id,
                'ticket_key' => $ticket->ticket_key,
                'ticket_type_id' => $ticket->ticket_type_id,
                'queue_id' => $ticket->queue_id,
                'status_id' => $ticket->status_id,
                'priority_id' => $ticket->priority_id,
                'sla_id' => $ticket->sla_id,
                'category_id' => $ticket->category_id,
                'client_id' => $ticket->client_id,
                'site_id' => $ticket->site_id,
                'contact_id' => $ticket->contact_id,
                'asset_id' => $ticket->asset_id,
                'impact' => $ticket->impact,
                'urgency' => $ticket->urgency,
                'owner_id' => $ticket->owner_id,
                'tag_ids' => $tagIds,
                'channel' => $ticket->channel,
                'workflow_id' => $ticket->workflow_id,
                'workflow_version_id' => $ticket->workflow_version_id,
                'workflow_state_key' => $ticket->workflow_state_key,
                'subject' => $context['subject'] ?? $ticket->subject,
                'description' => $context['description'] ?? $ticket->description,
            ]),
            initiatorType: $initiatorType,
            initiatorId: $initiatorId,
            automationActorId: (int) $automationActor->id,
            correlationUuid: $correlationUuid,
            causationUuid: $causationUuid,
            parentEventId: null,
            parentActionResultId: null,
            chainDepth: 0,
            occurredAt: CarbonImmutable::now(),
            fingerprint: $fingerprint,
            idempotencyKey: $idempotencyKey,
        );
    }

    /**
     * Build the root envelope for one completed authoritative Ticket mutation.
     * Exact runtime facts stay in memory; only the audit sanitizer projection
     * is exposed through persistence().
     */
    public static function mutation(
        Ticket $ticket,
        TicketRuleMutationEvent $event,
        ?User $initiator,
        User $automationActor,
    ): self {
        if ((int) $ticket->id !== $event->ticketId) {
            throw new \InvalidArgumentException('The Ticket mutation event belongs to a different Ticket.');
        }

        $correlationUuid = self::uuid($event->correlationUuid) ?? (string) Str::uuid();
        $causationUuid = self::uuid($event->causationUuid);
        $sanitizer = app(TicketRuleAuditSanitizer::class);
        $safeBefore = $sanitizer->map($event->before);
        $safeAfter = $sanitizer->map($event->after);
        $facts = array_merge([
            'ticket_id' => (int) $ticket->id,
            'ticket_key' => $ticket->ticket_key,
            'subject' => $ticket->subject,
            'description' => $ticket->description,
            'ticket_type_id' => $ticket->ticket_type_id,
            'queue_id' => $ticket->queue_id,
            'status_id' => $ticket->status_id,
            'priority_id' => $ticket->priority_id,
            'sla_id' => $ticket->sla_id,
            'category_id' => $ticket->category_id,
            'client_id' => $ticket->client_id,
            'site_id' => $ticket->site_id,
            'contact_id' => $ticket->contact_id,
            'asset_id' => $ticket->asset_id,
            'impact' => $ticket->impact,
            'urgency' => $ticket->urgency,
            'owner_id' => $ticket->owner_id,
            'channel' => $ticket->channel,
            'event_source_channel' => $event->sourceChannel,
            'event_source_action' => $event->sourceAction,
        ], $event->safeFacts, $event->classification, $event->after);
        $fingerprint = TicketRuleDerivedEventIdentity::fingerprint(
            (int) $ticket->id,
            $event->eventKey,
            $event->changedFields,
            $safeBefore,
            $safeAfter,
            $event->safeFacts,
            $event->classification,
        );
        $idempotencyKey = TicketRuleStableJson::checksum([
            'ticket_id' => (int) $ticket->id,
            'event_key' => $event->eventKey,
            'delivery_identity' => hash('sha256', $event->deliveryIdentity),
        ]);

        return new self(
            ticketId: (int) $ticket->id,
            eventKey: $event->eventKey,
            sourceChannel: $event->sourceChannel,
            sourceAction: $event->sourceAction,
            changedFields: $event->changedFields,
            before: $safeBefore,
            after: $safeAfter,
            facts: $facts,
            initiatorType: $initiator ? 'user' : null,
            initiatorId: $initiator?->id,
            automationActorId: (int) $automationActor->id,
            correlationUuid: $correlationUuid,
            causationUuid: $causationUuid,
            parentEventId: null,
            parentActionResultId: null,
            chainDepth: 0,
            occurredAt: CarbonImmutable::now(),
            fingerprint: $fingerprint,
            idempotencyKey: $idempotencyKey,
        );
    }

    private static function uuid(?string $value): ?string
    {
        if ($value === null || ! Str::isUuid($value)) {
            return null;
        }

        return strtolower($value);
    }

    private static function identifier(mixed $value, string $fallback, int $limit): string
    {
        return self::nullableIdentifier($value, $limit) ?? $fallback;
    }

    private static function nullableIdentifier(mixed $value, int $limit): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = preg_replace('/[^A-Za-z0-9_.:-]+/', '_', trim((string) $value)) ?? '';
        $normalized = trim($normalized, '_.:-');

        if ($normalized === '') {
            return null;
        }

        return Str::limit($normalized, $limit, '');
    }

    private static function positiveInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (! is_string($value) || preg_match('/\A[1-9][0-9]*\z/', $value) !== 1) {
            return null;
        }

        return (int) $value;
    }

    /** @return array<string, mixed> */
    public function persistence(): array
    {
        return [
            'ticket_id' => $this->ticketId,
            'parent_event_id' => $this->parentEventId,
            'parent_action_result_id' => $this->parentActionResultId,
            'event_key' => $this->eventKey,
            'event_fingerprint' => $this->fingerprint,
            'idempotency_key' => $this->idempotencyKey,
            'source_channel' => $this->sourceChannel,
            'source_action' => $this->sourceAction,
            'changed_fields_json' => $this->changedFields,
            'before_json' => $this->before,
            'after_json' => $this->after,
            'initiator_type' => $this->initiatorType,
            'initiator_id' => $this->initiatorId,
            'automation_actor_id' => $this->automationActorId,
            'correlation_uuid' => $this->correlationUuid,
            'causation_uuid' => $this->causationUuid,
            'chain_depth' => $this->chainDepth,
            'occurred_at' => $this->occurredAt,
        ];
    }
}
