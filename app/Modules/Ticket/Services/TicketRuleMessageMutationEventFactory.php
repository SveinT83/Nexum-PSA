<?php

namespace App\Modules\Ticket\Services;

use App\Models\Core\User;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketMessage;
use App\Modules\Ticket\Support\TicketRuleMutationEvent;
use App\Modules\Ticket\Support\TicketRuleTriggerRegistry;

/**
 * Build one privacy-safe Ticket message event for every authoritative message entry point.
 */
final class TicketRuleMessageMutationEventFactory
{
    /** @param array<string, mixed> $context */
    public function make(
        Ticket $ticket,
        TicketMessage $message,
        array $context = [],
        ?User $actor = null,
    ): TicketRuleMutationEvent {
        $sourceChannel = $this->sourceChannel($ticket, $context, $actor);
        $sourceAction = (string) ($context['_event_source_action'] ?? 'AddTicketMessage');
        $messageType = $this->messageType($message, $actor);
        $assignment = $this->assignment($context);
        $changedFields = ['message_id'];
        $eventKeys = [TicketRuleTriggerRegistry::MESSAGE_ADDED];
        $attachmentsCount = $message->fileAttachments()->count();
        $body = (string) $message->body;
        $subject = (string) ($message->subject ?? '');
        $safeFacts = [
            'message_id' => (int) $message->id,
            'message_type' => $messageType,
            'message_visibility' => (string) $message->visibility,
            'attachments_count' => $attachmentsCount,
            'message_body_length' => mb_strlen($body),
            'message_body_sha256' => hash('sha256', $body),
            'message_subject_length' => mb_strlen($subject),
            'message_subject_sha256' => hash('sha256', $subject),
            'event_source_channel' => $sourceChannel,
            'event_source_action' => $sourceAction,
        ];
        $before = [];
        $after = [
            'message_id' => (int) $message->id,
            'message_type' => $messageType,
            'message_visibility' => (string) $message->visibility,
            'attachments_count' => $attachmentsCount,
        ];
        $classification = [
            'message_type' => $messageType,
            'message_visibility' => (string) $message->visibility,
        ];

        if ($assignment !== null) {
            $changedFields[] = 'owner_id';
            $eventKeys[] = TicketRuleTriggerRegistry::ASSIGNMENT_CHANGED;
            $before['owner_id'] = $assignment['before_owner_id'];
            $after['owner_id'] = $assignment['after_owner_id'];
            $safeFacts['assignment_changes'] = [$assignment['change']];
            $classification['assignment_changes'] = [$assignment['change']];
            $classification['specialized_triggers_share_root_event'] = true;
        }
        $classification['event_keys'] = $eventKeys;

        return TicketRuleMutationEvent::make(
            ticketId: (int) $ticket->id,
            eventKey: TicketRuleTriggerRegistry::MESSAGE_ADDED,
            changedFields: $changedFields,
            before: $before,
            after: $after,
            safeFacts: $safeFacts,
            classification: $classification,
            sourceChannel: $sourceChannel,
            sourceAction: $sourceAction,
            deliveryIdentity: (string) ($context['_delivery_key'] ?? 'ticket-message:'.$message->id),
            relatedRecordType: TicketMessage::class,
            relatedRecordId: (int) $message->id,
            correlationUuid: $context['_correlation_uuid'] ?? null,
            causationUuid: $context['_causation_uuid'] ?? null,
        );
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{before_owner_id: int|null, after_owner_id: int|null, change: string}|null
     */
    private function assignment(array $context): ?array
    {
        if (! array_key_exists('_assignment_before_owner_id', $context)
            || ! array_key_exists('_assignment_after_owner_id', $context)) {
            return null;
        }

        $beforeOwnerId = $this->ownerId($context['_assignment_before_owner_id']);
        $afterOwnerId = $this->ownerId($context['_assignment_after_owner_id']);
        $change = match (true) {
            $beforeOwnerId === null && $afterOwnerId !== null => 'owner_assigned',
            $beforeOwnerId !== null && $afterOwnerId === null => 'owner_unassigned',
            $beforeOwnerId !== null && $afterOwnerId !== null && $beforeOwnerId !== $afterOwnerId => 'owner_changed',
            default => null,
        };

        return $change === null ? null : [
            'before_owner_id' => $beforeOwnerId,
            'after_owner_id' => $afterOwnerId,
            'change' => $change,
        ];
    }

    private function ownerId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_int($value) && (! is_string($value) || ! ctype_digit($value))) {
            return null;
        }

        $value = (int) $value;

        return $value > 0 ? $value : null;
    }

    private function messageType(TicketMessage $message, ?User $actor): string
    {
        if ($message->type === 'internal_note' || $message->visibility === 'internal') {
            return 'internal_note';
        }

        if ($message->type === 'status_update') {
            return 'public_update';
        }

        if (in_array($message->author_type, ['portal_user', 'external', 'contact'], true)) {
            return 'customer_reply';
        }

        return $actor ? 'public_update' : 'customer_reply';
    }

    /** @param array<string, mixed> $context */
    private function sourceChannel(Ticket $ticket, array $context, ?User $actor): string
    {
        if (filled($context['_event_source_channel'] ?? null)) {
            return (string) $context['_event_source_channel'];
        }

        if (($context['metadata']['created_from'] ?? null) === 'telephony_call') {
            return 'telephony';
        }

        if ($actor?->isSystemActor()) {
            return 'ticket_rule';
        }

        if ($actor) {
            return 'tech';
        }

        return in_array($ticket->channel, [
            'customer_portal',
            'email',
            'api',
            'intake',
            'relationship',
            'telephony',
            'signal',
            'scheduled',
            'integration',
        ], true)
            ? (string) $ticket->channel
            : 'system';
    }
}
