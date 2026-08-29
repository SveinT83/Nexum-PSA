<?php

namespace App\Modules\Ticket\Services;

use App\Modules\Ticket\Models\TicketRule;
use App\Modules\Ticket\Models\TicketRuleVersion;
use App\Modules\Ticket\Support\TicketRuleStableJson;
use Illuminate\Support\Collection;

final class TicketRuleFrozenPublishedSet
{
    /**
     * @return array{versions: Collection<int, TicketRuleVersion>, version_ids: list<int>, checksum: string}
     */
    public function capture(): array
    {
        $versions = TicketRuleVersion::query()
            ->select('ticket_rule_versions.*')
            ->join('ticket_rules', 'ticket_rules.published_version_id', '=', 'ticket_rule_versions.id')
            ->whereNull('ticket_rules.deleted_at')
            ->where('ticket_rules.is_active', true)
            ->where('ticket_rules.lifecycle_status', TicketRule::LIFECYCLE_PUBLISHED)
            ->where('ticket_rules.compatibility_status', TicketRule::COMPATIBILITY_ELIGIBLE)
            ->with('rule')
            ->orderBy('ticket_rule_versions.weight')
            ->orderBy('ticket_rule_versions.ticket_rule_id')
            ->get();

        $snapshot = $versions
            ->map(fn (TicketRuleVersion $version): array => [
                'id' => (int) $version->id,
                'ticket_rule_id' => (int) $version->ticket_rule_id,
                'weight' => (int) $version->weight,
                'definition_checksum' => (string) $version->definition_checksum,
            ])
            ->values()
            ->all();

        return [
            'versions' => $versions,
            'version_ids' => array_column($snapshot, 'id'),
            'checksum' => TicketRuleStableJson::checksum($snapshot),
        ];
    }
}
