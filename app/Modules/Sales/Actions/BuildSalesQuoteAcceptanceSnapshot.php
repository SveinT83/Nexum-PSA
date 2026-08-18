<?php

namespace App\Modules\Sales\Actions;

use App\Modules\Sales\Models\SalesQuoteAcknowledgement;
use App\Modules\Sales\Models\SalesQuoteLine;
use App\Modules\Sales\Models\SalesQuoteVersion;
use App\Modules\Sales\Support\SalesQuotePresentation;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class BuildSalesQuoteAcceptanceSnapshot
{
    public function __construct(private readonly SalesQuotePresentation $quotePresentation) {}

    public function handle(SalesQuoteVersion $version, array $data): array
    {
        $version->loadMissing(['quote.opportunity.client', 'lines.optionGroup', 'optionGroups.lines', 'acknowledgements']);

        $lines = $version->lines->keyBy('id');
        if ($lines->isEmpty()) {
            throw ValidationException::withMessages(['quote' => 'A quote must contain at least one line before it can be accepted.']);
        }

        $submittedLineIds = collect($data['selected_line_ids'] ?? [])
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0 && $lines->has($id))
            ->unique()
            ->values();

        $hasExplicitSelection = array_key_exists('selected_line_ids', $data);
        $selectedLineIds = $hasExplicitSelection
            ? $submittedLineIds
            : $lines
                ->filter(fn (SalesQuoteLine $line): bool => $line->is_required || $line->customer_selected_by_default)
                ->keys()
                ->map(fn ($id): int => (int) $id)
                ->values();

        $requiredLineIds = $lines
            ->filter(fn (SalesQuoteLine $line): bool => $line->is_required)
            ->keys()
            ->map(fn ($id): int => (int) $id);

        $selectedLineIds = $selectedLineIds->merge($requiredLineIds)->unique()->values();
        $missingRequired = $requiredLineIds->diff($selectedLineIds);
        if ($missingRequired->isNotEmpty()) {
            throw ValidationException::withMessages(['selected_line_ids' => 'Required quote lines cannot be removed.']);
        }

        foreach ($version->optionGroups as $group) {
            $groupLineIds = $group->lines->pluck('id')->map(fn ($id): int => (int) $id);
            $selectedCount = $selectedLineIds->intersect($groupLineIds)->count();
            $maxSelect = $group->max_select ?: (in_array($group->type, ['alternative', 'good_better_best'], true) ? 1 : null);

            if ($selectedCount < (int) $group->min_select) {
                throw ValidationException::withMessages(['selected_line_ids' => 'Select at least '.$group->min_select.' option(s) from '.$group->name.'.']);
            }

            if ($maxSelect !== null && $selectedCount > (int) $maxSelect) {
                throw ValidationException::withMessages(['selected_line_ids' => 'Select no more than '.$maxSelect.' option(s) from '.$group->name.'.']);
            }
        }

        $quantities = $this->acceptedQuantities($lines, $selectedLineIds, (array) ($data['quantities'] ?? []));
        $selectedLines = $selectedLineIds
            ->map(fn (int $id): SalesQuoteLine => $lines->get($id))
            ->map(fn (SalesQuoteLine $line): array => $this->lineSnapshot($line, (float) $quantities[$line->id]))
            ->values();
        $declinedLines = $lines
            ->reject(fn (SalesQuoteLine $line): bool => $selectedLineIds->contains((int) $line->id))
            ->map(fn (SalesQuoteLine $line): array => $this->lineSnapshot($line, (float) $line->quantity, false))
            ->values();

        $acknowledgements = $this->acknowledgements($version, $selectedLineIds, (array) ($data['acknowledgement_ids'] ?? []));
        $totals = $this->totals($selectedLines);
        $presentation = $this->quotePresentation->forVersion($version, [
            'selected_line_ids' => $selectedLineIds->all(),
            'quantities' => $quantities,
        ]);

        return [
            'selected_line_ids' => $selectedLineIds->all(),
            'declined_line_ids' => $declinedLines->pluck('id')->all(),
            'selected_lines' => $selectedLines->all(),
            'declined_lines' => $declinedLines->all(),
            'acknowledgements' => $acknowledgements->all(),
            'totals' => $totals,
            'customer_identity' => [
                'name' => $data['name'],
                'email' => $data['email'] ?? null,
                'method' => $data['method'] ?? 'public_link',
                'ip' => $data['ip'] ?? null,
                'user_agent' => $data['user_agent'] ?? null,
                'user_id' => $data['user_id'] ?? null,
                'portal_account_id' => $data['portal_account_id'] ?? null,
                'portal_membership_id' => $data['portal_membership_id'] ?? null,
                'portal_contact_id' => $data['portal_contact_id'] ?? null,
            ],
            'public_text_snapshot' => [
                'quote_key' => $version->quote->quote_key,
                'version_number' => $version->version_number,
                'title' => $version->title,
                'expires_at' => $version->expires_at?->toDateString(),
                'before_copy' => $presentation['before_copy']->all(),
                'after_copy' => $presentation['after_copy']->all(),
                'summary_text' => $presentation['summary_text'],
            ],
            'selection_payload' => [
                'selected_line_ids' => $selectedLineIds->all(),
                'quantities' => $quantities,
                'acknowledgement_ids' => $acknowledgements->pluck('id')->all(),
            ],
        ];
    }

    private function acceptedQuantities(Collection $lines, Collection $selectedLineIds, array $submitted): array
    {
        $quantities = [];

        foreach ($selectedLineIds as $lineId) {
            /** @var SalesQuoteLine $line */
            $line = $lines->get($lineId);
            $quantity = (float) $line->quantity;

            if ($line->customer_quantity_editable) {
                $quantity = (float) ($submitted[$line->id] ?? $submitted[(string) $line->id] ?? $line->quantity);
                $min = max(0.01, (float) ($line->min_customer_quantity ?: 0.01));
                $max = $line->max_customer_quantity !== null ? (float) $line->max_customer_quantity : null;

                if ($quantity < $min || ($max !== null && $quantity > $max)) {
                    throw ValidationException::withMessages(['quantities' => 'Quantity for '.$line->name.' must be between '.$min.' and '.($max ?? 'the allowed maximum').'.']);
                }
            }

            $quantities[$line->id] = round($quantity, 2);
        }

        return $quantities;
    }

    private function acknowledgements(SalesQuoteVersion $version, Collection $selectedLineIds, array $submittedIds): Collection
    {
        $submitted = collect($submittedIds)->map(fn ($id): int => (int) $id)->unique();

        return $version->acknowledgements
            ->filter(fn (SalesQuoteAcknowledgement $acknowledgement): bool => $acknowledgement->quote_line_id === null || $selectedLineIds->contains((int) $acknowledgement->quote_line_id))
            ->map(function (SalesQuoteAcknowledgement $acknowledgement) use ($submitted): array {
                if ($acknowledgement->is_required && ! $submitted->contains((int) $acknowledgement->id)) {
                    throw ValidationException::withMessages(['acknowledgement_ids' => 'Required quote acknowledgements must be accepted.']);
                }

                return [
                    'id' => $acknowledgement->id,
                    'quote_line_id' => $acknowledgement->quote_line_id,
                    'title' => $acknowledgement->title,
                    'body' => $acknowledgement->body,
                    'is_required' => $acknowledgement->is_required,
                    'accepted' => $submitted->contains((int) $acknowledgement->id),
                ];
            })
            ->values();
    }

    private function lineSnapshot(SalesQuoteLine $line, float $quantity, bool $selected = true): array
    {
        $base = (float) $line->unit_price_ex_vat * $quantity;
        $discount = $line->discount_type === 'percent'
            ? $base * ((float) $line->discount_value / 100)
            : (float) $line->discount_value;
        $lineTotal = max(0, $base - $discount);
        $vatAmount = $line->vat_rate !== null ? $lineTotal * ((float) $line->vat_rate / 100) : 0;

        return [
            'id' => $line->id,
            'selected' => $selected,
            'option_group_id' => $line->option_group_id,
            'section' => $line->section,
            'source_type' => $line->source_type,
            'source_id' => $line->source_id,
            'downstream_type' => $line->downstream_type,
            'billing_cadence' => $line->billing_cadence,
            'sku' => $line->sku,
            'name' => $line->customer_label ?: $line->name,
            'internal_name' => $line->name,
            'description' => $line->description,
            'quantity' => round($quantity, 2),
            'unit' => $line->unit,
            'unit_price_ex_vat' => round((float) $line->unit_price_ex_vat, 2),
            'discount_value' => round((float) $line->discount_value, 2),
            'discount_type' => $line->discount_type,
            'vat_rate' => $line->vat_rate !== null ? round((float) $line->vat_rate, 2) : null,
            'line_total_ex_vat' => round($lineTotal, 2),
            'vat_amount' => round($vatAmount, 2),
            'line_total_inc_vat' => round($lineTotal + $vatAmount, 2),
            'source_snapshot' => $line->snapshot,
        ];
    }

    private function totals(Collection $selectedLines): array
    {
        $cadenceTotals = $selectedLines
            ->groupBy('billing_cadence')
            ->map(fn (Collection $lines): array => [
                'total_ex_vat' => round($lines->sum('line_total_ex_vat'), 2),
                'vat_total' => round($lines->sum('vat_amount'), 2),
                'total_inc_vat' => round($lines->sum('line_total_inc_vat'), 2),
            ])
            ->all();

        return [
            'cadences' => $cadenceTotals,
            'total_ex_vat' => round($selectedLines->sum('line_total_ex_vat'), 2),
            'vat_total' => round($selectedLines->sum('vat_amount'), 2),
            'total_inc_vat' => round($selectedLines->sum('line_total_inc_vat'), 2),
        ];
    }
}
