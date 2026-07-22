<?php

namespace App\Modules\Integration\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Clients\Client;
use App\Modules\Commercial\Models\Economy\Units;
use App\Modules\Documentation\Models\Vendor;
use App\Modules\Integration\Jobs\CloudFactorySyncJob;
use App\Modules\Integration\Models\CloudFactory\Conflict;
use App\Modules\Integration\Models\CloudFactory\Offer;
use App\Modules\Integration\Models\CloudFactory\Operation;
use App\Modules\Integration\Models\CloudFactory\SyncRun;
use App\Modules\Integration\Models\CloudFactory\VendorLink;
use App\Modules\Integration\Models\CloudFactory\WebhookReceipt;
use App\Modules\Integration\Services\CloudFactory\CloudFactoryApiFactory;
use App\Modules\Integration\Services\CloudFactory\CloudFactoryClientMapper;
use App\Modules\Integration\Services\CloudFactory\CloudFactoryIntegration;
use App\Modules\Integration\Services\CloudFactory\CloudFactoryLegalTermsSync;
use App\Modules\Integration\Services\CloudFactory\CloudFactoryServiceManager;
use App\Modules\Integration\Services\CloudFactory\CloudFactorySyncProgress;
use App\Modules\Integration\Services\CloudFactory\CloudFactoryVendorResolver;
use App\Modules\Integration\Services\CloudFactory\CloudFactoryWebhookRegistration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class CloudFactoryController extends Controller
{
    public function index(
        CloudFactoryIntegration $integrations,
    ): View {
        $integration = $integrations->getOrCreate();

        return view('integration::Tech.Admin.System.Integrations.CloudFactory.index', [
            'integration' => $integration,
            'settings' => $integrations->config($integration),
            'hasRefreshToken' => filled($integration->getSecret('refresh_token')),
            'activeSyncRun' => $this->activeManualRun($integration->id),
            'units' => Units::query()->orderBy('name')->get(),
            'clients' => Client::query()->where('active', true)->orderBy('name')->limit(500)->get(),
            'latestRuns' => SyncRun::query()
                ->where('integration_id', $integration->id)
                ->latest('started_at')
                ->limit(10)
                ->get(),
            'latestWebhookReceipts' => WebhookReceipt::query()
                ->where('integration_id', $integration->id)
                ->latest('received_at')
                ->limit(10)
                ->get(),
            'conflicts' => Conflict::query()
                ->where('integration_id', $integration->id)
                ->where('status', 'open')
                ->with('client')
                ->latest()
                ->limit(20)
                ->get(),
            'operations' => Operation::query()
                ->where('integration_id', $integration->id)
                ->with('client')
                ->latest()
                ->limit(20)
                ->get(),
            'offerCounts' => Offer::query()
                ->where('integration_id', $integration->id)
                ->selectRaw('COUNT(*) as total')
                ->selectRaw('SUM(sell_enabled = 1) as enabled')
                ->selectRaw('SUM(excluded = 1) as excluded')
                ->selectRaw('SUM(active_subscription_count > 0) as subscribed')
                ->first(),
        ]);
    }

    public function connect(
        Request $request,
        CloudFactoryIntegration $integrations,
        CloudFactoryApiFactory $apiFactory,
    ): RedirectResponse {
        $data = $request->validate([
            'refresh_token' => ['required', 'string', 'min:20', 'max:8000'],
        ]);

        $integration = $integrations->getOrCreate();

        try {
            $apiFactory->make($integration)->connect($data['refresh_token']);
        } catch (Throwable $exception) {
            return back()->withErrors([
                'refresh_token' => 'Cloud Factory connection failed: '.$exception->getMessage(),
            ]);
        }

        return back()->with('success', 'Cloud Factory connected. Partner identity and roles were verified.');
    }

    public function refreshCapabilities(
        CloudFactoryIntegration $integrations,
        CloudFactoryApiFactory $apiFactory,
    ): RedirectResponse {
        $integration = $integrations->getOrCreate();

        if ($integration->status !== 'active' || blank($integration->getSecret('refresh_token'))) {
            return back()->withErrors([
                'capabilities' => 'Connect Cloud Factory before refreshing provider capabilities.',
            ]);
        }

        try {
            $result = $apiFactory->make($integration)->refreshCapabilities();
        } catch (Throwable $exception) {
            return back()->withErrors([
                'capabilities' => 'Cloud Factory capability refresh failed: '.$exception->getMessage(),
            ]);
        }

        return back()->with(
            'success',
            'Cloud Factory capabilities refreshed. '.count($result['roles']).' roles discovered.'
        );
    }

    public function update(
        Request $request,
        CloudFactoryIntegration $integrations,
    ): RedirectResponse {
        $integration = $integrations->getOrCreate();
        $data = $request->validate([
            'sync_enabled' => ['nullable', 'boolean'],
            'customer_sync_minutes' => ['required', 'integer', 'min:5', 'max:1440'],
            'subscription_sync_minutes' => ['required', 'integer', 'min:5', 'max:1440'],
            'catalogue_sync_day' => ['required', 'integer', 'min:1', 'max:28'],
            'catalogue_sync_time' => ['required', 'date_format:H:i'],
            'pricing_mode' => ['required', Rule::in(['follow_msrp', 'msrp_markup', 'cost_markup'])],
            'markup_percent' => ['required', 'numeric', 'min:-100', 'max:1000'],
            'default_currency' => ['required', 'string', 'size:3'],
            'default_country_code' => ['required', 'string', 'size:2'],
            'default_unit_id' => ['required', 'integer', Rule::exists('units', 'id')],
            'writes_enabled' => ['nullable', 'boolean'],
            'write_scope' => ['required', Rule::in(['test_client', 'all'])],
            'test_client_id' => ['nullable', 'integer', Rule::exists('clients', 'id')],
            'create_missing_clients' => ['nullable', 'boolean'],
            'push_client_updates' => ['nullable', 'boolean'],
            'microsoft_billing_cycle_type' => ['required', 'integer', 'min:0', 'max:10'],
        ]);

        if (($data['write_scope'] ?? 'test_client') === 'all'
            && blank(data_get($integration->config, 'validation_completed_at'))) {
            return back()->withErrors([
                'write_scope' => 'Complete the allowlisted fictitious Client validation before enabling all Client writes.',
            ]);
        }

        if ($request->boolean('writes_enabled') && blank($data['test_client_id'] ?? null)
            && ($data['write_scope'] ?? 'test_client') === 'test_client') {
            return back()->withErrors([
                'test_client_id' => 'Choose the fictitious Client before enabling provider writes.',
            ]);
        }

        $config = array_replace($integrations->config($integration), [
            'sync_enabled' => $request->boolean('sync_enabled'),
            'customer_sync_minutes' => (int) $data['customer_sync_minutes'],
            'subscription_sync_minutes' => (int) $data['subscription_sync_minutes'],
            'catalogue_sync_day' => (int) $data['catalogue_sync_day'],
            'catalogue_sync_time' => $data['catalogue_sync_time'],
            'pricing_mode' => $data['pricing_mode'],
            'markup_percent' => (float) $data['markup_percent'],
            'default_currency' => strtoupper($data['default_currency']),
            'default_country_code' => strtoupper($data['default_country_code']),
            'default_unit_id' => (int) $data['default_unit_id'],
            'writes_enabled' => $request->boolean('writes_enabled'),
            'write_scope' => $data['write_scope'],
            'test_client_id' => $data['test_client_id'] ?? null,
            'create_missing_clients' => $request->boolean('create_missing_clients'),
            'push_client_updates' => $request->boolean('push_client_updates'),
            'microsoft_billing_cycle_type' => (int) $data['microsoft_billing_cycle_type'],
            'configured_by' => $request->user()->id,
        ]);

        $integration->forceFill(['config' => $config])->save();

        return back()->with('success', 'Cloud Factory settings updated.');
    }

    public function revoke(
        CloudFactoryIntegration $integrations,
        CloudFactoryApiFactory $apiFactory,
        CloudFactoryWebhookRegistration $webhooks,
    ): RedirectResponse {
        $integration = $integrations->getOrCreate();

        try {
            if (data_get($integration->config, 'webhooks_enabled', false)) {
                $webhooks->disable($integration);
            }

            $apiFactory->make($integration)->revokeAllTokens();
        } catch (Throwable $exception) {
            return back()->withErrors([
                'revoke' => 'Cloud Factory revocation failed: '.$exception->getMessage(),
            ]);
        }

        return back()->with('success', 'Cloud Factory tokens were revoked and the integration was disabled.');
    }

    public function enableWebhooks(
        CloudFactoryIntegration $integrations,
        CloudFactoryWebhookRegistration $webhooks,
    ): RedirectResponse {
        $integration = $integrations->getOrCreate();

        try {
            $result = $webhooks->enable($integration);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            return back()->withErrors([
                'webhook' => 'Cloud Factory webhook registration failed: '.$exception->getMessage(),
            ]);
        }

        return back()->with(
            'success',
            'Cloud Factory webhooks enabled for '.count($result['events']).' notification events.'
        );
    }

    public function disableWebhooks(
        CloudFactoryIntegration $integrations,
        CloudFactoryWebhookRegistration $webhooks,
    ): RedirectResponse {
        $integration = $integrations->getOrCreate();

        try {
            $webhooks->disable($integration);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            return back()->withErrors([
                'webhook' => 'Cloud Factory webhook removal failed: '.$exception->getMessage(),
            ]);
        }

        return back()->with(
            'success',
            'Cloud Factory webhook registrations were removed and the shared key was deleted.'
        );
    }

    public function sync(
        Request $request,
        CloudFactoryIntegration $integrations,
        CloudFactorySyncProgress $progress,
    ): RedirectResponse|JsonResponse {
        $data = $request->validate([
            'kind' => ['required', Rule::in(['all', 'customers', 'catalogue', 'subscriptions'])],
        ]);
        $integration = $integrations->getOrCreate();

        if ($integration->status !== 'active') {
            throw ValidationException::withMessages([
                'kind' => 'Connect Cloud Factory before starting synchronization.',
            ]);
        }

        $activeRun = $this->activeManualRun($integration->id);

        if ($activeRun) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'A manual Cloud Factory synchronization is already active.',
                    'run' => $this->syncRunPayload($activeRun),
                ], 409);
            }

            return back()->with('warning', 'A manual Cloud Factory synchronization is already active.');
        }

        $run = SyncRun::query()->create([
            'integration_id' => $integration->id,
            'kind' => $data['kind'],
            'status' => 'queued',
            'metadata' => $progress->initialMetadata(
                $data['kind'],
                true,
                $request->user()?->id,
            ),
            'started_at' => now(),
        ]);

        CloudFactorySyncJob::dispatch($data['kind'], $run->id);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Cloud Factory synchronization was queued.',
                'run' => $this->syncRunPayload($run),
            ], 202);
        }

        return back()->with('success', 'Cloud Factory synchronization was queued.');
    }

    public function syncStatus(
        SyncRun $run,
        CloudFactoryIntegration $integrations,
    ): JsonResponse {
        $integration = $integrations->getOrCreate();

        abort_unless($run->integration_id === $integration->id, 404);

        return response()->json(['run' => $this->syncRunPayload($run->refresh())]);
    }

    public function catalogue(
        Request $request,
        CloudFactoryIntegration $integrations,
    ): View {
        $integration = $integrations->getOrCreate();
        $vendorFilter = $request->string('vendor')->trim()->toString();
        $recurrenceFilter = $request->string('recurrence_term')->trim()->toString();
        $billingFilter = $request->string('billing_term')->trim()->toString();
        $sortKey = $request->string('sort')->trim()->toString();
        $sortDirection = strtolower($request->string('direction')->trim()->toString()) === 'desc' ? 'desc' : 'asc';
        $sortColumns = [
            'recurrence_term' => 'recurrence_term',
            'billing_term' => 'billing_term',
        ];
        $sortColumn = $sortColumns[$sortKey] ?? null;
        $query = Offer::query()
            ->where('integration_id', $integration->id)
            ->with(['vendor', 'service'])
            ->when($request->filled('q'), function ($query) use ($request): void {
                $needle = '%'.$request->string('q')->trim()->toString().'%';
                $query->where(function ($query) use ($needle): void {
                    $query->where('name', 'like', $needle)
                        ->orWhere('sku', 'like', $needle)
                        ->orWhere('vendor_name', 'like', $needle)
                        ->orWhereHas('vendor', fn ($vendor) => $vendor->where('name', 'like', $needle));
                });
            })
            ->when($vendorFilter === 'unmapped', fn ($query) => $query->whereNull('vendor_id'))
            ->when(ctype_digit($vendorFilter), fn ($query) => $query->where('vendor_id', (int) $vendorFilter))
            ->when(ctype_digit($recurrenceFilter), fn ($query) => $query->where('recurrence_term', (int) $recurrenceFilter))
            ->when(ctype_digit($billingFilter), fn ($query) => $query->where('billing_term', (int) $billingFilter))
            ->when($request->input('state') === 'enabled', fn ($query) => $query->where('sell_enabled', true))
            ->when($request->input('state') === 'excluded', fn ($query) => $query->where('excluded', true))
            ->when($request->input('state') === 'subscribed', fn ($query) => $query->where('active_subscription_count', '>', 0));

        $vendorLinks = VendorLink::query()
            ->where('integration_id', $integration->id)
            ->with('vendor')
            ->orderBy('external_name')
            ->get();
        $vendorIds = Offer::query()
            ->where('integration_id', $integration->id)
            ->whereNotNull('vendor_id')
            ->pluck('vendor_id')
            ->merge($vendorLinks->pluck('vendor_id')->filter())
            ->unique();
        $vendorProductCounts = Offer::query()
            ->where('integration_id', $integration->id)
            ->whereNotNull('external_category_id')
            ->selectRaw('external_category_id, COUNT(*) as aggregate')
            ->groupBy('external_category_id')
            ->pluck('aggregate', 'external_category_id');

        $recurrenceTerms = Offer::query()
            ->where('integration_id', $integration->id)
            ->whereNotNull('recurrence_term')
            ->distinct()
            ->orderBy('recurrence_term')
            ->pluck('recurrence_term')
            ->map(fn ($term): array => [
                'value' => (string) $term,
                'label' => (new Offer(['recurrence_term' => (int) $term]))->commitmentLabel(),
            ]);
        $billingTerms = Offer::query()
            ->where('integration_id', $integration->id)
            ->whereNotNull('billing_term')
            ->distinct()
            ->orderBy('billing_term')
            ->pluck('billing_term')
            ->map(fn ($term): array => [
                'value' => (string) $term,
                'label' => (new Offer(['billing_term' => (int) $term]))->billingLabel(),
            ]);

        if ($sortColumn) {
            $query->orderByRaw($sortColumn.' IS NULL')
                ->orderBy($sortColumn, $sortDirection)
                ->orderBy($sortKey === 'recurrence_term' ? 'billing_term' : 'recurrence_term')
                ->orderBy('name');
        } else {
            $query->orderByRaw('vendor_id IS NULL DESC')
                ->orderBy('vendor_name')
                ->orderBy('name');
        }

        return view('integration::Tech.Admin.System.Integrations.CloudFactory.catalogue', [
            'integration' => $integration,
            'offers' => $query->paginate(50)->withQueryString(),
            'vendors' => Vendor::query()->whereIn('id', $vendorIds)->orderBy('name')->get(['id', 'name']),
            'allVendors' => Vendor::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'vendorLinks' => $vendorLinks,
            'vendorProductCounts' => $vendorProductCounts,
            'recurrenceTerms' => $recurrenceTerms,
            'billingTerms' => $billingTerms,
            'vendorMappingCounts' => [
                'mapped' => $vendorLinks->whereNotNull('vendor_id')->count(),
                'unmapped' => $vendorLinks->whereNull('vendor_id')->count(),
            ],
            'filters' => [
                'q' => $request->string('q')->trim()->toString(),
                'vendor' => $vendorFilter,
                'state' => $request->string('state')->trim()->toString(),
                'recurrence_term' => $recurrenceFilter,
                'billing_term' => $billingFilter,
                'sort' => $sortColumn ? $sortKey : '',
                'direction' => $sortColumn ? $sortDirection : '',
            ],
        ]);
    }

    public function updateVendorLink(
        Request $request,
        VendorLink $vendorLink,
        CloudFactoryIntegration $integrations,
        CloudFactoryVendorResolver $vendors,
    ): RedirectResponse {
        $data = $request->validate([
            'vendor_id' => ['required', 'integer', Rule::exists('vendors', 'id')],
        ]);
        $integration = $integrations->getOrCreate();

        abort_unless($vendorLink->integration_id === $integration->id, 404);
        $vendor = Vendor::query()->findOrFail($data['vendor_id']);
        $vendors->linkManually($vendorLink, $vendor);

        return back()->with(
            'success',
            $vendorLink->external_name.' was linked to Nexum Vendor '.$vendor->name.'.'
        );
    }

    public function updateOffer(
        Request $request,
        Offer $offer,
        CloudFactoryServiceManager $services,
        CloudFactoryLegalTermsSync $legalTerms,
    ): RedirectResponse {
        $data = $request->validate([
            'sell_enabled' => ['nullable', 'boolean'],
            'excluded' => ['nullable', 'boolean'],
            'price_mode' => ['nullable', Rule::in(['follow_msrp', 'msrp_markup', 'cost_markup', 'manual'])],
            'markup_percent' => ['nullable', 'numeric', 'min:-100', 'max:1000'],
            'manual_sale_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $wasSellEnabled = $offer->sell_enabled;
        $sellEnabled = $request->boolean('sell_enabled');
        $offer->forceFill([
            'sell_enabled' => $sellEnabled,
            'excluded' => $request->boolean('excluded') && $offer->active_subscription_count === 0,
            'price_mode' => $data['price_mode'] ?? null,
            'markup_percent' => $data['markup_percent'] ?? null,
            'manual_sale_price' => $data['manual_sale_price'] ?? null,
        ])->save();

        if ($sellEnabled || $offer->service_id || $offer->active_subscription_count > 0) {
            $services->ensureService($offer->refresh(), $offer->active_subscription_count > 0);
        }

        $legalTerms->syncOffer($offer->refresh());

        if ($sellEnabled && ! $wasSellEnabled && $offer->integration?->status === 'active') {
            CloudFactorySyncJob::dispatch('catalogue');
        }

        return back()->with(
            'success',
            $sellEnabled && ! $wasSellEnabled
                ? 'Cloud Factory offer updated. A current catalogue and legal terms check was queued.'
                : 'Cloud Factory offer updated.'
        );
    }

    public function linkClient(
        Request $request,
        Conflict $conflict,
        CloudFactoryIntegration $integrations,
        CloudFactoryClientMapper $mapper,
    ): RedirectResponse {
        $data = $request->validate([
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')],
        ]);
        $integration = $integrations->getOrCreate();

        abort_unless($conflict->integration_id === $integration->id, 404);
        $mapper->linkManually(
            $integration,
            Client::query()->findOrFail($data['client_id']),
            $conflict->provider_payload ?? []
        );

        return back()->with('success', 'Client and Cloud Factory customer were linked manually.');
    }

    public function completeValidation(
        CloudFactoryIntegration $integrations,
    ): RedirectResponse {
        $integration = $integrations->getOrCreate();
        $testClientId = (int) data_get($integration->config, 'test_client_id');

        $confirmed = Operation::query()
            ->where('integration_id', $integration->id)
            ->where('client_id', $testClientId)
            ->where('status', 'confirmed')
            ->exists();

        if (! $testClientId || ! $confirmed) {
            return back()->withErrors([
                'validation' => 'A confirmed Cloud Factory operation on the allowlisted fictitious Client is required first.',
            ]);
        }

        $config = $integration->config ?? [];
        $config['validation_completed_at'] = now()->toIso8601String();
        $integration->forceFill(['config' => $config])->save();

        return back()->with('success', 'Fictitious Client validation recorded. All-Client write scope can now be selected.');
    }

    private function activeManualRun(string $integrationId): ?SyncRun
    {
        return SyncRun::query()
            ->where('integration_id', $integrationId)
            ->whereIn('status', ['queued', 'running', 'retrying'])
            ->latest('created_at')
            ->get()
            ->first(fn (SyncRun $run): bool => (bool) data_get($run->metadata, 'manual', false));
    }

    private function syncRunPayload(SyncRun $run): array
    {
        return [
            'id' => $run->id,
            'kind' => $run->kind,
            'status' => $run->status,
            'progress' => data_get($run->metadata, 'progress', []),
            'queued_for_seconds' => $run->status === 'queued'
                ? (int) $run->created_at?->diffInSeconds(now(), true) : 0,
            'records' => [
                'seen' => (int) $run->records_seen,
                'created' => (int) $run->records_created,
                'updated' => (int) $run->records_updated,
                'conflicted' => (int) $run->records_conflicted,
            ],
            'error' => $run->status === 'failed' ? $run->last_error : null,
            'status_url' => route('tech.admin.system.integrations.cloudfactory.sync.status', $run),
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
        ];
    }
}
