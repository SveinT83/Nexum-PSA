<?php

namespace App\Modules\Commercial\Support;

use App\Modules\Commercial\Models\Packages\Package;
use App\Modules\Commercial\Models\Services\Services;
use App\Modules\Commercial\Models\Terms\terms;
use Illuminate\Database\Eloquent\Model;

/** Staff projections use explicit columns; customer documents use a separate immutable projector. */
class CommercialProjection
{
    public static function catalogue(Model $record): array
    {
        $columns = match ($record->getTable()) {
            'services' => ['id', 'sku', 'name', 'unitId', 'category_id', 'sla_id', 'status', 'icon', 'availability_addon_of_service_id', 'availability_audience', 'orderable', 'taxable', 'setup_fee', 'billing_cycle', 'price_ex_vat', 'price_including_tax', 'one_time_fee', 'one_time_fee_recurrence', 'recurrence_value_x', 'default_discount_value', 'default_discount_type', 'timebank_enabled', 'timebank_minutes', 'timebank_interval', 'short_description', 'long_description', 'customer_unit_singular', 'customer_unit_plural', 'price_currency', 'source', 'source_integration_id', 'managed_externally'],
            'packages' => ['id', 'name', 'description', 'status', 'sales_price_user', 'sales_price_asset', 'sales_price_site', 'sales_price_client', 'sales_price_other'],
            'costs' => ['id', 'name', 'cost', 'currency', 'unitId', 'recurrence', 'vendor_id', 'note', 'source', 'source_integration_id', 'managed_externally'],
            'terms' => ['id', 'name', 'type', 'content', 'origin', 'issuer', 'source_url', 'managed_externally', 'source_integration_id', 'current_version_id', 'sync_status', 'last_checked_at'],
            'units' => ['id', 'name', 'short', 'common_code'],
            'time_rates' => ['id', 'name', 'code', 'slug', 'rate_type', 'unit', 'amount_ex_vat', 'currency', 'description', 'applies_without_contract', 'applies_with_contract', 'is_active', 'is_customer_visible', 'sort_order'],
            'sla' => ['id', 'name', 'description', 'is_default', 'low_firstResponse', 'low_firstResponse_type', 'low_onsite', 'low_onsite_type', 'medium_firstResponse', 'medium_firstResponse_type', 'medium_onsite', 'medium_onsite_type', 'high_firstResponse', 'high_firstResponse_type', 'high_onsite', 'high_onsite_type'],
            default => throw new \LogicException('Unsupported Commercial projection'),
        };
        $data = $record->only($columns) + $record->only(['created_at', 'updated_at']);
        if ($record instanceof Services) {
            $record->load(['unit', 'sla', 'sourceIntegration', 'costRelations.cost', 'serviceTerms', 'serviceTimeRates.timeRate']);
            $data['unit'] = $record->unit?->only(['id', 'name', 'short']);
            $data['sla'] = $record->sla?->only(['id', 'name', 'is_default']);
            $data['source_integration'] = $record->sourceIntegration?->only(['id', 'name', 'type']);
            $data['costs'] = $record->costRelations->map(fn ($row) => $row->cost?->only(['id', 'name', 'cost', 'currency', 'unitId']))->filter()->values()->all();
            $data['terms'] = $record->serviceTerms->map(fn ($row) => $row->only(['id', 'name', 'type', 'current_version_id']))->all();
            $data['time_rates'] = $record->serviceTimeRates->map(fn ($row) => ['id' => $row->id, 'time_rate_id' => $row->time_rate_id, 'amount_ex_vat' => $row->amount_ex_vat, 'is_active' => $row->is_active, 'rate' => $row->timeRate?->only(['id', 'name', 'code', 'amount_ex_vat', 'currency', 'is_customer_visible'])])->all();
            $data['integration_managed'] = $record->isIntegrationManaged();
            $data['is_integration_managed'] = $data['integration_managed'];
            $data['unit_id'] = $record->unitId;
            $data['vendor_id'] = $record->vendor_id;
        }
        if ($record instanceof Package) {
            $data['inherited_terms'] = $record->services()->with('serviceTerms')->get()->flatMap(fn ($s) => $s->serviceTerms)->unique('id')->values()->map(fn ($term) => $term->only(['id', 'name', 'type', 'current_version_id']))->all();
            $data['services'] = $record->services()->orderBy('services.id')->get(['services.id', 'services.name'])->map(fn ($r) => $r->only(['id', 'name']))->all();
            $data['terms'] = $record->terms()->orderBy('terms.id')->get(['terms.id', 'terms.name', 'terms.type'])->map(fn ($r) => $r->only(['id', 'name', 'type']))->all();
        }
        if ($record instanceof terms) {
            $data['versions'] = $record->versions()->orderBy('id')->get()->map(fn ($v) => $v->only(['id', 'term_id', 'name', 'type', 'version_label', 'issuer', 'content', 'source_url', 'effective_at', 'checksum']))->all();
            $data['is_in_use'] = $record->isInUse();
        }
        $data['revision'] = CommercialRevision::of($record);

        return $data;
    }

    public static function item(\App\Modules\Commercial\Models\Contracts\ContractItem $item): array
    {
        return $item->only(['id', 'contract_id', 'service_id', 'name', 'sku', 'customer_description', 'customer_unit_singular', 'customer_unit_plural', 'unit_price', 'price_currency', 'quantity', 'unit', 'billing_interval', 'discount_value', 'discount_type', 'setup_fee', 'sla_id', 'uses_contract_default_sla', 'sla_snapshot', 'cost_unit_price', 'cost_currency', 'source', 'cloudfactory_offer_id']) + ['time_rates' => $item->timeRates()->get()->map(fn ($r) => $r->only(['id', 'time_rate_id', 'service_time_rate_id', 'name', 'code', 'rate_type', 'unit', 'amount_ex_vat', 'currency', 'is_active', 'is_customer_visible', 'sort_order']))->all()];
    }
}
