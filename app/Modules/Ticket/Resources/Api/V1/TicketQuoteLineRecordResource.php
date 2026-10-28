<?php

namespace App\Modules\Ticket\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

/** Explicit operational read-back excludes arbitrary snapshots, fingerprints and public-access tokens. */
final class TicketQuoteLineRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $result = Arr::only($this->resource->attributesToArray(), ['id', 'quote_version_id', 'option_group_id', 'section', 'sort_order', 'source_type', 'source_id', 'downstream_type', 'billing_cadence', 'is_optional', 'sku', 'name', 'description', 'quantity', 'unit', 'unit_cost_ex_vat', 'unit_price_ex_vat', 'discount_value', 'discount_type', 'vat_rate', 'line_total_ex_vat', 'vat_amount', 'line_total_inc_vat', 'margin_amount', 'margin_percent', 'is_required', 'is_recommended', 'customer_selected_by_default', 'customer_quantity_editable', 'min_customer_quantity', 'max_customer_quantity', 'customer_label', 'created_at', 'updated_at']);

        return $result;
    }
}
