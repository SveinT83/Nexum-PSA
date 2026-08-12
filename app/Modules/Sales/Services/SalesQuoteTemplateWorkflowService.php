<?php

namespace App\Modules\Sales\Services;

use App\Models\Core\User;
use App\Modules\Commercial\Models\Packages\Package;
use App\Modules\Commercial\Models\Services\Services;
use App\Modules\Commercial\Models\TimeRate;
use App\Modules\Sales\Actions\EnsureSalesDefaults;
use App\Modules\Sales\Models\SalesQuoteOptionGroup;
use App\Modules\Sales\Models\SalesQuoteTemplate;
use App\Modules\Sales\Models\SalesQuoteTemplateAcknowledgement;
use App\Modules\Sales\Models\SalesQuoteTemplateLine;
use App\Modules\Sales\Models\SalesQuoteTemplateOptionGroup;
use App\Modules\Sales\Support\SalesQuotePresentation;
use App\Modules\Storage\Models\Item as StorageItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SalesQuoteTemplateWorkflowService
{
    public function catalog(): array
    {
        return [
            'target_types' => $this->targetTypes(),
            'customer_segments' => $this->customerSegments(),
            'source_types' => $this->sourceTypes(),
            'line_catalogs' => $this->lineCatalogs(),
            'sections' => $this->sections(),
            'downstream_types' => $this->downstreamTypes(),
            'billing_cadences' => SalesQuotePresentation::CADENCES,
            'option_group_types' => SalesQuoteOptionGroup::TYPES,
        ];
    }

    public function templateRules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'is_active' => 'nullable|boolean',
            'target_type' => ['nullable', Rule::in(array_keys($this->targetTypes()))],
            'customer_segment' => ['nullable', Rule::in(array_keys($this->customerSegments()))],
            'intro_text' => 'nullable|string',
            'scope_text' => 'nullable|string',
            'assumptions_text' => 'nullable|string',
            'exclusions_text' => 'nullable|string',
            'next_steps_text' => 'nullable|string',
            'seller_checklist' => 'nullable|array',
            'seller_checklist.*' => 'string|max:500',
            'seller_checklist_text' => 'nullable|string',
            'approval_policy_hints' => 'nullable|array',
            'approval_policy_hints.*' => 'string|max:500',
            'approval_policy_hints_text' => 'nullable|string',
        ];
    }

    public function lineRules(SalesQuoteTemplate $template): array
    {
        return [
            'source_reference' => 'nullable|string|max:100',
            'option_group_id' => ['nullable', Rule::exists('sales_quote_template_option_groups', 'id')->where('template_id', $template->id)],
            'option_group_name' => 'nullable|string|max:255',
            'option_group_type' => ['nullable', Rule::in(array_keys(SalesQuoteOptionGroup::TYPES))],
            'option_group_description' => 'nullable|string',
            'option_group_min_select' => 'nullable|integer|min:0',
            'option_group_max_select' => 'nullable|integer|min:1',
            'section' => ['required', Rule::in(array_keys($this->sections()))],
            'sort_order' => 'nullable|integer|min:0',
            'source_type' => ['nullable', Rule::in(array_keys($this->sourceTypes()))],
            'source_id' => 'nullable|integer|min:1',
            'downstream_type' => ['required', Rule::in(array_keys($this->downstreamTypes()))],
            'billing_cadence' => ['required', Rule::in(array_keys(SalesQuotePresentation::CADENCES))],
            'is_required' => 'nullable|boolean',
            'is_recommended' => 'nullable|boolean',
            'customer_selected_by_default' => 'nullable|boolean',
            'customer_quantity_editable' => 'nullable|boolean',
            'min_customer_quantity' => 'nullable|numeric|min:0.01',
            'max_customer_quantity' => 'nullable|numeric|min:0.01|gte:min_customer_quantity',
            'customer_label' => 'nullable|string|max:255',
            'sku' => 'nullable|string|max:255',
            'name' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'quantity' => 'required|numeric|min:0.01',
            'unit' => 'nullable|string|max:50',
            'unit_cost_ex_vat' => 'nullable|numeric|min:0',
            'unit_price_ex_vat' => 'nullable|numeric|min:0',
            'discount_value' => 'nullable|numeric|min:0',
            'discount_type' => ['required', Rule::in(['amount', 'percent'])],
            'vat_rate' => 'nullable|numeric|min:0|max:100',
        ];
    }

    public function acknowledgementRules(SalesQuoteTemplate $template): array
    {
        return [
            'template_line_id' => ['nullable', Rule::exists('sales_quote_template_lines', 'id')->where('template_id', $template->id)],
            'title' => 'required|string|max:255',
            'body' => 'required|string',
            'is_required' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0',
        ];
    }

    public function createTemplate(array $data, ?User $actor): SalesQuoteTemplate
    {
        $data = $this->templatePayload($data, $actor, creating: true);
        $data['template_key'] = $this->uniqueTemplateKey($data['name']);
        $data['created_by'] = $actor?->id;

        return SalesQuoteTemplate::query()->create($data);
    }

    public function updateTemplate(SalesQuoteTemplate $template, array $data, ?User $actor): SalesQuoteTemplate
    {
        $template->update($this->templatePayload($data, $actor, creating: false));

        return $template->refresh();
    }

    public function createLine(SalesQuoteTemplate $template, array $data): SalesQuoteTemplateLine
    {
        $data = $this->normalizeLineSource($data);
        $group = $this->resolveTemplateOptionGroup($template, $data);
        $linePayload = $this->templateLinePayload($data);

        return $template->lines()->create(array_merge($linePayload, [
            'template_option_group_id' => $group?->id,
            'section' => $data['section'],
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'downstream_type' => $data['downstream_type'],
            'billing_cadence' => $data['billing_cadence'],
            'is_required' => (bool) ($data['is_required'] ?? false),
            'is_recommended' => (bool) ($data['is_recommended'] ?? false),
            'customer_selected_by_default' => (bool) ($data['customer_selected_by_default'] ?? false),
            'customer_quantity_editable' => (bool) ($data['customer_quantity_editable'] ?? false),
            'min_customer_quantity' => $data['min_customer_quantity'] ?? $data['quantity'],
            'max_customer_quantity' => $data['max_customer_quantity'] ?? $data['quantity'],
            'customer_label' => $data['customer_label'] ?? null,
            'quantity' => $data['quantity'],
            'discount_value' => $data['discount_value'] ?? 0,
            'discount_type' => $data['discount_type'],
        ]));
    }

    public function createAcknowledgement(SalesQuoteTemplate $template, array $data): SalesQuoteTemplateAcknowledgement
    {
        return $template->acknowledgements()->create([
            'template_line_id' => $data['template_line_id'] ?? null,
            'title' => $data['title'],
            'body' => $data['body'],
            'is_required' => (bool) ($data['is_required'] ?? false),
            'source_type' => 'quote_template',
            'source_id' => $template->id,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);
    }

    public function templateResource(SalesQuoteTemplate $template): array
    {
        $template->loadMissing(['optionGroups.lines', 'lines.optionGroup', 'acknowledgements.line']);

        return [
            'id' => $template->id,
            'template_key' => $template->template_key,
            'name' => $template->name,
            'description' => $template->description,
            'is_active' => $template->is_active,
            'target_type' => $template->target_type,
            'customer_segment' => $template->customer_segment,
            'intro_text' => $template->intro_text,
            'scope_text' => $template->scope_text,
            'assumptions_text' => $template->assumptions_text,
            'exclusions_text' => $template->exclusions_text,
            'next_steps_text' => $template->next_steps_text,
            'seller_checklist' => $template->seller_checklist ?: [],
            'approval_policy_hints' => $template->approval_policy_hints ?: [],
            'option_groups' => $template->optionGroups->map(fn (SalesQuoteTemplateOptionGroup $group): array => [
                'id' => $group->id,
                'name' => $group->name,
                'type' => $group->type,
                'description' => $group->description,
                'min_select' => $group->min_select,
                'max_select' => $group->max_select,
                'sort_order' => $group->sort_order,
                'lines_count' => $group->lines->count(),
            ])->values()->all(),
            'lines' => $template->lines->map(fn (SalesQuoteTemplateLine $line): array => $this->lineResource($line))->values()->all(),
            'acknowledgements' => $template->acknowledgements
                ->map(fn (SalesQuoteTemplateAcknowledgement $acknowledgement): array => $this->acknowledgementResource($acknowledgement))
                ->values()
                ->all(),
            'updated_at' => $template->updated_at?->toISOString(),
        ];
    }

    public function lineResource(SalesQuoteTemplateLine $line): array
    {
        return [
            'id' => $line->id,
            'template_id' => $line->template_id,
            'template_option_group_id' => $line->template_option_group_id,
            'option_group_name' => $line->optionGroup?->name,
            'section' => $line->section,
            'sort_order' => $line->sort_order,
            'source_type' => $line->source_type,
            'source_id' => $line->source_id,
            'source_reference' => $line->source_id ? $line->source_type.':'.$line->source_id : 'custom',
            'downstream_type' => $line->downstream_type,
            'billing_cadence' => $line->billing_cadence,
            'is_required' => $line->is_required,
            'is_recommended' => $line->is_recommended,
            'customer_selected_by_default' => $line->customer_selected_by_default,
            'customer_quantity_editable' => $line->customer_quantity_editable,
            'min_customer_quantity' => $line->min_customer_quantity,
            'max_customer_quantity' => $line->max_customer_quantity,
            'customer_label' => $line->customer_label,
            'sku' => $line->sku,
            'name' => $line->name,
            'description' => $line->description,
            'quantity' => $line->quantity,
            'unit' => $line->unit,
            'unit_cost_ex_vat' => $line->unit_cost_ex_vat,
            'unit_price_ex_vat' => $line->unit_price_ex_vat,
            'discount_value' => $line->discount_value,
            'discount_type' => $line->discount_type,
            'vat_rate' => $line->vat_rate,
        ];
    }

    public function acknowledgementResource(SalesQuoteTemplateAcknowledgement $acknowledgement): array
    {
        return [
            'id' => $acknowledgement->id,
            'template_id' => $acknowledgement->template_id,
            'template_line_id' => $acknowledgement->template_line_id,
            'line_name' => $acknowledgement->line?->name,
            'title' => $acknowledgement->title,
            'body' => $acknowledgement->body,
            'is_required' => $acknowledgement->is_required,
            'sort_order' => $acknowledgement->sort_order,
        ];
    }

    public function targetTypes(): array
    {
        return EnsureSalesDefaults::TYPES;
    }

    public function customerSegments(): array
    {
        return [
            'general' => 'General',
            'smb' => 'SMB',
            'enterprise' => 'Enterprise',
            'public_sector' => 'Public sector',
            'non_profit' => 'Non-profit',
        ];
    }

    public function sourceTypes(): array
    {
        return [
            'custom' => 'Custom',
            'service' => 'Commercial service',
            'package' => 'Commercial package',
            'time_rate' => 'Commercial time rate',
            'storage_item' => 'Storage item',
        ];
    }

    /** @return array<string, Collection<int, array<string, mixed>>> */
    public function lineCatalogs(): array
    {
        return [
            'service' => Services::query()
                ->with(['costRelations.cost'])
                ->where(fn ($query) => $query->where('status', 'active')->orWhereNull('status'))
                ->orderBy('name')
                ->get()
                ->map(fn (Services $service): array => [
                    'value' => 'service:'.$service->id,
                    'label' => trim(($service->sku ? $service->sku.' - ' : '').$service->name),
                    'name' => $service->name,
                    'description' => $service->short_description,
                    'price' => $service->price_ex_vat,
                    'cost' => $service->costRelations->sum(fn ($relation) => (float) ($relation->cost?->cost ?? 0)),
                    'vat' => 25,
                ])
                ->values(),
            'package' => Package::query()
                ->with(['services.costRelations.cost'])
                ->where(fn ($query) => $query->where('status', 'active')->orWhereNull('status'))
                ->orderBy('name')
                ->get()
                ->map(fn (Package $package): array => [
                    'value' => 'package:'.$package->id,
                    'label' => $package->name,
                    'name' => $package->name,
                    'description' => $package->description,
                    'price' => $package->sales_price_client,
                    'cost' => $package->services->sum(fn ($service) => $service->costRelations->sum(fn ($relation) => (float) ($relation->cost?->cost ?? 0))),
                    'vat' => 25,
                ])
                ->values(),
            'time_rate' => TimeRate::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map(fn (TimeRate $rate): array => [
                    'value' => 'time_rate:'.$rate->id,
                    'label' => trim(($rate->code ? $rate->code.' - ' : '').$rate->name),
                    'name' => $rate->name,
                    'description' => $rate->description,
                    'price' => $rate->amount_ex_vat,
                    'cost' => null,
                    'vat' => 25,
                ])
                ->values(),
            'storage_item' => StorageItem::query()
                ->where('status', 'active')
                ->orderBy('name')
                ->limit(200)
                ->get()
                ->map(fn (StorageItem $item): array => [
                    'value' => 'storage_item:'.$item->id,
                    'label' => trim(($item->sku ? $item->sku.' - ' : '').$item->name),
                    'name' => $item->name,
                    'description' => $item->short_description,
                    'price' => $item->sale_price,
                    'cost' => $item->purchase_price,
                    'vat' => $item->vat_rate,
                ])
                ->values(),
        ];
    }

    public function sections(): array
    {
        return [
            'monthly_services' => 'Monthly services',
            'one_time_costs' => 'One-time costs',
            'equipment' => 'Equipment',
            'implementation' => 'Implementation',
            'optional' => 'Optional',
        ];
    }

    public function downstreamTypes(): array
    {
        return [
            'recurring_contract' => 'Contract',
            'one_time_order' => 'Order',
            'equipment' => 'Equipment',
            'implementation' => 'Implementation',
            'non_billable' => 'Non-billable',
        ];
    }

    private function templatePayload(array $data, ?User $actor, bool $creating): array
    {
        $payload = [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? false),
            'target_type' => $data['target_type'] ?? null,
            'customer_segment' => $data['customer_segment'] ?? 'general',
            'intro_text' => $data['intro_text'] ?? null,
            'scope_text' => $data['scope_text'] ?? null,
            'assumptions_text' => $data['assumptions_text'] ?? null,
            'exclusions_text' => $data['exclusions_text'] ?? null,
            'next_steps_text' => $data['next_steps_text'] ?? null,
            'seller_checklist' => $this->listInput($data, 'seller_checklist', 'seller_checklist_text'),
            'approval_policy_hints' => $this->listInput($data, 'approval_policy_hints', 'approval_policy_hints_text'),
            'updated_by' => $actor?->id,
        ];

        if ($creating) {
            $payload['created_by'] = $actor?->id;
        }

        return $payload;
    }

    private function listInput(array $data, string $arrayKey, string $textKey): array
    {
        if (array_key_exists($arrayKey, $data) && is_array($data[$arrayKey])) {
            return collect($data[$arrayKey])
                ->map(fn (mixed $line): string => trim((string) $line))
                ->filter()
                ->values()
                ->all();
        }

        return $this->linesFromTextarea($data[$textKey] ?? null);
    }

    private function resolveTemplateOptionGroup(SalesQuoteTemplate $template, array $data): ?SalesQuoteTemplateOptionGroup
    {
        if (! empty($data['option_group_id'])) {
            return SalesQuoteTemplateOptionGroup::query()
                ->where('template_id', $template->id)
                ->findOrFail($data['option_group_id']);
        }

        if (! filled($data['option_group_name'] ?? null)) {
            return null;
        }

        return SalesQuoteTemplateOptionGroup::query()->updateOrCreate(
            [
                'template_id' => $template->id,
                'name' => $data['option_group_name'],
            ],
            [
                'type' => $data['option_group_type'] ?? 'optional',
                'description' => $data['option_group_description'] ?? null,
                'min_select' => (int) ($data['option_group_min_select'] ?? 0),
                'max_select' => $data['option_group_max_select'] ?? null,
            ],
        );
    }

    private function normalizeLineSource(array $data): array
    {
        if (filled($data['source_reference'] ?? null)) {
            $reference = (string) $data['source_reference'];

            if ($reference === 'custom') {
                $data['source_type'] = 'custom';
                $data['source_id'] = null;
            } else {
                [$sourceType, $sourceId] = array_pad(explode(':', $reference, 2), 2, null);

                if (! array_key_exists((string) $sourceType, $this->sourceTypes()) || ! ctype_digit((string) $sourceId)) {
                    throw ValidationException::withMessages([
                        'source_reference' => 'Select a valid catalog source.',
                    ]);
                }

                $data['source_type'] = $sourceType;
                $data['source_id'] = (int) $sourceId;
            }
        }

        $data['source_type'] = $data['source_type'] ?? 'custom';

        if ($data['source_type'] === 'custom' && ! filled($data['name'] ?? null)) {
            throw ValidationException::withMessages([
                'name' => 'Custom template lines need a line name.',
            ]);
        }

        return $data;
    }

    private function templateLinePayload(array $data): array
    {
        $base = [
            'source_type' => $data['source_type'],
            'source_id' => $data['source_id'] ?? null,
            'sku' => $data['sku'] ?? null,
            'name' => $data['name'] ?? 'Template line',
            'description' => $data['description'] ?? null,
            'unit' => $data['unit'] ?? null,
            'unit_cost_ex_vat' => $data['unit_cost_ex_vat'] ?? 0,
            'unit_price_ex_vat' => $data['unit_price_ex_vat'] ?? 0,
            'vat_rate' => $data['vat_rate'] ?? null,
            'source_snapshot' => [
                'configured_source_type' => $data['source_type'],
                'configured_source_id' => $data['source_id'] ?? null,
                'configured_at' => now()->toISOString(),
            ],
        ];

        if ($data['source_type'] === 'service' && ! empty($data['source_id'])) {
            $service = Services::with(['costRelations.cost'])->findOrFail($data['source_id']);
            $cost = $service->costRelations->sum(fn ($relation) => (float) ($relation->cost?->cost ?? 0));

            return array_merge($base, [
                'sku' => $service->sku,
                'name' => ($data['name'] ?? null) ?: $service->name,
                'description' => $data['description'] ?? $service->short_description,
                'unit_price_ex_vat' => $data['unit_price_ex_vat'] ?? $service->price_ex_vat ?? 0,
                'unit_cost_ex_vat' => $data['unit_cost_ex_vat'] ?? $cost,
                'vat_rate' => $data['vat_rate'] ?? 25,
                'source_snapshot' => array_merge($base['source_snapshot'], $service->only(['id', 'name', 'sku', 'price_ex_vat', 'billing_cycle']), [
                    'cost_ex_vat' => $cost,
                ]),
            ]);
        }

        if ($data['source_type'] === 'package' && ! empty($data['source_id'])) {
            $package = Package::with(['services.costRelations.cost'])->findOrFail($data['source_id']);
            $cost = $package->services->sum(fn ($service) => $service->costRelations->sum(fn ($relation) => (float) ($relation->cost?->cost ?? 0)));

            return array_merge($base, [
                'name' => ($data['name'] ?? null) ?: $package->name,
                'description' => $data['description'] ?? $package->description,
                'unit_price_ex_vat' => $data['unit_price_ex_vat'] ?? $package->sales_price_client ?? 0,
                'unit_cost_ex_vat' => $data['unit_cost_ex_vat'] ?? $cost,
                'vat_rate' => $data['vat_rate'] ?? 25,
                'source_snapshot' => array_merge($base['source_snapshot'], $package->only(['id', 'name', 'description']), [
                    'cost_ex_vat' => $cost,
                ]),
            ]);
        }

        if ($data['source_type'] === 'time_rate' && ! empty($data['source_id'])) {
            $rate = TimeRate::findOrFail($data['source_id']);

            return array_merge($base, [
                'name' => ($data['name'] ?? null) ?: $rate->name,
                'description' => $data['description'] ?? $rate->description,
                'unit' => $rate->unit,
                'unit_price_ex_vat' => $data['unit_price_ex_vat'] ?? $rate->amount_ex_vat ?? 0,
                'vat_rate' => $data['vat_rate'] ?? 25,
                'source_snapshot' => array_merge($base['source_snapshot'], $rate->only(['id', 'name', 'code', 'amount_ex_vat', 'unit'])),
            ]);
        }

        if ($data['source_type'] === 'storage_item' && ! empty($data['source_id'])) {
            $item = StorageItem::findOrFail($data['source_id']);

            return array_merge($base, [
                'sku' => $item->sku,
                'name' => ($data['name'] ?? null) ?: $item->name,
                'description' => $data['description'] ?? $item->short_description,
                'unit_price_ex_vat' => $data['unit_price_ex_vat'] ?? $item->sale_price ?? 0,
                'unit_cost_ex_vat' => $data['unit_cost_ex_vat'] ?? $item->purchase_price ?? 0,
                'vat_rate' => $data['vat_rate'] ?? $item->vat_rate ?? 25,
                'source_snapshot' => array_merge($base['source_snapshot'], $item->only(['id', 'sku', 'name', 'sale_price', 'purchase_price', 'vat_rate'])),
            ]);
        }

        return $base;
    }

    private function linesFromTextarea(?string $value): array
    {
        return collect(preg_split('/\r\n|\r|\n/', (string) $value))
            ->map(fn (string $line): string => trim($line))
            ->filter()
            ->values()
            ->all();
    }

    private function uniqueTemplateKey(string $name): string
    {
        $base = Str::upper(Str::slug($name, '_')) ?: 'QUOTE_TEMPLATE';
        $key = $base;
        $counter = 2;

        while (SalesQuoteTemplate::query()->where('template_key', $key)->exists()) {
            $key = $base.'_'.$counter++;
        }

        return $key;
    }
}
