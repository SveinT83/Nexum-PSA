<?php

namespace App\Modules\Sales\Actions;

use App\Models\Core\User;
use App\Modules\Sales\Models\SalesActivity;
use App\Modules\Sales\Models\SalesQuoteAcknowledgement;
use App\Modules\Sales\Models\SalesQuoteLine;
use App\Modules\Sales\Models\SalesQuoteOptionGroup;
use App\Modules\Sales\Models\SalesQuoteTemplate;
use App\Modules\Sales\Models\SalesQuoteTemplateAcknowledgement;
use App\Modules\Sales\Models\SalesQuoteTemplateLine;
use App\Modules\Sales\Models\SalesQuoteTemplateOptionGroup;
use App\Modules\Sales\Models\SalesQuoteVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApplySalesQuoteTemplate
{
    public function __construct(private readonly RecalculateSalesQuoteVersion $recalculateQuoteVersion) {}

    public function handle(SalesQuoteVersion $version, SalesQuoteTemplate $template, User $actor, bool $replaceExisting = false): SalesQuoteVersion
    {
        $version->loadMissing('quote.opportunity', 'lines', 'optionGroups', 'acknowledgements');
        $template->loadMissing(['optionGroups', 'lines.optionGroup', 'acknowledgements']);

        if (! $version->isEditable()) {
            throw ValidationException::withMessages(['template_id' => 'Only draft quotes can use a quote template.']);
        }

        if (! $template->is_active) {
            throw ValidationException::withMessages(['template_id' => 'Only active quote templates can be applied.']);
        }

        $hadExistingLines = $version->lines()->exists();

        if (! $replaceExisting && $hadExistingLines) {
            throw ValidationException::withMessages(['replace_existing' => 'Choose replace existing lines or apply the template before adding manual lines.']);
        }

        return DB::transaction(function () use ($version, $template, $actor, $replaceExisting, $hadExistingLines): SalesQuoteVersion {
            if ($replaceExisting) {
                $version->acknowledgements()->delete();
                $version->lines()->delete();
                $version->optionGroups()->delete();
            }

            $groupMap = [];

            $template->optionGroups->each(function (SalesQuoteTemplateOptionGroup $templateGroup) use ($version, &$groupMap): void {
                $group = SalesQuoteOptionGroup::query()->create([
                    'quote_version_id' => $version->id,
                    'name' => $templateGroup->name,
                    'type' => $templateGroup->type,
                    'description' => $templateGroup->description,
                    'min_select' => $templateGroup->min_select,
                    'max_select' => $templateGroup->max_select,
                    'sort_order' => $templateGroup->sort_order,
                ]);

                $groupMap[$templateGroup->id] = $group->id;
            });

            $lineMap = [];

            $template->lines->each(function (SalesQuoteTemplateLine $templateLine) use ($version, $template, &$groupMap, &$lineMap): void {
                $line = SalesQuoteLine::query()->create([
                    'quote_version_id' => $version->id,
                    'option_group_id' => $templateLine->template_option_group_id ? ($groupMap[$templateLine->template_option_group_id] ?? null) : null,
                    'section' => $templateLine->section,
                    'sort_order' => $templateLine->sort_order,
                    'source_type' => $templateLine->source_type,
                    'source_id' => $templateLine->source_id,
                    'downstream_type' => $templateLine->downstream_type,
                    'billing_cadence' => $templateLine->billing_cadence,
                    'is_optional' => ! $templateLine->is_required,
                    'sku' => $templateLine->sku,
                    'name' => $templateLine->name,
                    'description' => $templateLine->description,
                    'quantity' => $templateLine->quantity,
                    'unit' => $templateLine->unit,
                    'unit_cost_ex_vat' => $templateLine->unit_cost_ex_vat,
                    'unit_price_ex_vat' => $templateLine->unit_price_ex_vat,
                    'discount_value' => $templateLine->discount_value,
                    'discount_type' => $templateLine->discount_type,
                    'vat_rate' => $templateLine->vat_rate,
                    'is_required' => $templateLine->is_required,
                    'is_recommended' => $templateLine->is_recommended,
                    'customer_selected_by_default' => $templateLine->customer_selected_by_default,
                    'customer_quantity_editable' => $templateLine->customer_quantity_editable,
                    'min_customer_quantity' => $templateLine->min_customer_quantity,
                    'max_customer_quantity' => $templateLine->max_customer_quantity,
                    'customer_label' => $templateLine->customer_label,
                    'snapshot' => [
                        'template_line_id' => $templateLine->id,
                        'template_key' => $template->template_key,
                        'source_snapshot' => $templateLine->source_snapshot ?: [],
                    ],
                ]);

                $lineMap[$templateLine->id] = $line->id;
            });

            $template->acknowledgements->each(function (SalesQuoteTemplateAcknowledgement $acknowledgement) use ($version, &$lineMap): void {
                SalesQuoteAcknowledgement::query()->create([
                    'quote_version_id' => $version->id,
                    'quote_line_id' => $acknowledgement->template_line_id ? ($lineMap[$acknowledgement->template_line_id] ?? null) : null,
                    'title' => $acknowledgement->title,
                    'body' => $acknowledgement->body,
                    'is_required' => $acknowledgement->is_required,
                    'source_type' => 'quote_template',
                    'source_id' => $acknowledgement->id,
                    'sort_order' => $acknowledgement->sort_order,
                ]);
            });

            $textFields = ['intro_text', 'scope_text', 'assumptions_text', 'exclusions_text', 'next_steps_text'];
            $updates = [
                'source_template_id' => $template->id,
                'template_snapshot' => $template->snapshot(),
                'approval_status' => 'not_required',
                'approval_required_reasons' => null,
                'approval_policy_snapshot' => null,
                'approval_requested_at' => null,
                'approval_requested_by' => null,
                'approval_decided_at' => null,
                'approval_decided_by' => null,
                'approval_decision_note' => null,
                'updated_by' => $actor->id,
            ];

            foreach ($textFields as $field) {
                if ($replaceExisting || $this->shouldCopyTemplateText($version, $field, $hadExistingLines)) {
                    $updates[$field] = $template->{$field};
                }
            }

            $version->forceFill($updates)->save();

            $this->recalculateQuoteVersion->handle($version);

            SalesActivity::query()->create([
                'opportunity_id' => $version->quote->opportunity_id,
                'actor_id' => $actor->id,
                'type' => 'quote_template_applied',
                'subject' => 'Quote template applied',
                'body' => $template->name.' was copied into quote '.$version->quote->quote_key.' v'.$version->version_number.'.',
                'metadata' => [
                    'quote_version_id' => $version->id,
                    'quote_template_id' => $template->id,
                    'quote_template_key' => $template->template_key,
                    'replace_existing' => $replaceExisting,
                ],
            ]);

            return $version->fresh(['lines.optionGroup', 'optionGroups', 'acknowledgements']);
        });
    }

    private function shouldCopyTemplateText(SalesQuoteVersion $version, string $field, bool $hadExistingLines): bool
    {
        if (! filled($version->{$field})) {
            return true;
        }

        if ($hadExistingLines) {
            return false;
        }

        return (string) $version->{$field} === (string) $this->defaultTextForField($version, $field);
    }

    private function defaultTextForField(SalesQuoteVersion $version, string $field): ?string
    {
        return match ($field) {
            'intro_text' => 'Thank you for the opportunity to provide this quote.',
            'scope_text' => $version->quote?->opportunity?->needs,
            'assumptions_text' => 'Prices are shown excluding VAT unless otherwise stated.',
            'next_steps_text' => 'Please accept the quote or ask a question if anything should be clarified.',
            default => null,
        };
    }
}
