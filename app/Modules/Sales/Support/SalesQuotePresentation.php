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

    public function forVersion(SalesQuoteVersion $version): array
    {
        $version->loadMissing('lines');
        $lines = $version->lines;

        $groups = collect(self::CADENCES)
            ->map(function (array $definition, string $cadence) use ($lines): array {
                $groupLines = $lines
                    ->filter(fn (SalesQuoteLine $line): bool => $this->cadenceForLine($line) === $cadence)
                    ->values();

                return array_merge($definition, [
                    'key' => $cadence,
                    'lines' => $groupLines,
                    'total_ex_vat' => round($groupLines->sum(fn (SalesQuoteLine $line): float => (float) $line->line_total_ex_vat), 2),
                    'vat_total' => round($groupLines->sum(fn (SalesQuoteLine $line): float => (float) $line->vat_amount), 2),
                    'total_inc_vat' => round($groupLines->sum(fn (SalesQuoteLine $line): float => (float) $line->line_total_inc_vat), 2),
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
}
