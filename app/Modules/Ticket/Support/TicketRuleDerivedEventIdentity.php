<?php

namespace App\Modules\Ticket\Support;

/**
 * Builds the semantic identity used to stop recursive derived Ticket events.
 *
 * Persisted record identifiers are delivery evidence, not state identity.
 * Message events therefore replace their generated message ID while retaining
 * safe content/type evidence so distinct legitimate messages remain distinct.
 */
final class TicketRuleDerivedEventIdentity
{
    private const GENERATED_RECORD_IDENTIFIER = [
        'type' => 'generated_record_identifier',
    ];

    private const MESSAGE_SEMANTIC_FIELDS = [
        'message_type',
        'message_visibility',
        'attachments_count',
        'message_body_length',
        'message_body_sha256',
        'message_subject_length',
        'message_subject_sha256',
    ];

    /**
     * @param  list<string>  $changedFields
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  array<string, mixed>  $safeFacts
     * @param  array<string, mixed>  $classification
     */
    public static function fingerprint(
        int $ticketId,
        string $eventKey,
        array $changedFields,
        array $before,
        array $after,
        array $safeFacts = [],
        array $classification = [],
    ): string {
        $changedFields = collect($changedFields)
            ->filter(fn (mixed $field): bool => is_string($field) && $field !== '')
            ->unique()
            ->sort()
            ->values()
            ->all();

        return TicketRuleStableJson::checksum([
            'ticket_id' => $ticketId,
            'event_key' => $eventKey,
            'changed_fields' => $changedFields,
            'before' => self::changedEvidence($eventKey, $changedFields, $before),
            'after' => self::changedEvidence($eventKey, $changedFields, $after),
            'semantic_evidence' => self::semanticEvidence(
                $eventKey,
                $safeFacts,
                $classification,
            ),
        ]);
    }

    /** @param list<string> $changedFields @return array<string, mixed> */
    private static function changedEvidence(
        string $eventKey,
        array $changedFields,
        array $evidence,
    ): array {
        $selected = [];

        foreach ($changedFields as $field) {
            if ($eventKey === TicketRuleTriggerRegistry::MESSAGE_ADDED
                && $field === 'message_id') {
                $selected[$field] = self::GENERATED_RECORD_IDENTIFIER;

                continue;
            }

            if (! array_key_exists($field, $evidence)) {
                continue;
            }

            $selected[$field] = $evidence[$field];
        }

        return $selected;
    }

    /** @return array<string, mixed> */
    private static function semanticEvidence(
        string $eventKey,
        array $safeFacts,
        array $classification,
    ): array {
        if ($eventKey !== TicketRuleTriggerRegistry::MESSAGE_ADDED) {
            return [];
        }

        $available = array_merge($classification, $safeFacts);
        $semantic = [];

        foreach (self::MESSAGE_SEMANTIC_FIELDS as $field) {
            if (! array_key_exists($field, $available)) {
                continue;
            }

            $semantic[$field] = $available[$field];
        }

        return $semantic;
    }
}
