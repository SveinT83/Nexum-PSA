<?php

namespace App\Modules\Sales\Actions;

use App\Models\Clients\ClientUser;
use App\Models\Core\User;
use App\Modules\Sales\Models\SalesOpportunity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Validated opportunity business contract shared by browser and API. */
class SalesOpportunityWorkflow
{
    public function store(Request $request): SalesOpportunity
    {
        $data = $this->validatedOpportunity($request, true);
        $this->assertPrimaryContactBelongsToClient($data['primary_contact_id'] ?? null, (int) $data['client_id']);

        return app(StoreSalesOpportunity::class)->handle($data, $request->user());
    }

    public function update(Request $request, SalesOpportunity $opportunity): SalesOpportunity
    {
        return DB::transaction(function () use ($request, $opportunity) {
            SalesOpportunity::query()->whereKey($opportunity->id)->lockForUpdate()->firstOrFail();
            $opportunity->refresh();

            $validated = $this->validatedOpportunity($request, false);
            $this->assertLostWorkflowIsNotBypassed($opportunity, $validated['status'] ?? null);
            $data = array_merge($this->payloadFromOpportunity($opportunity), $validated);
            $this->assertPrimaryContactBelongsToClient($data['primary_contact_id'] ?? null, (int) $data['client_id']);
            $probability = $data['probability_percent'] ?? (EnsureSalesDefaults::STATUSES[$data['status']]['probability'] ?? $opportunity->probability_percent);
            $estimated = (float) ($data['estimated_value_ex_vat'] ?? 0);
            $opportunity->fill(array_merge($data, ['probability_percent' => $probability, 'weighted_value_ex_vat' => round($estimated * ($probability / 100), 2), 'updated_by' => $request->user()->id]))->save();
            app(SyncOpportunityFollowUpCalendar::class)->handle($opportunity, $request->user());

            return $opportunity->refresh();

        }, 3);
    }

    private function validatedOpportunity(Request $request, bool $creating): array
    {
        if ($request->has('next_follow_up_type')) {
            $request->merge([
                'next_follow_up_type' => EnsureSalesDefaults::normalizeNextAction($request->input('next_follow_up_type')),
            ]);
        }

        return \App\Modules\Integration\Support\DomainApiInput::validate($request, [
            'client_id' => [$creating ? 'required' : 'sometimes', Rule::exists('clients', 'id')],
            'primary_contact_id' => ['sometimes', 'nullable', Rule::exists('client_users', 'id')],
            'owner_id' => ['sometimes', 'nullable', Rule::exists((new User)->getTable(), 'id')],
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'type' => [$creating ? 'required' : 'sometimes', 'string', Rule::in(array_keys(EnsureSalesDefaults::TYPES))],
            'status' => ['sometimes', 'nullable', 'string', Rule::in(array_keys(EnsureSalesDefaults::STATUSES))],
            'summary' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'needs' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'employee_count_estimate' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000'],
            'user_count_estimate' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000'],
            'workstation_count_estimate' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000'],
            'server_count_estimate' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000'],
            'site_count_estimate' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000'],
            'estimated_value_ex_vat' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999999'],
            'probability_percent' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100'],
            'expected_close_date' => ['sometimes', 'nullable', 'date'],
            'next_follow_up_at' => ['sometimes', 'nullable', 'date'],
            'next_follow_up_type' => ['sometimes', 'nullable', Rule::in(array_keys(EnsureSalesDefaults::NEXT_ACTIONS))],
            'next_follow_up_note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);
    }

    private function payloadFromOpportunity(SalesOpportunity $opportunity): array
    {
        return [
            'client_id' => $opportunity->client_id,
            'primary_contact_id' => $opportunity->primary_contact_id,
            'owner_id' => $opportunity->owner_id,
            'title' => $opportunity->title,
            'type' => $opportunity->type,
            'status' => $opportunity->status,
            'summary' => $opportunity->summary,
            'needs' => $opportunity->needs,
            'employee_count_estimate' => $opportunity->employee_count_estimate,
            'user_count_estimate' => $opportunity->user_count_estimate,
            'workstation_count_estimate' => $opportunity->workstation_count_estimate,
            'server_count_estimate' => $opportunity->server_count_estimate,
            'site_count_estimate' => $opportunity->site_count_estimate,
            'estimated_value_ex_vat' => $opportunity->estimated_value_ex_vat,
            'probability_percent' => $opportunity->probability_percent,
            'expected_close_date' => $opportunity->expected_close_date?->format('Y-m-d'),
            'next_follow_up_at' => $opportunity->next_follow_up_at?->toDateTimeString(),
            'next_follow_up_type' => $opportunity->next_follow_up_type,
            'next_follow_up_note' => $opportunity->next_follow_up_note,
        ];
    }

    private function assertPrimaryContactBelongsToClient(mixed $contactId, int $clientId): void
    {
        if (! $contactId) {
            return;
        }

        abort_unless(
            ClientUser::query()
                ->whereKey($contactId)
                ->whereHas('site', fn ($query) => $query->where('client_id', $clientId))
                ->exists(),
            422,
            'Sales contact must belong to the selected client.'
        );
    }

    private function assertLostWorkflowIsNotBypassed(SalesOpportunity $opportunity, mixed $requestedStatus): void
    {
        if ($requestedStatus === 'lost' && $opportunity->status !== 'lost') {
            throw ValidationException::withMessages([
                'status' => 'Use the lost action so the reason and follow-up cleanup are recorded.',
            ]);
        }

        if ($opportunity->status === 'lost' && filled($requestedStatus) && $requestedStatus !== 'lost') {
            throw ValidationException::withMessages([
                'status' => 'Use the reopen action so the lost transition is reversed safely.',
            ]);
        }
    }
}
