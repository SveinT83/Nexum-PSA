<?php

namespace App\Modules\Sales\Support;

use App\Modules\Sales\Models\SalesQuoteLine;
use App\Modules\Sales\Models\SalesQuoteVersion;
use Illuminate\Support\Collection;

class SalesQuotePresentation
{
    public const CADENCES = [
        'one_time' => [
            'label' => 'One-time charges',
            'summary_label' => 'One-time',
            'unit' => 'NOK',
            'suffix' => '',
        ],
        'monthly' => [
            'label' => 'Monthly recurring charges',
            'summary_label' => 'Recurring monthly',
            'unit' => 'NOK/month',
            'suffix' => '/month',
        ],
        'quarterly' => [
            'label' => 'Quarterly recurring charges',
            'summary_label' => 'Recurring quarterly',
            'unit' => 'NOK/quarter',
            'suffix' => '/quarter',
        ],
        'annual' => [
            'label' => 'Annual recurring charges',
            'summary_label' => 'Recurring annual',
            'unit' => 'NOK/year',
            'suffix' => '/year',
        ],
    ];

    public const CUSTOMER_COPY_FIELDS = [
        'intro_text' => ['label' => 'Introduction', 'position' => 'before'],
        'scope_text' => ['label' => 'Solution and scope', 'position' => 'before'],
        'assumptions_text' => ['label' => 'Assumptions and alternatives', 'position' => 'after'],
        'exclusions_text' => ['label' => 'Exclusions', 'position' => 'after'],
        'next_steps_text' => ['label' => 'Next steps', 'position' => 'after'],
    ];

    public function forVersion(SalesQuoteVersion $version, ?array $selection = null): array
    {
        $version->loadMissing(['lines.optionGroup', 'optionGroups.lines', 'acknowledgements', 'acceptanceSnapshot']);
        $lines = $version->lines;
        $selection = $selection ?: $this->selectionFromVersion($version);
        $selectedLineIds = collect($selection['selected_line_ids'] ?? [])
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->unique();
        $quantities = (array) ($selection['quantities'] ?? []);

        $lines->each(function (SalesQuoteLine $line) use ($selectedLineIds, $quantities): void {
            $quantity = (float) ($quantities[$line->id] ?? $quantities[(string) $line->id] ?? $line->quantity);
            $selected = $selectedLineIds->contains((int) $line->id);
            $totals = $this->lineTotals($line, $quantity);

            $line->setAttribute('cpq_selected', $selected);
            $line->setAttribute('cpq_effective_quantity', round($quantity, 2));
            $line->setAttribute('cpq_line_total_ex_vat', $selected ? $totals['line_total_ex_vat'] : 0);
            $line->setAttribute('cpq_vat_amount', $selected ? $totals['vat_amount'] : 0);
            $line->setAttribute('cpq_line_total_inc_vat', $selected ? $totals['line_total_inc_vat'] : 0);
        });

        $groups = collect(self::CADENCES)
            ->map(function (array $definition, string $cadence) use ($lines): array {
                $groupLines = $lines
                    ->filter(fn (SalesQuoteLine $line): bool => $this->cadenceForLine($line) === $cadence)
                    ->values();

                return array_merge($definition, [
                    'key' => $cadence,
                    'lines' => $groupLines,
                    'total_ex_vat' => round($groupLines->sum(fn (SalesQuoteLine $line): float => (float) $line->getAttribute('cpq_line_total_ex_vat')), 2),
                    'vat_total' => round($groupLines->sum(fn (SalesQuoteLine $line): float => (float) $line->getAttribute('cpq_vat_amount')), 2),
                    'total_inc_vat' => round($groupLines->sum(fn (SalesQuoteLine $line): float => (float) $line->getAttribute('cpq_line_total_inc_vat')), 2),
                ]);
            })
            ->filter(fn (array $group): bool => $group['lines']->isNotEmpty())
            ->values();

        $beforeCopy = $this->customerCopy($version, 'before');
        $afterCopy = $this->customerCopy($version, 'after');

        return [
            'groups' => $groups,
            'before_copy' => $beforeCopy,
            'after_copy' => $afterCopy,
            'summary_text' => $groups->map(fn (array $group): string => $this->summaryLine($group))->implode("\n"),
            'summary_html' => $groups->map(fn (array $group): string => e($this->summaryLine($group)))->implode('<br>'),
            'customer_copy_text' => $this->copyText($beforeCopy->merge($afterCopy)),
            'customer_copy_html' => $this->copyHtml($beforeCopy->merge($afterCopy)),
            'selected_line_ids' => $selectedLineIds->all(),
            'quantities' => $quantities,
            'acknowledgements' => $version->acknowledgements,
            'option_groups' => $version->optionGroups,
            'has_customer_choices' => $lines->contains(fn (SalesQuoteLine $line): bool => ! $line->is_required || $line->option_group_id !== null || $line->customer_quantity_editable),
        ];
    }

    public function normalizeCadence(?string $cadence, ?string $section, ?string $downstreamType): string
    {
        if ($cadence && array_key_exists($cadence, self::CADENCES)) {
            return $cadence;
        }

        return $downstreamType === 'recurring_contract' || $section === 'monthly_services'
            ? 'monthly'
            : 'one_time';
    }

    public function cadenceForLine(SalesQuoteLine $line): string
    {
        return $this->normalizeCadence($line->billing_cadence, $line->section, $line->downstream_type);
    }

    public function defaultSelectionForVersion(SalesQuoteVersion $version): array
    {
        $version->loadMissing('lines');

        return [
            'selected_line_ids' => $version->lines
                ->filter(fn (SalesQuoteLine $line): bool => $line->is_required || $line->customer_selected_by_default)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->values()
                ->all(),
            'quantities' => $version->lines
                ->mapWithKeys(fn (SalesQuoteLine $line): array => [$line->id => (float) $line->quantity])
                ->all(),
        ];
    }

    private function customerCopy(SalesQuoteVersion $version, string $position): Collection
    {
        return collect(self::CUSTOMER_COPY_FIELDS)
            ->filter(fn (array $definition): bool => $definition['position'] === $position)
            ->map(fn (array $definition, string $field): array => [
                'field' => $field,
                'label' => $definition['label'],
                'text' => $version->{$field},
            ])
            ->filter(fn (array $section): bool => filled($section['text']))
            ->values();
    }

    private function summaryLine(array $group): string
    {
        return $group['summary_label'].': '.$this->money($group['total_ex_vat']).' '.$group['unit'].' ex VAT';
    }

    private function copyText(Collection $sections): string
    {
        return $sections->map(fn (array $section): string => $section['label']."\n".$section['text'])->implode("\n\n");
    }

    private function copyHtml(Collection $sections): string
    {
        return $sections->map(fn (array $section): string => '<p><strong>'.e($section['label']).'</strong><br>'.nl2br(e($section['text'])).'</p>')->implode('');
    }

    private function money(float $amount): string
    {
        return number_format($amount, 2, ',', ' ');
    }

    private function selectionFromVersion(SalesQuoteVersion $version): array
    {
        if ($version->acceptanceSnapshot) {
            return [
                'selected_line_ids' => $version->acceptanceSnapshot->selected_line_ids ?: [],
                'quantities' => collect($version->acceptanceSnapshot->selected_lines ?: [])
                    ->mapWithKeys(fn (array $line): array => [(int) $line['id'] => (float) $line['quantity']])
                    ->all(),
            ];
        }

        return $this->defaultSelectionForVersion($version);
    }

    private function lineTotals(SalesQuoteLine $line, float $quantity): array
    {
        $base = (float) $line->unit_price_ex_vat * $quantity;
        $discount = $line->discount_type === 'percent'
            ? $base * ((float) $line->discount_value / 100)
            : (float) $line->discount_value;
        $lineTotal = max(0, $base - $discount);
        $vatAmount = $line->vat_rate !== null ? $lineTotal * ((float) $line->vat_rate / 100) : 0;

        return [
            'line_total_ex_vat' => round($lineTotal, 2),
            'vat_amount' => round($vatAmount, 2),
            'line_total_inc_vat' => round($lineTotal + $vatAmount, 2),
        ];
    }
}
