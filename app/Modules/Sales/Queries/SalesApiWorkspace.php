<?php

namespace App\Modules\Sales\Queries;

use App\Models\Clients\Client;
use App\Models\Core\User;
use App\Modules\Sales\Actions\EnsureSalesDefaults;
use App\Modules\Sales\Actions\EvaluateSalesQuoteApproval;
use App\Modules\Sales\Models\SalesOpportunity;
use App\Modules\Sales\Models\SalesQuoteAcceptanceSnapshot;
use App\Modules\Sales\Models\SalesQuoteConversionPlan;
use App\Modules\Sales\Models\SalesQuoteTemplate;
use App\Modules\Sales\Models\SalesQuoteVersion;
use App\Modules\Sales\Models\SalesSetting;
use App\Modules\Sales\Services\SalesQuoteTemplateWorkflowService;
use Illuminate\Http\Request;

/** Pure bounded context/query operations; absent defaults are reported, never seeded on GET. */
class SalesApiWorkspace
{
    public function context(Request $request, ?SalesOpportunity $opportunity = null): array
    {
        $clients = Client::query()->when($request->filled('q'), fn ($q) => $q->where('name', 'like', '%'.$request->string('q')->trim().'%'));
        $total = (clone $clients)->count();
        $rows = $clients->orderBy('name')->offset(max(0, $request->integer('client_page', 1) - 1) * 50)->limit(50)->get(['id', 'name', 'client_number']);
        $clientId = $opportunity?->client_id ?? $request->integer('client_id');
        $client = $clientId ? Client::query()->findOrFail($clientId) : null;

        return [
            'types' => EnsureSalesDefaults::TYPES, 'statuses' => EnsureSalesDefaults::STATUSES,
            'next_actions' => EnsureSalesDefaults::NEXT_ACTIONS,
            'owners' => User::query()->where('status', User::STATUS_ACTIVE)->orderBy('name')->offset(max(0, $request->integer('owner_page', 1) - 1) * 100)->limit(100)->get(['id', 'name']), 'owners_total' => User::query()->where('status', User::STATUS_ACTIVE)->count(),
            'clients' => $rows, 'clients_total' => $total, 'clients_complete' => $total <= 50, 'clients_page' => $request->integer('client_page', 1), 'clients_next_page' => $total > $request->integer('client_page', 1) * 50 ? $request->integer('client_page', 1) + 1 : null,
            'sites' => $client?->sites()->orderBy('name')->offset(max(0, $request->integer('site_page', 1) - 1) * 100)->limit(100)->get(['id', 'name']) ?? [],
            'contacts' => $client?->contacts()->where('client_users.active', true)->orderBy('client_users.name')->offset(max(0, $request->integer('contact_page', 1) - 1) * 100)->limit(100)->get(['client_users.id', 'client_users.name', 'email', 'phone', 'role', 'client_site_id', 'contact_id']) ?? [],
            'sites_total' => $client?->sites()->count() ?? 0, 'contacts_total' => $client?->contacts()->where('client_users.active', true)->count() ?? 0,
            'setup_required' => ! SalesSetting::query()->where('key', 'cpq_approval_policy')->exists(),
            'defaults' => ['quote_expiry_days' => SalesSetting::get('quote_expiry_days', 30), 'create_calendar_followups' => SalesSetting::get('create_calendar_followups', true)],
            'quote_templates' => SalesQuoteTemplate::query()->where('is_active', true)->orderBy('name')->offset(max(0, $request->integer('template_page', 1) - 1) * 100)->limit(100)->get(['id', 'name', 'template_key']),
            'quote_templates_total' => SalesQuoteTemplate::query()->where('is_active', true)->count(),
            'catalog' => app(SalesQuoteTemplateWorkflowService::class)->catalog(),
            'opportunity_revision' => $opportunity ? \App\Modules\Sales\Support\SalesRevision::of($opportunity) : null,
        ];
    }

    public function dashboard(): array
    {
        return [
            'open' => SalesOpportunity::query()->whereNotIn('status', ['won', 'lost', 'not_qualified'])->count(),
            'won' => SalesOpportunity::query()->where('status', 'won')->count(),
            'unread' => SalesOpportunity::query()->where('is_unread', true)->count(),
            'due' => SalesOpportunity::query()->whereNotNull('next_follow_up_at')->where('next_follow_up_at', '<', now())->count(),
            'weighted' => SalesOpportunity::query()->whereNotIn('status', ['lost', 'not_qualified'])->sum('weighted_value_ex_vat'),
        ];
    }

    public function reporting(): array
    {
        return ['templates' => SalesQuoteTemplate::query()->count(), 'active_templates' => SalesQuoteTemplate::query()->where('is_active', true)->count(),
            'accepted_snapshots' => SalesQuoteAcceptanceSnapshot::query()->count(), 'pending_conversion_plans' => SalesQuoteConversionPlan::query()->where('status', 'pending')->count(),
            'declined_quotes' => SalesQuoteVersion::query()->where('status', 'declined')->count(), 'expired_sent_quotes' => SalesQuoteVersion::query()->where('status', 'sent')->whereNotNull('expires_at')->where('expires_at', '<', now()->toDateString())->count()];
    }

    public function policy(): array
    {
        return SalesSetting::get('cpq_approval_policy', EvaluateSalesQuoteApproval::DEFAULT_POLICY);
    }

    public function policyRevision(): string
    {
        $record = SalesSetting::query()->where('key', 'cpq_approval_policy')->first();

        return hash('sha256', json_encode($record?->getRawOriginal() ?? $this->policy(), JSON_THROW_ON_ERROR));
    }
}
