<?php

namespace App\Modules\Ticket\Services;

use App\Modules\Email\Models\EmailAccount;
use App\Modules\Integration\Support\DomainApiAudit;
use App\Modules\Ticket\Actions\UpdateDefaultTicketEmailAccount;
use App\Modules\Ticket\Support\TicketMergeSettings;
use App\Modules\Ticket\Support\TicketPortalPolicy;
use App\Modules\Ticket\Support\TicketQuoteCostPolicy;
use App\Modules\Ticket\Support\TicketSettingsPayload;
use App\Modules\Ticket\Support\TicketSolutionPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Fixed administrative settings use existing policies and an atomic, content-free change receipt. */
class TicketSettingsEditor
{
    public function settings(): array
    {
        return [
            'default_email_account_id' => EmailAccount::query()->where('is_active', true)->orderBy('address')->get(['id', 'defaults_for'])
                ->first(fn ($account) => in_array('tickets', (array) $account->defaults_for, true))?->id,
            'solution_policy' => app(TicketSolutionPolicy::class)->settings(),
            'portal_policy' => app(TicketPortalPolicy::class)->settings(),
            'quote_cost_policy' => app(TicketQuoteCostPolicy::class)->settings(),
            'merge_settings' => app(TicketMergeSettings::class)->settings(),
        ];
    }

    public function updateEmail(Request $request): void
    {
        $data = $request->validate(TicketSettingsPayload::email());
        $account = isset($data['email_account_id']) ? EmailAccount::query()->where('is_active', true)->findOrFail($data['email_account_id']) : null;
        DB::transaction(function () use ($request, $account) {
            $before = ['email_account_id' => $this->settings()['default_email_account_id']];
            app(UpdateDefaultTicketEmailAccount::class)->handle($account);
            app(DomainApiAudit::class)->record($request->user(), 'ticket', 'ticket.settings.default_email.updated', null, $before, ['email_account_id' => $this->settings()['default_email_account_id']]);
        });
    }

    public function updateSolution(Request $request): void
    {
        $data = $request->validate(TicketSettingsPayload::solution());
        DB::transaction(function () use ($request, $data) {
            $policy = app(TicketSolutionPolicy::class);
            $before = $policy->settings();
            $policy->update(['allow_internal_solution_notes' => (bool) ($data['allow_internal_solution_notes'] ?? false)]);
            app(DomainApiAudit::class)->record($request->user(), 'ticket', 'ticket.settings.solution.updated', null, $before, $policy->settings());
        });
    }

    public function updatePortal(Request $request): void
    {
        $data = $request->validate(TicketSettingsPayload::portal());
        DB::transaction(function () use ($request, $data) {
            $policy = app(TicketPortalPolicy::class);
            $before = $policy->settings();
            $policy->update($data);
            app(DomainApiAudit::class)->record($request->user(), 'ticket', 'ticket.settings.portal.updated', null, $before, $policy->settings());
        });
    }

    public function updateQuote(Request $request): void
    {
        $data = $request->validate(TicketSettingsPayload::quoteCost());
        DB::transaction(function () use ($request, $data) {
            $policy = app(TicketQuoteCostPolicy::class);
            $before = $policy->settings();
            $policy->update(['quote_required_cost_threshold' => filled($data['quote_required_cost_threshold'] ?? null) ? (float) $data['quote_required_cost_threshold'] : null]);
            app(DomainApiAudit::class)->record($request->user(), 'ticket', 'ticket.settings.quote_cost.updated', null, $before, $policy->settings());
        });
    }

    public function updateMerge(Request $request): void
    {
        app(TicketMergeSettings::class)->update($request->validate(TicketSettingsPayload::merge()), $request->user());
    }
}
