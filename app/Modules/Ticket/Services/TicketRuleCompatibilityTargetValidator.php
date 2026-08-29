<?php

namespace App\Modules\Ticket\Services;

use App\Modules\Commercial\Models\Sla\Sla;
use App\Modules\Taxonomy\Models\Category;
use App\Modules\Taxonomy\Models\Tag;
use App\Modules\Ticket\Models\TicketPriority;
use App\Modules\Ticket\Models\TicketQueue;
use App\Modules\Ticket\Models\TicketType;

final class TicketRuleCompatibilityTargetValidator
{
    /** @param array<string, mixed> $action */
    public function failureCode(array $action): ?string
    {
        $type = (string) ($action['type'] ?? '');

        if ($type === 'emit_signal') {
            return null;
        }

        if (! in_array($type, [
            'set_ticket_type',
            'set_queue',
            'set_priority',
            'set_sla',
            'set_category',
            'add_tag',
        ], true)) {
            return 'unsupported_compatibility_action';
        }

        $value = $action['value'] ?? null;
        if (! is_numeric($value) || (int) $value < 1) {
            return 'invalid_action_target';
        }

        $targetId = (int) $value;
        $available = match ($type) {
            'set_ticket_type' => TicketType::query()
                ->where('is_active', true)
                ->whereKey($targetId)
                ->exists(),
            'set_queue' => TicketQueue::query()
                ->where('is_active', true)
                ->whereKey($targetId)
                ->exists(),
            'set_priority' => TicketPriority::query()
                ->where('is_active', true)
                ->whereKey($targetId)
                ->exists(),
            'set_sla' => Sla::query()->whereKey($targetId)->exists(),
            'set_category' => Category::query()
                ->forTickets()
                ->active()
                ->whereKey($targetId)
                ->exists(),
            'add_tag' => Tag::query()
                ->where('active', true)
                ->whereKey($targetId)
                ->exists(),
        };

        return $available ? null : $type.'_target_unavailable';
    }
}
