<?php

namespace App\Modules\Commercial\Actions;

use App\Modules\Commercial\Models\Contracts\Contracts;
use Illuminate\Support\Collection;

class BuildContractTermSnapshots
{
    /**
     * Build legal snapshots from the terms attached to all services in a contract.
     */
    public function handle(Contracts $contract): array
    {
        $contract->load(['sla', 'items.slaPolicy', 'items.service.serviceTerms']);

        $termsByType = $this->groupTermsByType($contract);

        return [
            'terms_snapshot' => $this->combine($termsByType['terms']),
            'dpa_snapshot' => $this->combine($termsByType['dpa']),
            'legal_snapshot' => $this->combine($termsByType['legal']),
            'sla_snapshot' => $this->combineSlaSnapshot($contract, $termsByType['sla']),
            'general_snapshot' => $this->combine($termsByType['general']),
        ];
    }

    /**
     * Group service terms into the contract snapshot buckets.
     */
    public function groupTermsByType(Contracts $contract): array
    {
        $contract->load(['items.service.serviceTerms']);

        $termsByType = [
            'terms' => collect(),
            'dpa' => collect(),
            'legal' => collect(),
            'sla' => collect(),
            'general' => collect(),
        ];

        foreach ($contract->items as $item) {
            if (! $item->service) {
                continue;
            }

            foreach ($item->service->serviceTerms as $term) {
                $type = $term->type ?: 'terms';

                if (! isset($termsByType[$type])) {
                    $type = 'terms';
                }

                if (! $termsByType[$type]->has($term->id)) {
                    $termsByType[$type]->put($term->id, $term);
                }
            }
        }

        return $termsByType;
    }

    private function combine(Collection $terms): string
    {
        return $terms
            ->map(function ($term) {
                $content = trim((string) $term->content);

                if ($content === '') {
                    return null;
                }

                return trim((string) $term->name)."\n".$content;
            })
            ->filter()
            ->unique()
            ->implode("\n\n---\n\n");
    }

    private function combineSlaSnapshot(Contracts $contract, Collection $slaTerms): string
    {
        $parts = collect([$this->combine($slaTerms)])
            ->filter();

        $slaPolicies = collect();

        if ($contract->sla) {
            $slaPolicies->put('contract-'.$contract->sla->id, [
                'title' => 'Contract default SLA: '.$contract->sla->name,
                'sla' => $contract->sla,
            ]);
        }

        foreach ($contract->items as $item) {
            if (! $item->uses_contract_default_sla && $item->slaPolicy) {
                $slaPolicies->put('item-'.$item->slaPolicy->id, [
                    'title' => 'Service SLA: '.$item->slaPolicy->name,
                    'sla' => $item->slaPolicy,
                ]);
            }
        }

        foreach ($slaPolicies as $policy) {
            $parts->push($this->formatSlaPolicy($policy['title'], $policy['sla']));
        }

        return $parts
            ->filter()
            ->unique()
            ->implode("\n\n---\n\n");
    }

    private function formatSlaPolicy(string $title, object $sla): string
    {
        $lines = [$title];

        if (! empty($sla->description)) {
            $lines[] = trim((string) $sla->description);
        }

        $lines[] = 'Low priority: first response '.$this->duration($sla->low_firstResponse, $sla->low_firstResponse_type).', onsite '.$this->duration($sla->low_onsite, $sla->low_onsite_type).'.';
        $lines[] = 'Medium priority: first response '.$this->duration($sla->medium_firstResponse, $sla->medium_firstResponse_type).', onsite '.$this->duration($sla->medium_onsite, $sla->medium_onsite_type).'.';
        $lines[] = 'High priority: first response '.$this->duration($sla->high_firstResponse, $sla->high_firstResponse_type).', onsite '.$this->duration($sla->high_onsite, $sla->high_onsite_type).'.';

        return implode("\n", array_filter($lines));
    }

    private function duration($value, $type): string
    {
        if ($value === null || $value === '') {
            return 'not specified';
        }

        return trim((string) $value.' '.strtolower((string) ($type ?: 'hours')));
    }
}
