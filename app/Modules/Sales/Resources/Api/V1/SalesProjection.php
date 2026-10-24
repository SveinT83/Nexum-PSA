<?php

namespace App\Modules\Sales\Resources\Api\V1;

use App\Modules\Sales\Models\SalesActivity;
use App\Modules\Sales\Models\SalesOpportunity;
use App\Modules\Sales\Models\SalesQuoteVersion;
use App\Modules\Sales\Support\SalesQuotePresentation;
use App\Modules\Sales\Support\SalesRevision;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/** Explicit projections prevent model metadata/provider secrets from escaping across interfaces. */
final class SalesProjection
{
    public static function page(LengthAwarePaginator $page, callable $project): array
    {
        return ['data' => $page->getCollection()->map($project)->values()->all(), 'meta' => [
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(),
        ], 'links' => ['next' => $page->nextPageUrl(), 'prev' => $page->previousPageUrl()]];
    }

    public static function client($client): array
    {
        return $client->only(['id', 'name', 'client_number', 'website', 'sales_category_id', 'lead_temperature', 'contacts_count', 'assets_count', 'marketing_engagement_summary'])
            + ['tags' => $client->tags->map->only(['id', 'name', 'color'])->all()];
    }

    public static function activity(SalesActivity $activity): array
    {
        return $activity->only(['id', 'opportunity_id', 'actor_id', 'type', 'direction', 'subject', 'body', 'is_unread', 'read_at', 'created_at'])
            + ['metadata' => \Illuminate\Support\Arr::only($activity->metadata ?? [], ['quote_version_id', 'previous_quote_version_id', 'approval_status', 'conversion_plan_id', 'status', 'target_reference', 'recipient_contact_id', 'to_email', 'to_name', 'cc', 'notify_user_id', 'delivery_attempt_id']),
                'actor' => $activity->actor?->only(['id', 'name']),
                'delivery' => ! empty($activity->metadata['delivery_attempt_id']) && ($attempt = \App\Modules\Sales\Models\SalesDeliveryAttempt::find($activity->metadata['delivery_attempt_id'])) ? app(\App\Modules\Sales\Services\SalesCommunications::class)->project($attempt) : null];
    }

    public static function opportunity(SalesOpportunity $opportunity): array
    {
        $opportunity->loadMissing(['client', 'primaryContact', 'owner', 'stakeholders.clientUser', 'currentQuoteVersion']);
        $data = (new SalesOpportunityResource($opportunity))->resolve();
        $data['revision'] = SalesRevision::of($opportunity);
        $data['stakeholders'] = $opportunity->stakeholders->map(fn ($item) => $item->only(['id', 'client_user_id', 'role', 'is_primary', 'notes']) + ['contact' => $item->clientUser?->only(['id', 'name', 'email', 'phone'])])->all();
        $data['current_quote_version_id'] = $opportunity->current_quote_version_id;
        $data['quotes'] = $opportunity->quotes()->orderBy('id')->get()->map->only(['id', 'quote_key', 'status', 'current_version_id'])->all();
        $data['links'] += [
            'context' => url('/api/v1/sales/opportunities/'.$opportunity->opportunity_key.'/context'),
            'quote_versions' => url('/api/v1/sales/opportunities/'.$opportunity->opportunity_key.'/quote/versions'),
            'activity_list' => url('/api/v1/sales/opportunities/'.$opportunity->opportunity_key.'/activities'),
        ];

        return $data;
    }

    public static function version(SalesQuoteVersion $version, bool $customer = false): array
    {
        $version->loadMissing(['quote.opportunity.client', 'lines.optionGroup', 'optionGroups', 'acknowledgements', 'acceptanceSnapshot', 'conversionPlans']);
        $data = $version->only(['id', 'quote_id', 'version_number', 'status', 'title', 'intro_text', 'scope_text', 'assumptions_text', 'exclusions_text', 'next_steps_text', 'expires_at', 'subtotal_ex_vat', 'discount_total_ex_vat', 'vat_total', 'total_ex_vat', 'total_inc_vat', 'sent_at', 'viewed_at', 'accepted_at', 'declined_at']);
        $data['expires_at'] = $version->expires_at?->toDateString();
        $data['effective_status'] = $version->status === 'sent' && $version->expires_at?->isPast() ? 'expired' : $version->status;
        $data['can_respond'] = $data['effective_status'] === 'sent' && (int) $version->quote->opportunity->current_quote_version_id === (int) $version->id;
        $data['quote_key'] = $version->quote->quote_key;
        $data['customer'] = $version->quote->opportunity->client?->only(['id', 'name']);
        $lineFields = ['id', 'option_group_id', 'section', 'sort_order', 'billing_cadence', 'sku', 'name', 'description', 'quantity', 'unit', 'unit_price_ex_vat', 'discount_value', 'discount_type', 'vat_rate', 'line_total_ex_vat', 'vat_amount', 'line_total_inc_vat', 'is_required', 'is_optional', 'is_recommended', 'customer_selected_by_default', 'customer_quantity_editable', 'min_customer_quantity', 'max_customer_quantity', 'customer_label'];
        if (! $customer) {
            $lineFields = array_merge($lineFields, ['source_type', 'source_id', 'downstream_type', 'unit_cost_ex_vat', 'margin_amount', 'margin_percent']);
        }
        $data['lines'] = $version->lines->map->only($lineFields)->all();
        $data['option_groups'] = $version->optionGroups->map->only(['id', 'name', 'type', 'description', 'min_select', 'max_select', 'sort_order'])->all();
        $data['acknowledgements'] = $version->acknowledgements->map->only(['id', 'quote_line_id', 'title', 'body', 'is_required', 'sort_order'])->all();
        $presentation = app(SalesQuotePresentation::class)->forVersion($version);
        $data['presentation'] = \Illuminate\Support\Arr::only($presentation, ['cadences', 'cadence_totals', 'totals', 'customer_copy_html', 'customer_copy_text', 'summary_html', 'summary_text']);
        $data['presentation']['groups'] = $presentation['groups']->map(fn ($group) => \Illuminate\Support\Arr::except($group, ['lines']))->all();
        $data['presentation']['selected_line_ids'] = $presentation['selected_line_ids'];
        $data['presentation']['quantities'] = $presentation['quantities'];
        if ($version->acceptanceSnapshot) {
            $data['acceptance'] = self::acceptance($version, $customer);
        }
        if (! $customer) {
            $data += $version->only(['approval_status', 'approval_required_reasons', 'approval_policy_snapshot', 'approval_requested_at', 'approval_requested_by', 'approval_decided_at', 'approval_decided_by', 'approval_decision_note', 'margin_amount', 'margin_percent', 'source_template_id', 'created_at', 'updated_at']);
            $data['revision'] = SalesRevision::of($version);
            $data['conversion_plans'] = $version->conversionPlans->map->only(['id', 'quote_version_id', 'quote_line_id', 'target_domain', 'target_type', 'status', 'created_record_type', 'created_record_id', 'target_reference', 'operator_note', 'processed_at', 'processed_by'])->all();
        }

        return $data;
    }

    public static function acceptance(SalesQuoteVersion $version, bool $customer = false): ?array
    {
        $snapshot = $version->acceptanceSnapshot;
        if (! $snapshot) {
            return null;
        }
        $safe = $snapshot->only(['id', 'quote_version_id', 'accepted_at', 'accepted_by_name', 'selected_line_ids', 'declined_line_ids', 'totals', 'public_text_snapshot']);
        // Snapshot lines can contain internal source/cost facts; project each customer-visible field.
        $fields = ['id', 'quote_line_id', 'name', 'description', 'quantity', 'unit', 'billing_cadence', 'unit_price_ex_vat', 'discount_value', 'discount_type', 'vat_rate', 'line_total_ex_vat', 'line_total_inc_vat', 'vat_amount', 'customer_label'];
        $safe['totals'] = \Illuminate\Support\Arr::only($snapshot->totals ?? [], ['subtotal_ex_vat', 'discount_total_ex_vat', 'vat_total', 'total_ex_vat', 'total_inc_vat']);
        $safe['selected_lines'] = collect($snapshot->selected_lines)->map(fn ($line) => \Illuminate\Support\Arr::only($line, $fields))->all();
        $safe['declined_lines'] = collect($snapshot->declined_lines)->map(fn ($line) => \Illuminate\Support\Arr::only($line, $fields))->all();
        $safe['acknowledgements'] = collect($snapshot->acknowledgements)->map(fn ($item) => \Illuminate\Support\Arr::only($item, ['id', 'title', 'body', 'is_required', 'accepted']))->all();
        if (! $customer) {
            $safe += $snapshot->only(['accepted_by_email', 'source_method', 'source_user_id']);
        }

        return $safe;
    }
}
