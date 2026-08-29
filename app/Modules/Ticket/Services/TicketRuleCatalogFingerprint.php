<?php

namespace App\Modules\Ticket\Services;

use App\Modules\Ticket\Support\TicketRuleStableJson;
use Illuminate\Support\Facades\DB;
use JsonException;

final class TicketRuleCatalogFingerprint
{
    public function checksum(): string
    {
        return TicketRuleStableJson::checksum($this->catalog());
    }

    /**
     * Fingerprint only the mutable legacy catalogue. Version/lifecycle metadata
     * is deliberately excluded so compatibility backfill cannot disguise drift.
     *
     * @return array<int, array<string, mixed>>
     */
    public function catalog(): array
    {
        return DB::table('ticket_rules')
            ->orderBy('id')
            ->get([
                'id',
                'name',
                'description',
                'trigger',
                'weight',
                'is_active',
                'stop_processing',
                'conditions_json',
                'actions_json',
                'deleted_at',
            ])
            ->map(fn (object $rule): array => [
                'id' => (int) $rule->id,
                'name' => (string) $rule->name,
                'description' => $rule->description !== null ? (string) $rule->description : null,
                'trigger' => (string) $rule->trigger,
                'weight' => (int) $rule->weight,
                'is_active' => (bool) $rule->is_active,
                'stop_processing' => (bool) $rule->stop_processing,
                'conditions' => $this->decodeJsonForFingerprint($rule->conditions_json),
                'actions' => $this->decodeJsonForFingerprint($rule->actions_json),
                'deleted_at' => $rule->deleted_at !== null ? (string) $rule->deleted_at : null,
            ])
            ->values()
            ->all();
    }

    private function decodeJsonForFingerprint(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        try {
            return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // Preserve drift sensitivity without exposing malformed rule data.
            return ['malformed_json_sha256' => hash('sha256', $value)];
        }
    }
}
