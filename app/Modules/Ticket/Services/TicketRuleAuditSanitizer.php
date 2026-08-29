<?php

namespace App\Modules\Ticket\Services;

use App\Modules\Ticket\Support\TicketRuleStableJson;
use Illuminate\Support\Str;

final class TicketRuleAuditSanitizer
{
    private const SAFE_TEXT_FIELDS = [
        'action_type',
        'branch',
        'channel',
        'delivery_type',
        'event_key',
        'failure_code',
        'initiator_type',
        'permission',
        'reason_code',
        'root_event_key',
        'selected_branch',
        'severity',
        'signal_type',
        'sla_source',
        'source_action',
        'source_channel',
        'status',
        'ticket_action',
        'ticket_key',
        'type',
        'workflow_state_key',
    ];

    /** @param array<string, mixed> $values */
    public function map(array $values): array
    {
        $safe = [];

        foreach ($values as $key => $value) {
            $safe[(string) $key] = $this->value((string) $key, $value);
        }

        return $safe;
    }

    public function value(string $field, mixed $value): mixed
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_array($value)) {
            if (array_is_list($value) && count($value) <= 50
                && collect($value)->every(fn (mixed $item): bool => is_numeric($item))) {
                return array_map('intval', $value);
            }

            return [
                'type' => 'structured',
                'count' => count($value),
                'sha256' => TicketRuleStableJson::checksum($value),
            ];
        }

        $text = (string) $value;

        if (str_ends_with($field, '_id') && preg_match('/\A[0-9]+\z/', $text) === 1) {
            return (int) $text;
        }

        if (in_array($field, self::SAFE_TEXT_FIELDS, true)) {
            return Str::limit($text, 120, '');
        }

        return [
            'type' => 'text',
            'length' => mb_strlen($text),
            'sha256' => hash('sha256', $text),
        ];
    }

    public function message(?string $message): ?string
    {
        if ($message === null) {
            return null;
        }

        // Exception text can contain credentials, payloads, or PII.
        // Persist only a correlation-safe fingerprint.
        return 'Failure fingerprint: '.hash('sha256', $message);
    }
}
