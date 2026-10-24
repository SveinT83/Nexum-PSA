<?php

namespace App\Modules\Sales\Actions;

use App\Models\Clients\ClientUser;
use App\Models\Core\User;
use App\Modules\Commercial\Models\Packages\Package;
use App\Modules\Commercial\Models\Services\Services;
use App\Modules\Commercial\Models\TimeRate;
use App\Modules\Integration\Support\DomainApiInput;
use App\Modules\Notification\Actions\SendCustomerPortalNotification;
use App\Modules\Sales\DTOs\SalesWorkflowResult;
use App\Modules\Sales\Models\SalesActivity;
use App\Modules\Sales\Models\SalesOpportunity;
use App\Modules\Sales\Models\SalesOpportunityStakeholder;
use App\Modules\Sales\Models\SalesQuoteAcknowledgement;
use App\Modules\Sales\Models\SalesQuoteConversionPlan;
use App\Modules\Sales\Models\SalesQuoteLine;
use App\Modules\Sales\Models\SalesQuoteOptionGroup;
use App\Modules\Sales\Models\SalesQuoteTemplate;
use App\Modules\Sales\Models\SalesQuoteVersion;
use App\Modules\Sales\Support\SalesQuotePresentation;
use App\Modules\Storage\Models\Item as StorageItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Shared browser/API Sales business mutations. Transport adapters render results separately. */
class SalesWorkflow
{
    public function activityData(Request $request, SalesOpportunity $sale): array
    {
        $data = DomainApiInput::validate($request, [
            'type' => 'required|string|in:journal,internal_note,email_in,email_out',
            'subject' => 'nullable|string|max:255',
            'body' => 'required|string|max:10000',
            'recipient_contact_id' => 'nullable|exists:client_users,id',
            'to_email' => 'nullable|email|max:255',
            'cc' => 'nullable|string|max:1000',
            'notify_user_id' => ['nullable', Rule::exists((new User)->getTable(), 'id')],
        ]);

        $metadata = [];
        if ($data['type'] === 'email_out') {
            $recipient = $this->salesRecipient($sale, $data);
            $metadata = [
                'recipient_contact_id' => $recipient['contact_id'] ?? null,
                'to_email' => $recipient['email'],
                'to_name' => $recipient['name'] ?? '',
                'cc' => $this->ccRecipientsFromString($data['cc'] ?? ''),
            ];
        }

        if ($data['type'] === 'internal_note' && ! empty($data['notify_user_id'])) {
            $metadata['notify_user_id'] = (int) $data['notify_user_id'];
        }

        return [
            'opportunity_id' => $sale->id,
            'actor_id' => $request->user()->id,
            'type' => $data['type'],
            'direction' => in_array($data['type'], ['email_in', 'email_out'], true) ? ($data['type'] === 'email_in' ? 'inbound' : 'outbound') : null,
            'subject' => $data['subject'] ?? null,
            'body' => $data['body'],
            'is_unread' => $data['type'] === 'email_in',
            'read_at' => $data['type'] === 'email_in' ? null : now(),
            'metadata' => $metadata,
        ];
    }

    public function storeActivity(Request $request, SalesOpportunity $sale): SalesWorkflowResult
    {
        return DB::transaction(function () use ($request, $sale) {
            SalesOpportunity::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();
            $sale->refresh();

            $attributes = $this->activityData($request, $sale);
            $metadata = $attributes['metadata'];
            $kind = $attributes['type'] === 'email_out' ? 'activity' : ($attributes['type'] === 'internal_note' && ! empty($metadata['notify_user_id']) ? 'internal' : null);
            $communications = app(\App\Modules\Sales\Services\SalesCommunications::class);
            $previewId = $request->attributes->get('sales_delivery_preview_id');
            $frozenAttempt = $kind ? ($previewId
                ? $communications->assertPreview($previewId, $request->user(), $kind, $sale, null, $request->attributes->get('sales_delivery_preview_hash'))
                : $communications->preview($request->user(), $kind, $sale, null, $request->all())) : null;
            $activity = SalesActivity::query()->create($attributes);

            if ($activity->is_unread) {
                $sale->forceFill(['is_unread' => true])->save();
            }

            if ($frozenAttempt) {
                $communications->queue($frozenAttempt, $activity);
            }

            return new SalesWorkflowResult('success', 'Sales activity added.', null, ['activity_id' => isset($activity) ? $activity->id : null, 'line_id' => isset($line) ? $line->id : null, 'version_id' => isset($newVersion) ? $newVersion->id : (isset($version) ? $version->id : null)]);

        }, 3);
    }

    public function markRead(Request $request, SalesOpportunity $sale): SalesWorkflowResult
    {
        return DB::transaction(function () use ($sale) {
            SalesOpportunity::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();
            $sale->refresh();

            DB::transaction(function () use ($sale): void {
                SalesActivity::query()
                    ->where('opportunity_id', $sale->id)
                    ->where('is_unread', true)
                    ->update([
                        'is_unread' => false,
                        'read_at' => now(),
                    ]);

                $sale->forceFill(['is_unread' => false])->save();
            });

            return new SalesWorkflowResult('success', 'Sales activity marked as read.', null, ['activity_id' => isset($activity) ? $activity->id : null, 'line_id' => isset($line) ? $line->id : null, 'version_id' => isset($newVersion) ? $newVersion->id : (isset($version) ? $version->id : null)]);

        }, 3);
    }

    public function markActivityRead(Request $request, SalesOpportunity $sale, SalesActivity $activity): SalesWorkflowResult
    {
        return DB::transaction(function () use ($sale, $activity) {
            SalesOpportunity::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();
            $sale->refresh();

            abort_unless((int) $activity->opportunity_id === (int) $sale->id, 404);

            DB::transaction(function () use ($sale, $activity): void {
                if ($activity->is_unread) {
                    $activity->forceFill([
                        'is_unread' => false,
                        'read_at' => now(),
                    ])->save();
                }

                $hasUnreadActivity = SalesActivity::query()
                    ->where('opportunity_id', $sale->id)
                    ->where('is_unread', true)
                    ->exists();

                $sale->forceFill(['is_unread' => $hasUnreadActivity])->save();
            });

            return new SalesWorkflowResult('success', 'Sales reply marked as read.', null, ['activity_id' => isset($activity) ? $activity->id : null, 'line_id' => isset($line) ? $line->id : null, 'version_id' => isset($newVersion) ? $newVersion->id : (isset($version) ? $version->id : null)]);

        }, 3);
    }

    public function storeStakeholder(Request $request, SalesOpportunity $sale): SalesWorkflowResult
    {
        return DB::transaction(function () use ($request, $sale) {
            SalesOpportunity::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();
            $sale->refresh();

            $data = DomainApiInput::validate($request, [
                'client_user_id' => 'required|exists:client_users,id',
                'role' => 'required|string|max:100',
                'is_primary' => 'nullable|boolean',
                'notes' => 'nullable|string|max:1000',
            ]);

            $contact = ClientUser::query()->whereHas('site', fn ($query) => $query->where('client_id', $sale->client_id))->findOrFail($data['client_user_id']);

            if ($request->boolean('is_primary')) {
                SalesOpportunityStakeholder::query()->where('opportunity_id', $sale->id)->update(['is_primary' => false]);
                $sale->forceFill(['primary_contact_id' => $contact->id])->save();
            }

            SalesOpportunityStakeholder::query()->updateOrCreate(
                ['opportunity_id' => $sale->id, 'client_user_id' => $contact->id],
                [
                    'role' => $data['role'],
                    'is_primary' => $request->boolean('is_primary'),
                    'notes' => $data['notes'] ?? null,
                ]
            );

            return new SalesWorkflowResult('success', 'Stakeholder added.', null, ['activity_id' => isset($activity) ? $activity->id : null, 'line_id' => isset($line) ? $line->id : null, 'version_id' => isset($newVersion) ? $newVersion->id : (isset($version) ? $version->id : null)]);

        }, 3);
    }

    public function ensureQuote(Request $request, SalesOpportunity $sale, RecalculateSalesQuoteVersion $recalculate, EnsureSalesQuoteDraft $ensureDraft): SalesWorkflowResult
    {
        return DB::transaction(function () use ($request, $sale, $recalculate, $ensureDraft) {
            SalesOpportunity::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();
            $sale->refresh();

            $version = $ensureDraft->handle($sale, $request->user());
            $recalculate->handle($version);

            return new SalesWorkflowResult('success', 'Quote draft ready.', null, ['activity_id' => isset($activity) ? $activity->id : null, 'line_id' => isset($line) ? $line->id : null, 'version_id' => isset($newVersion) ? $newVersion->id : (isset($version) ? $version->id : null)]);

        }, 3);
    }

    public function updateQuoteDetails(Request $request, SalesOpportunity $sale): SalesWorkflowResult
    {
        return DB::transaction(function () use ($request, $sale) {
            SalesOpportunity::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();
            $sale->refresh();

            $version = $sale->currentQuoteVersion()->firstOrFail();
            abort_unless($version->isEditable(), 422, 'Only a draft quote can be edited.');

            $data = DomainApiInput::validate($request, [
                'title' => ['required', 'string', 'max:255'],
                'expires_at' => ['nullable', 'date'],
                'intro_text' => ['nullable', 'string', 'max:20000'],
                'scope_text' => ['nullable', 'string', 'max:20000'],
                'assumptions_text' => ['nullable', 'string', 'max:20000'],
                'exclusions_text' => ['nullable', 'string', 'max:20000'],
                'next_steps_text' => ['nullable', 'string', 'max:20000'],
                'acknowledgement_title' => ['nullable', 'string', 'max:255'],
                'acknowledgement_body' => ['nullable', 'string', 'max:20000'],
                'acknowledgement_required' => ['nullable', 'boolean'],
            ]);

            $version->fill(array_merge(collect($data)->except(['acknowledgement_title', 'acknowledgement_body', 'acknowledgement_required'])->all(), [
                'updated_by' => $request->user()->id,
            ]))->save();
            if (array_intersect(array_keys($data), ['acknowledgement_title', 'acknowledgement_body', 'acknowledgement_required'])) {
                $this->saveQuoteAcknowledgement($version, $data);
            }
            $this->resetDraftApproval($version, $request->user(), 'Quote presentation changed.');

            return new SalesWorkflowResult('success', 'Quote presentation updated.', null, ['activity_id' => isset($activity) ? $activity->id : null, 'line_id' => isset($line) ? $line->id : null, 'version_id' => isset($newVersion) ? $newVersion->id : (isset($version) ? $version->id : null)]);

        }, 3);
    }

    public function addQuoteLine(
        Request $request,
        SalesOpportunity $sale,
        RecalculateSalesQuoteVersion $recalculate,
        EnsureSalesQuoteDraft $ensureDraft,
        SalesQuotePresentation $quotePresentation
    ): SalesWorkflowResult {
        return DB::transaction(function () use ($request, $sale, $recalculate, $ensureDraft, $quotePresentation) {
            SalesOpportunity::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();
            $sale->refresh();

            $version = $ensureDraft->handle($sale, $request->user());

            abort_unless($version->isEditable(), 422);

            $data = DomainApiInput::validate($request, [
                'source_type' => 'required|string|in:custom,service,package,time_rate,storage_item',
                'source_id' => 'required_unless:source_type,custom|nullable|integer',
                'section' => 'required|string|max:100',
                'downstream_type' => 'required|string|max:100',
                'billing_cadence' => ['nullable', Rule::in(array_keys(SalesQuotePresentation::CADENCES))],
                'name' => 'nullable|string|max:255',
                'description' => 'nullable|string|max:4000',
                'quantity' => 'required|numeric|min:0.01|max:100000',
                'unit_price_ex_vat' => 'nullable|numeric|min:0|max:9999999',
                'unit_cost_ex_vat' => 'nullable|numeric|min:0|max:9999999',
                'discount_value' => 'nullable|numeric|min:0|max:9999999',
                'discount_type' => 'required|string|in:amount,percent',
                'vat_rate' => 'nullable|numeric|min:0|max:100',
                'is_optional' => 'nullable|boolean',
                'is_required' => 'nullable|boolean',
                'is_recommended' => 'nullable|boolean',
                'customer_selected_by_default' => 'nullable|boolean',
                'customer_quantity_editable' => 'nullable|boolean',
                'min_customer_quantity' => 'nullable|numeric|min:0.01|max:100000',
                'max_customer_quantity' => 'nullable|numeric|min:0.01|max:100000',
                'customer_label' => 'nullable|string|max:255',
                'option_group_name' => 'nullable|string|max:255',
                'option_group_type' => ['nullable', Rule::in(array_keys(SalesQuoteOptionGroup::TYPES))],
                'option_group_description' => 'nullable|string|max:2000',
                'option_group_min_select' => 'nullable|integer|min:0|max:100',
                'option_group_max_select' => 'nullable|integer|min:1|max:100',
                'line_acknowledgement_title' => 'nullable|string|max:255',
                'line_acknowledgement_body' => 'nullable|string|max:20000',
                'line_acknowledgement_required' => 'nullable|boolean',
            ]);

            $lineData = $this->lineDataFromSource($data);
            $optionGroup = $this->resolveOptionGroup($version, $data);
            $cpqData = $this->lineCpqData($request, $data);

            $line = SalesQuoteLine::query()->create(array_merge($lineData, $cpqData, [
                'quote_version_id' => $version->id,
                'option_group_id' => $optionGroup?->id,
                'section' => $data['section'],
                'downstream_type' => $data['downstream_type'],
                'billing_cadence' => $quotePresentation->normalizeCadence(
                    $data['billing_cadence'] ?? null,
                    $data['section'],
                    $data['downstream_type']
                ),
                'quantity' => $data['quantity'],
                'unit_price_ex_vat' => $data['unit_price_ex_vat'] ?? $lineData['unit_price_ex_vat'],
                'unit_cost_ex_vat' => $data['unit_cost_ex_vat'] ?? $lineData['unit_cost_ex_vat'],
                'discount_value' => $data['discount_value'] ?? 0,
                'discount_type' => $data['discount_type'],
                'vat_rate' => $data['vat_rate'] ?? $lineData['vat_rate'],
                'is_optional' => ! $cpqData['is_required'],
                'description' => $data['description'] ?? $lineData['description'],
            ]));
            $this->saveLineAcknowledgement($version, $line, $data);
            $this->resetDraftApproval($version, $request->user(), 'Quote line added.');

            $recalculate->handle($version);

            return new SalesWorkflowResult('success', 'Quote line added.', null, ['activity_id' => isset($activity) ? $activity->id : null, 'line_id' => isset($line) ? $line->id : null, 'version_id' => isset($newVersion) ? $newVersion->id : (isset($version) ? $version->id : null)]);

        }, 3);
    }

    public function deleteQuoteLine(Request $request, SalesOpportunity $sale, SalesQuoteLine $line, RecalculateSalesQuoteVersion $recalculate): SalesWorkflowResult
    {
        return DB::transaction(function () use ($request, $sale, $line, $recalculate) {
            SalesOpportunity::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();
            $sale->refresh();

            abort_unless((int) $line->quoteVersion->quote->opportunity_id === (int) $sale->id, 404);
            abort_unless($line->quoteVersion->isEditable(), 422);

            $version = $line->quoteVersion;
            $line->delete();
            $this->resetDraftApproval($version, $request->user(), 'Quote line removed.');
            $recalculate->handle($version);

            return new SalesWorkflowResult('success', 'Quote line removed.', null, ['activity_id' => isset($activity) ? $activity->id : null, 'line_id' => isset($line) ? $line->id : null, 'version_id' => isset($newVersion) ? $newVersion->id : (isset($version) ? $version->id : null)]);

        }, 3);
    }

    public function updateQuoteLine(
        Request $request,
        SalesOpportunity $sale,
        SalesQuoteLine $line,
        RecalculateSalesQuoteVersion $recalculate,
        SalesQuotePresentation $quotePresentation
    ): SalesWorkflowResult {
        return DB::transaction(function () use ($request, $sale, $line, $recalculate, $quotePresentation) {
            SalesOpportunity::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();
            $sale->refresh();

            abort_unless((int) $line->quoteVersion->quote->opportunity_id === (int) $sale->id, 404);
            abort_unless($line->quoteVersion->isEditable(), 422);

            $data = DomainApiInput::validate($request, [
                'section' => 'required|string|max:100',
                'downstream_type' => 'required|string|max:100',
                'billing_cadence' => ['nullable', Rule::in(array_keys(SalesQuotePresentation::CADENCES))],
                'name' => 'required|string|max:255',
                'description' => 'nullable|string|max:4000',
                'quantity' => 'required|numeric|min:0.01|max:100000',
                'unit_price_ex_vat' => 'required|numeric|min:0|max:9999999',
                'unit_cost_ex_vat' => 'nullable|numeric|min:0|max:9999999',
                'discount_value' => 'nullable|numeric|min:0|max:9999999',
                'discount_type' => 'required|string|in:amount,percent',
                'vat_rate' => 'nullable|numeric|min:0|max:100',
                'is_optional' => 'nullable|boolean',
                'is_required' => 'nullable|boolean',
                'is_recommended' => 'nullable|boolean',
                'customer_selected_by_default' => 'nullable|boolean',
                'customer_quantity_editable' => 'nullable|boolean',
                'min_customer_quantity' => 'nullable|numeric|min:0.01|max:100000',
                'max_customer_quantity' => 'nullable|numeric|min:0.01|max:100000',
                'customer_label' => 'nullable|string|max:255',
                'option_group_name' => 'nullable|string|max:255',
                'option_group_type' => ['nullable', Rule::in(array_keys(SalesQuoteOptionGroup::TYPES))],
                'option_group_description' => 'nullable|string|max:2000',
                'option_group_min_select' => 'nullable|integer|min:0|max:100',
                'option_group_max_select' => 'nullable|integer|min:1|max:100',
                'line_acknowledgement_title' => 'nullable|string|max:255',
                'line_acknowledgement_body' => 'nullable|string|max:20000',
                'line_acknowledgement_required' => 'nullable|boolean',
            ]);
            $optionGroup = array_key_exists('option_group_name', $data) ? $this->resolveOptionGroup($line->quoteVersion, $data) : $line->optionGroup;
            $cpqData = $this->lineCpqData($request, $data, $line);

            $line->fill(array_merge(collect($data)->except([
                'option_group_name',
                'option_group_type',
                'option_group_description',
                'option_group_min_select',
                'option_group_max_select',
                'line_acknowledgement_title',
                'line_acknowledgement_body',
                'line_acknowledgement_required',
            ])->all(), $cpqData, [
                'option_group_id' => $optionGroup?->id,
                'billing_cadence' => $quotePresentation->normalizeCadence(
                    $data['billing_cadence'] ?? null,
                    $data['section'],
                    $data['downstream_type']
                ),
                'unit_cost_ex_vat' => $data['unit_cost_ex_vat'] ?? $line->unit_cost_ex_vat,
                'discount_value' => $data['discount_value'] ?? $line->discount_value,
                'vat_rate' => $data['vat_rate'] ?? $line->vat_rate,
                'is_optional' => ! $cpqData['is_required'],
            ]))->save();
            if (array_intersect(array_keys($data), ['line_acknowledgement_title', 'line_acknowledgement_body', 'line_acknowledgement_required'])) {
                $this->saveLineAcknowledgement($line->quoteVersion, $line, $data);
            }
            $this->resetDraftApproval($line->quoteVersion, $request->user(), 'Quote line changed.');

            $recalculate->handle($line->quoteVersion);

            return new SalesWorkflowResult('success', 'Quote line updated.', null, ['activity_id' => isset($activity) ? $activity->id : null, 'line_id' => isset($line) ? $line->id : null, 'version_id' => isset($newVersion) ? $newVersion->id : (isset($version) ? $version->id : null)]);

        }, 3);
    }

    public function reviseQuote(Request $request, SalesOpportunity $sale, EnsureSalesQuoteDraft $ensureDraft, RecalculateSalesQuoteVersion $recalculate): SalesWorkflowResult
    {
        return DB::transaction(function () use ($request, $sale, $ensureDraft, $recalculate) {
            SalesOpportunity::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();
            $sale->refresh();

            $version = $sale->currentQuoteVersion()->with('quote')->firstOrFail();

            if ($sale->status !== 'negotiation' || $version->status !== 'sent') {
                return new SalesWorkflowResult('warning', 'Only sent quotes in negotiation can be revised.', null, ['activity_id' => isset($activity) ? $activity->id : null, 'line_id' => isset($line) ? $line->id : null, 'version_id' => isset($newVersion) ? $newVersion->id : (isset($version) ? $version->id : null)]);
            }

            $newVersion = $ensureDraft->handle($sale, $request->user());
            $recalculate->handle($newVersion);

            SalesActivity::query()->create([
                'opportunity_id' => $sale->id,
                'actor_id' => $request->user()->id,
                'type' => 'quote_revised',
                'subject' => 'Quote moved back to draft',
                'body' => 'Quote '.$version->quote->quote_key.' v'.$version->version_number.' remains immutable. Version '.$newVersion->version_number.' was created as a new draft.',
                'is_unread' => false,
                'read_at' => now(),
                'metadata' => ['previous_quote_version_id' => $version->id, 'quote_version_id' => $newVersion->id],
            ]);

            return new SalesWorkflowResult('success', 'A new draft quote version was created. The sent version remains unchanged.', null, ['activity_id' => isset($activity) ? $activity->id : null, 'line_id' => isset($line) ? $line->id : null, 'version_id' => isset($newVersion) ? $newVersion->id : (isset($version) ? $version->id : null)]);

        }, 3);
    }

    public function requestQuoteApproval(Request $request, SalesOpportunity $sale, RecalculateSalesQuoteVersion $recalculate, EvaluateSalesQuoteApproval $approval): SalesWorkflowResult
    {
        return DB::transaction(function () use ($request, $sale, $recalculate, $approval) {
            SalesOpportunity::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();
            $sale->refresh();

            $version = $sale->currentQuoteVersion()->with('quote')->firstOrFail();
            abort_unless($version->isEditable(), 422, 'Only draft quotes can be submitted for approval.');

            $recalculate->handle($version);
            $result = $approval->handle($version);

            $version->forceFill([
                'approval_status' => $result['required'] ? 'pending' : 'not_required',
                'approval_required_reasons' => $result['reasons'],
                'approval_policy_snapshot' => $result['policy'],
                'approval_requested_at' => $result['required'] ? now() : null,
                'approval_requested_by' => $result['required'] ? $request->user()->id : null,
                'approval_decided_at' => null,
                'approval_decided_by' => null,
                'approval_decision_note' => null,
            ])->save();

            SalesActivity::query()->create([
                'opportunity_id' => $sale->id,
                'actor_id' => $request->user()->id,
                'type' => $result['required'] ? 'quote_approval_requested' : 'quote_approval_not_required',
                'subject' => $result['required'] ? 'Quote approval requested' : 'Quote approval not required',
                'body' => $result['required'] ? implode("\n", $result['reasons']) : 'Approval policy did not require internal approval for this quote.',
                'metadata' => ['quote_version_id' => $version->id, 'reasons' => $result['reasons']],
            ]);

            return new SalesWorkflowResult($result['required'] ? 'warning' : 'success', $result['required'] ? 'Quote sent for internal approval.' : 'Approval is not required for this quote.', null, ['activity_id' => isset($activity) ? $activity->id : null, 'line_id' => isset($line) ? $line->id : null, 'version_id' => isset($newVersion) ? $newVersion->id : (isset($version) ? $version->id : null)]);

        }, 3);
    }

    public function approveQuote(Request $request, SalesOpportunity $sale): SalesWorkflowResult
    {
        return DB::transaction(function () use ($request, $sale) {
            SalesOpportunity::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();
            $sale->refresh();

            $this->assertQuoteApprover($request);

            return $this->decideQuoteApproval($request, $sale, 'approved', 'Quote approved.');

        }, 3);
    }

    public function rejectQuote(Request $request, SalesOpportunity $sale): SalesWorkflowResult
    {
        return DB::transaction(function () use ($request, $sale) {
            SalesOpportunity::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();
            $sale->refresh();

            $this->assertQuoteApprover($request);

            return $this->decideQuoteApproval($request, $sale, 'rejected', 'Quote approval rejected.');

        }, 3);
    }

    public function requestQuoteChanges(Request $request, SalesOpportunity $sale): SalesWorkflowResult
    {
        return DB::transaction(function () use ($request, $sale) {
            SalesOpportunity::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();
            $sale->refresh();

            $this->assertQuoteApprover($request);

            return $this->decideQuoteApproval($request, $sale, 'changes_requested', 'Quote changes requested.');

        }, 3);
    }

    public function applyQuoteTemplate(Request $request, SalesOpportunity $sale, ApplySalesQuoteTemplate $applyTemplate): SalesWorkflowResult
    {
        return DB::transaction(function () use ($request, $sale, $applyTemplate) {
            SalesOpportunity::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();
            $sale->refresh();

            $data = DomainApiInput::validate($request, [
                'template_id' => 'required|exists:sales_quote_templates,id',
                'replace_existing' => 'nullable|boolean',
            ]);

            $template = SalesQuoteTemplate::query()->where('is_active', true)->findOrFail($data['template_id']);
            $version = $sale->currentQuoteVersion()->with('quote')->firstOrFail();

            $applyTemplate->handle($version, $template, $request->user(), (bool) ($data['replace_existing'] ?? false));

            return new SalesWorkflowResult('success', 'Quote template applied.', null, ['activity_id' => isset($activity) ? $activity->id : null, 'line_id' => isset($line) ? $line->id : null, 'version_id' => isset($newVersion) ? $newVersion->id : (isset($version) ? $version->id : null)]);

        }, 3);
    }

    public function updateConversionPlan(Request $request, SalesOpportunity $sale, SalesQuoteConversionPlan $plan): SalesWorkflowResult
    {
        return DB::transaction(function () use ($request, $sale, $plan) {
            SalesOpportunity::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();
            $sale->refresh();

            $plan->loadMissing('quoteVersion.quote');
            abort_unless((int) $plan->quoteVersion->quote->opportunity_id === (int) $sale->id, 404);

            $data = DomainApiInput::validate($request, [
                'status' => ['required', Rule::in(['pending', 'in_progress', 'completed', 'deferred', 'blocked', 'not_applicable'])],
                'target_reference' => ['nullable', 'string', 'max:255'],
                'operator_note' => ['nullable', 'string', 'max:4000'],
            ]);

            $terminalStatuses = ['completed', 'deferred', 'blocked', 'not_applicable'];
            $plan->forceFill([
                'status' => $data['status'],
                'target_reference' => $data['target_reference'] ?? null,
                'operator_note' => $data['operator_note'] ?? null,
                'processed_at' => in_array($data['status'], $terminalStatuses, true) ? now() : null,
                'processed_by' => in_array($data['status'], $terminalStatuses, true) ? $request->user()->id : null,
            ])->save();

            SalesActivity::query()->create([
                'opportunity_id' => $sale->id,
                'actor_id' => $request->user()->id,
                'type' => 'quote_conversion_plan_updated',
                'subject' => 'Quote conversion plan updated',
                'body' => trim(str_replace('_', ' ', $data['status'])."\n".($data['target_reference'] ?? '')."\n".($data['operator_note'] ?? '')),
                'metadata' => [
                    'quote_version_id' => $plan->quote_version_id,
                    'conversion_plan_id' => $plan->id,
                    'status' => $data['status'],
                    'target_reference' => $data['target_reference'] ?? null,
                ],
            ]);

            return new SalesWorkflowResult('success', 'Conversion plan updated.', null, ['activity_id' => isset($activity) ? $activity->id : null, 'line_id' => isset($line) ? $line->id : null, 'version_id' => isset($newVersion) ? $newVersion->id : (isset($version) ? $version->id : null)]);

        }, 3);
    }

    public function sendQuote(Request $request, SalesOpportunity $sale, RecalculateSalesQuoteVersion $recalculate, SendCustomerPortalNotification $portalNotifications, EvaluateSalesQuoteApproval $approval): SalesWorkflowResult
    {
        return DB::transaction(function () use ($request, $sale, $recalculate, $portalNotifications, $approval) {
            SalesOpportunity::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();
            $sale->refresh();

            $version = $sale->currentQuoteVersion()->with('quote')->firstOrFail();
            $communications = app(\App\Modules\Sales\Services\SalesCommunications::class);
            $previewId = $request->attributes->get('sales_delivery_preview_id');
            $frozenAttempt = $previewId
                ? $communications->assertPreview($previewId, $request->user(), 'quote', $sale, $version, $request->attributes->get('sales_delivery_preview_hash'))
                : $communications->preview($request->user(), 'quote', $sale, $version);

            if (! in_array($version->status, ['draft', 'sent'], true)) {
                return new SalesWorkflowResult('warning', 'Only draft or sent quotes can be emailed.', null, ['activity_id' => isset($activity) ? $activity->id : null, 'line_id' => isset($line) ? $line->id : null, 'version_id' => isset($newVersion) ? $newVersion->id : (isset($version) ? $version->id : null)]);
            }

            $recalculate->handle($version);

            if ($version->lines()->count() < 1) {
                return new SalesWorkflowResult('warning', 'Add at least one quote line before sending.', null, ['activity_id' => isset($activity) ? $activity->id : null, 'line_id' => isset($line) ? $line->id : null, 'version_id' => isset($newVersion) ? $newVersion->id : (isset($version) ? $version->id : null)]);
            }

            $approvalResult = $approval->handle($version);
            if ($approvalResult['required'] && ($version->approval_status !== 'approved' || $version->approval_policy_snapshot != $approvalResult['policy'])) {
                $version->forceFill([
                    'approval_status' => 'pending',
                    'approval_required_reasons' => $approvalResult['reasons'],
                    'approval_policy_snapshot' => $approvalResult['policy'],
                    'approval_requested_at' => $version->approval_requested_at ?: now(),
                    'approval_requested_by' => $version->approval_requested_by ?: $request->user()->id,
                    'approval_decided_at' => null,
                    'approval_decided_by' => null,
                    'approval_decision_note' => null,
                ])->save();

                SalesActivity::query()->create([
                    'opportunity_id' => $sale->id,
                    'actor_id' => $request->user()->id,
                    'type' => 'quote_approval_requested',
                    'subject' => 'Quote approval required',
                    'body' => implode("\n", $approvalResult['reasons']),
                    'metadata' => ['quote_version_id' => $version->id, 'reasons' => $approvalResult['reasons']],
                ]);

                return new SalesWorkflowResult('warning', 'Quote requires internal approval before sending.', null, ['activity_id' => isset($activity) ? $activity->id : null, 'line_id' => isset($line) ? $line->id : null, 'version_id' => isset($newVersion) ? $newVersion->id : (isset($version) ? $version->id : null)]);
            }

            if (! $approvalResult['required'] && $version->approval_status !== 'not_required') {
                $version->forceFill([
                    'approval_status' => 'not_required',
                    'approval_required_reasons' => [],
                    'approval_policy_snapshot' => $approvalResult['policy'],
                    'approval_requested_at' => null,
                    'approval_requested_by' => null,
                    'approval_decided_at' => null,
                    'approval_decided_by' => null,
                    'approval_decision_note' => null,
                ])->save();
            }

            $wasDraft = $version->status === 'draft';

            if ($wasDraft) {
                $version->forceFill([
                    'status' => 'sent',
                    'sent_at' => now(),
                    'updated_by' => $request->user()->id,
                ])->save();
                $version->quote->forceFill(['status' => 'sent', 'current_version_id' => $version->id])->save();
                $sale->forceFill([
                    'status' => 'quote_sent',
                    'probability_percent' => 50,
                    'weighted_value_ex_vat' => round((float) $version->total_ex_vat * 0.5, 2),
                    'current_quote_version_id' => $version->id,
                ])->save();

                SalesActivity::query()->create([
                    'opportunity_id' => $sale->id,
                    'actor_id' => $request->user()->id,
                    'type' => 'quote_sent',
                    'subject' => 'Quote sent',
                    'body' => 'Quote '.$version->quote->quote_key.' v'.$version->version_number.' was marked as sent.',
                    'metadata' => ['quote_version_id' => $version->id],
                ]);

                if ($sale->client_id) {
                    $portalNotifications->handle(
                        type: 'portal_quote_sent',
                        clientId: (int) $sale->client_id,
                        siteId: null,
                        title: 'New quote available',
                        body: $version->title ?: $sale->title,
                        url: route('customer-portal.quotes.show', $version),
                        sourceType: SalesQuoteVersion::class,
                        sourceId: $version->id,
                        metadata: [
                            'quote_key' => $version->quote->quote_key,
                            'version_number' => $version->version_number,
                            'opportunity_id' => $sale->id,
                        ],
                    );
                }
            }

            $sale->loadMissing('primaryContact');

            if (! empty($frozenAttempt->payload['to_email'])) {
                $communications->queue($frozenAttempt);

                SalesActivity::query()->create([
                    'opportunity_id' => $sale->id,
                    'actor_id' => $request->user()->id,
                    'type' => 'quote_email_queued',
                    'subject' => 'Quote email queued',
                    'body' => 'Quote '.$version->quote->quote_key.' v'.$version->version_number.' was queued for email delivery to '.$frozenAttempt->payload['to_email'].'.',
                    'is_unread' => false,
                    'read_at' => now(),
                    'metadata' => ['quote_version_id' => $version->id, 'to_email' => $frozenAttempt->payload['to_email'], 'delivery_attempt_id' => $frozenAttempt->id],
                ]);

                return new SalesWorkflowResult('success', $wasDraft ? 'Quote marked as sent and queued for email delivery.' : 'Quote email queued for delivery.', null, ['activity_id' => isset($activity) ? $activity->id : null, 'line_id' => isset($line) ? $line->id : null, 'version_id' => isset($newVersion) ? $newVersion->id : (isset($version) ? $version->id : null)]);
            }

            $communications->queue($frozenAttempt);

            return new SalesWorkflowResult('warning', 'Quote marked as sent, but no primary contact email is available.', null, ['activity_id' => isset($activity) ? $activity->id : null, 'line_id' => isset($line) ? $line->id : null, 'version_id' => isset($newVersion) ? $newVersion->id : (isset($version) ? $version->id : null)]);

        }, 3);
    }

    private function decideQuoteApproval(Request $request, SalesOpportunity $sale, string $status, string $message): SalesWorkflowResult
    {
        $data = DomainApiInput::validate($request, [
            'note' => ['nullable', 'string', 'max:4000'],
        ]);

        $version = $sale->currentQuoteVersion()->firstOrFail();
        abort_unless($version->isEditable(), 422, 'Only draft quotes can receive an internal approval decision.');

        $version->forceFill([
            'approval_status' => $status,
            'approval_decided_at' => now(),
            'approval_decided_by' => $request->user()->id,
            'approval_decision_note' => $data['note'] ?? null,
        ])->save();

        SalesActivity::query()->create([
            'opportunity_id' => $sale->id,
            'actor_id' => $request->user()->id,
            'type' => 'quote_approval_'.$status,
            'subject' => $message,
            'body' => $data['note'] ?? $message,
            'metadata' => ['quote_version_id' => $version->id, 'approval_status' => $status],
        ]);

        return new SalesWorkflowResult($status === 'approved' ? 'success' : 'warning', $message, null, ['activity_id' => isset($activity) ? $activity->id : null, 'line_id' => isset($line) ? $line->id : null, 'version_id' => isset($newVersion) ? $newVersion->id : (isset($version) ? $version->id : null)]);
    }

    private function assertQuoteApprover(Request $request): void
    {
        abort_unless($request->user()?->can('sales.quote.approve'), 403);
    }

    private function resolveOptionGroup(SalesQuoteVersion $version, array $data): ?SalesQuoteOptionGroup
    {
        if (! filled($data['option_group_name'] ?? null)) {
            return null;
        }

        $maxSelect = $data['option_group_max_select'] ?? null;
        $type = $data['option_group_type'] ?? 'optional';
        if ($maxSelect === null && in_array($type, ['alternative', 'good_better_best'], true)) {
            $maxSelect = 1;
        }

        if ($maxSelect !== null && (int) $maxSelect < (int) ($data['option_group_min_select'] ?? 0)) {
            throw ValidationException::withMessages(['option_group_max_select' => 'Maximum selections must be greater than or equal to minimum selections.']);
        }

        return SalesQuoteOptionGroup::query()->updateOrCreate(
            [
                'quote_version_id' => $version->id,
                'name' => trim((string) $data['option_group_name']),
            ],
            [
                'type' => $type,
                'description' => $data['option_group_description'] ?? null,
                'min_select' => (int) ($data['option_group_min_select'] ?? 0),
                'max_select' => $maxSelect !== null ? (int) $maxSelect : null,
                'sort_order' => $version->optionGroups()->count() * 10 + 10,
            ]
        );
    }

    private function lineCpqData(Request $request, array $data, ?SalesQuoteLine $line = null): array
    {
        $quantity = (float) ($data['quantity'] ?? $line?->quantity ?? 1);
        $customerQuantityEditable = $request->has('customer_quantity_editable') ? $request->boolean('customer_quantity_editable') : (bool) $line?->customer_quantity_editable;
        $minQuantity = (float) ($data['min_customer_quantity'] ?? $line?->min_customer_quantity ?? ($customerQuantityEditable ? 1 : $quantity));
        $maxQuantity = array_key_exists('max_customer_quantity', $data) && $data['max_customer_quantity'] !== null
            ? (float) $data['max_customer_quantity']
            : ($line ? $line->max_customer_quantity : ($customerQuantityEditable ? null : $quantity));

        if ($maxQuantity !== null && $maxQuantity < $minQuantity) {
            throw ValidationException::withMessages(['max_customer_quantity' => 'Maximum customer quantity must be greater than or equal to minimum quantity.']);
        }

        $isOptional = $request->has('is_optional') ? $request->boolean('is_optional') : (bool) $line?->is_optional;
        $isRequired = $request->has('is_required') ? $request->boolean('is_required') : ! $isOptional;
        $selectedByDefault = $request->has('customer_selected_by_default')
            ? $request->boolean('customer_selected_by_default')
            : ($line ? (bool) $line->customer_selected_by_default : true);

        return [
            'is_required' => $isRequired,
            'is_recommended' => $request->has('is_recommended') ? $request->boolean('is_recommended') : (bool) $line?->is_recommended,
            'customer_selected_by_default' => $isRequired || $selectedByDefault,
            'customer_quantity_editable' => $customerQuantityEditable,
            'min_customer_quantity' => $minQuantity,
            'max_customer_quantity' => $maxQuantity,
            'customer_label' => array_key_exists('customer_label', $data) ? $data['customer_label'] : $line?->customer_label,
        ];
    }

    private function saveQuoteAcknowledgement(SalesQuoteVersion $version, array $data): void
    {
        $body = trim((string) ($data['acknowledgement_body'] ?? ''));
        $existing = SalesQuoteAcknowledgement::query()
            ->where('quote_version_id', $version->id)
            ->whereNull('quote_line_id')
            ->where('source_type', 'manual_quote_details')
            ->first();

        if ($body === '') {
            $existing?->delete();

            return;
        }

        SalesQuoteAcknowledgement::query()->updateOrCreate(
            [
                'quote_version_id' => $version->id,
                'quote_line_id' => null,
                'source_type' => 'manual_quote_details',
            ],
            [
                'title' => $data['acknowledgement_title'] ?: 'Important information',
                'body' => $body,
                'is_required' => array_key_exists('acknowledgement_required', $data) ? (bool) $data['acknowledgement_required'] : true,
                'sort_order' => $existing?->sort_order ?? $version->acknowledgements()->count() * 10 + 10,
            ]
        );
    }

    private function saveLineAcknowledgement(SalesQuoteVersion $version, SalesQuoteLine $line, array $data): void
    {
        $body = trim((string) ($data['line_acknowledgement_body'] ?? ''));
        $existing = SalesQuoteAcknowledgement::query()
            ->where('quote_version_id', $version->id)
            ->where('quote_line_id', $line->id)
            ->where('source_type', 'manual_line')
            ->first();

        if ($body === '') {
            $existing?->delete();

            return;
        }

        SalesQuoteAcknowledgement::query()->updateOrCreate(
            [
                'quote_version_id' => $version->id,
                'quote_line_id' => $line->id,
                'source_type' => 'manual_line',
            ],
            [
                'title' => $data['line_acknowledgement_title'] ?: 'Important information for '.$line->name,
                'body' => $body,
                'is_required' => array_key_exists('line_acknowledgement_required', $data) ? (bool) $data['line_acknowledgement_required'] : true,
                'sort_order' => $existing?->sort_order ?? $version->acknowledgements()->count() * 10 + 10,
            ]
        );
    }

    private function resetDraftApproval(SalesQuoteVersion $version, User $actor, string $reason): void
    {
        if (! $version->isEditable() || ! in_array($version->approval_status, ['pending', 'approved', 'rejected', 'changes_requested'], true)) {
            return;
        }

        $version->forceFill([
            'approval_status' => 'not_required',
            'approval_required_reasons' => [],
            'approval_requested_at' => null,
            'approval_requested_by' => null,
            'approval_decided_at' => null,
            'approval_decided_by' => null,
            'approval_decision_note' => null,
        ])->save();

        SalesActivity::query()->create([
            'opportunity_id' => $version->quote->opportunity_id,
            'actor_id' => $actor->id,
            'type' => 'quote_approval_invalidated',
            'subject' => 'Quote approval reset',
            'body' => $reason,
            'metadata' => ['quote_version_id' => $version->id],
        ]);
    }

    private function salesRecipient(SalesOpportunity $sale, array $data): array
    {
        $contactId = $data['recipient_contact_id'] ?? null;

        if ($contactId) {
            $contact = ClientUser::query()
                ->whereKey($contactId)
                ->whereHas('site', fn ($query) => $query->where('client_id', $sale->client_id))
                ->where('active', true)
                ->firstOrFail();

            return [
                'contact_id' => $contact->id,
                'email' => $contact->email,
                'name' => $contact->name,
            ];
        }

        $email = $data['to_email'] ?? null;
        abort_unless($email && filter_var($email, FILTER_VALIDATE_EMAIL), 422, 'Customer email requires a recipient.');

        return [
            'contact_id' => null,
            'email' => $email,
            'name' => '',
        ];
    }

    private function ccRecipientsFromString(?string $cc): array
    {
        $emails = collect(preg_split('/[,;\s]+/', (string) $cc))->map(fn ($email) => trim($email))->filter();
        if ($emails->contains(fn ($email) => ! filter_var($email, FILTER_VALIDATE_EMAIL))) {
            throw ValidationException::withMessages(['cc' => 'Use valid email addresses separated by commas or semicolons.']);
        }

        return $emails->unique()->map(fn ($email) => ['email' => $email, 'name' => ''])->values()->all();
    }

    private function lineDataFromSource(array $data): array
    {
        $base = [
            'source_type' => $data['source_type'],
            'source_id' => $data['source_id'] ?? null,
            'sku' => null,
            'name' => ($data['name'] ?? null) ?: 'Custom line',
            'description' => $data['description'] ?? null,
            'unit' => null,
            'unit_price_ex_vat' => (float) ($data['unit_price_ex_vat'] ?? 0),
            'unit_cost_ex_vat' => (float) ($data['unit_cost_ex_vat'] ?? 0),
            'vat_rate' => $data['vat_rate'] ?? 25,
            'snapshot' => null,
        ];

        if ($data['source_type'] === 'service' && ! empty($data['source_id'])) {
            $service = Services::with(['serviceTerms', 'sla', 'costRelations.cost'])->findOrFail($data['source_id']);
            $cost = $service->costRelations->sum(fn ($relation) => (float) ($relation->cost?->cost ?? 0));

            return array_merge($base, [
                'name' => ($data['name'] ?? null) ?: $service->name,
                'sku' => $service->sku,
                'description' => $data['description'] ?? $service->short_description,
                'unit_price_ex_vat' => (float) ($data['unit_price_ex_vat'] ?? $service->price_ex_vat ?? 0),
                'unit_cost_ex_vat' => (float) ($data['unit_cost_ex_vat'] ?? $cost),
                'vat_rate' => $data['vat_rate'] ?? 25,
                'snapshot' => array_merge($service->only(['id', 'name', 'sku', 'price_ex_vat', 'billing_cycle']), [
                    'cost_ex_vat' => $cost,
                ]),
            ]);
        }

        if ($data['source_type'] === 'package' && ! empty($data['source_id'])) {
            $package = Package::with(['terms', 'services.costRelations.cost'])->findOrFail($data['source_id']);
            $cost = $package->services->sum(
                fn ($service) => $service->costRelations->sum(fn ($relation) => (float) ($relation->cost?->cost ?? 0))
            );

            return array_merge($base, [
                'name' => ($data['name'] ?? null) ?: $package->name,
                'description' => $data['description'] ?? $package->description,
                'unit_price_ex_vat' => (float) ($data['unit_price_ex_vat'] ?? $package->sales_price_client ?? 0),
                'unit_cost_ex_vat' => (float) ($data['unit_cost_ex_vat'] ?? $cost),
                'snapshot' => array_merge($package->only(['id', 'name', 'description']), [
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
                'unit_price_ex_vat' => (float) ($data['unit_price_ex_vat'] ?? $rate->amount_ex_vat ?? 0),
                'snapshot' => $rate->only(['id', 'name', 'code', 'amount_ex_vat', 'unit']),
            ]);
        }

        if ($data['source_type'] === 'storage_item' && ! empty($data['source_id'])) {
            $item = StorageItem::findOrFail($data['source_id']);

            return array_merge($base, [
                'name' => ($data['name'] ?? null) ?: $item->name,
                'sku' => $item->sku,
                'description' => $data['description'] ?? $item->short_description,
                'unit_price_ex_vat' => (float) ($data['unit_price_ex_vat'] ?? $item->sale_price ?? 0),
                'unit_cost_ex_vat' => (float) ($data['unit_cost_ex_vat'] ?? $item->purchase_price ?? 0),
                'vat_rate' => $data['vat_rate'] ?? $item->vat_rate ?? 25,
                'snapshot' => $item->only(['id', 'sku', 'name', 'sale_price', 'purchase_price', 'vat_rate']),
            ]);
        }

        return $base;
    }
}
