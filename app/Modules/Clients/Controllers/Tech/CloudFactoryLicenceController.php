<?php

namespace App\Modules\Clients\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Models\Clients\Client;
use App\Modules\Clients\Menus\SideBar\ClientsMenu;
use App\Modules\Integration\Jobs\CloudFactorySyncJob;
use App\Modules\Integration\Models\CloudFactory\ClientLink;
use App\Modules\Integration\Models\CloudFactory\Offer;
use App\Modules\Integration\Models\CloudFactory\Operation;
use App\Modules\Integration\Models\CloudFactory\Subscription;
use App\Modules\Integration\Services\CloudFactory\CloudFactoryIntegration;
use App\Modules\Integration\Services\CloudFactory\CloudFactoryLicenceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class CloudFactoryLicenceController extends Controller
{
    public function index(
        Client $client,
        CloudFactoryIntegration $integrations,
    ): View {
        $integration = $integrations->getOrCreate();

        return view('clients::Tech.licenses', [
            'client' => $client,
            'sidebarMenuItems' => (new ClientsMenu)->ClientsMenu($client),
            'integration' => $integration,
            'settings' => $integrations->config($integration),
            'link' => ClientLink::query()
                ->where('integration_id', $integration->id)
                ->where('client_id', $client->id)
                ->first(),
            'subscriptions' => Subscription::query()
                ->where('integration_id', $integration->id)
                ->where('client_id', $client->id)
                ->with(['offer.vendor', 'service', 'contract', 'contractItem'])
                ->orderBy('provider_family')
                ->orderBy('name')
                ->get(),
            'offers' => Offer::query()
                ->where('integration_id', $integration->id)
                ->where('sell_enabled', true)
                ->where('excluded', false)
                ->whereNotNull('service_id')
                ->whereIn('provider_family', ['microsoft', 'adobe'])
                ->with(['vendor', 'service'])
                ->orderBy('vendor_name')
                ->orderBy('name')
                ->get(),
            'operations' => Operation::query()
                ->where('integration_id', $integration->id)
                ->where('client_id', $client->id)
                ->latest()
                ->limit(30)
                ->get(),
        ]);
    }

    public function issue(
        Request $request,
        Client $client,
        CloudFactoryIntegration $integrations,
        CloudFactoryLicenceService $licences,
    ): RedirectResponse {
        $integration = $integrations->getOrCreate();
        $data = $request->validate([
            'offer_id' => [
                'required',
                'uuid',
                Rule::exists('cloudfactory_offers', 'id')->where('integration_id', $integration->id),
            ],
            'quantity' => ['required', 'integer', 'min:1', 'max:100000'],
        ]);

        try {
            $operation = $licences->issue(
                $integration,
                $client,
                Offer::query()->findOrFail($data['offer_id']),
                (int) $data['quantity'],
                $request->user()->id
            );
        } catch (Throwable $exception) {
            if ($exception instanceof \Illuminate\Validation\ValidationException) {
                throw $exception;
            }

            return back()->withErrors(['licence' => $exception->getMessage()]);
        }

        CloudFactorySyncJob::dispatch('subscriptions')->delay(now()->addSeconds(30));

        return back()->with('success', 'Cloud Factory licence operation '.$operation->status.'.');
    }

    public function quantity(
        Request $request,
        Client $client,
        Subscription $subscription,
        CloudFactoryIntegration $integrations,
        CloudFactoryLicenceService $licences,
    ): RedirectResponse {
        $this->owns($client, $subscription);
        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:0', 'max:100000'],
        ]);

        try {
            $operation = $licences->changeQuantity(
                $integrations->getOrCreate(),
                $subscription,
                (int) $data['quantity'],
                $request->user()->id
            );
        } catch (Throwable $exception) {
            if ($exception instanceof \Illuminate\Validation\ValidationException) {
                throw $exception;
            }

            return back()->withErrors(['licence' => $exception->getMessage()]);
        }

        CloudFactorySyncJob::dispatch('subscriptions')->delay(now()->addSeconds(30));

        return back()->with('success', 'Quantity change '.$operation->status.'.');
    }

    public function renewal(
        Request $request,
        Client $client,
        Subscription $subscription,
        CloudFactoryIntegration $integrations,
        CloudFactoryLicenceService $licences,
    ): RedirectResponse {
        $this->owns($client, $subscription);
        $data = $request->validate(['enabled' => ['required', 'boolean']]);

        try {
            $operation = $licences->setAutoRenew(
                $integrations->getOrCreate(),
                $subscription,
                (bool) $data['enabled'],
                $request->user()->id
            );
        } catch (Throwable $exception) {
            if ($exception instanceof \Illuminate\Validation\ValidationException) {
                throw $exception;
            }

            return back()->withErrors(['licence' => $exception->getMessage()]);
        }

        CloudFactorySyncJob::dispatch('subscriptions')->delay(now()->addSeconds(30));

        return back()->with('success', 'Renewal change '.$operation->status.'.');
    }

    public function status(
        Request $request,
        Client $client,
        Subscription $subscription,
        CloudFactoryIntegration $integrations,
        CloudFactoryLicenceService $licences,
    ): RedirectResponse {
        $this->owns($client, $subscription);
        $data = $request->validate([
            'status' => ['required', Rule::in(['activate', 'suspend'])],
        ]);

        try {
            $operation = $licences->setMicrosoftStatus(
                $integrations->getOrCreate(),
                $subscription,
                $data['status'],
                $request->user()->id
            );
        } catch (Throwable $exception) {
            if ($exception instanceof \Illuminate\Validation\ValidationException) {
                throw $exception;
            }

            return back()->withErrors(['licence' => $exception->getMessage()]);
        }

        CloudFactorySyncJob::dispatch('subscriptions')->delay(now()->addSeconds(30));

        return back()->with('success', 'Status change '.$operation->status.'.');
    }

    private function owns(Client $client, Subscription $subscription): void
    {
        abort_unless((int) $subscription->client_id === (int) $client->id, 404);
    }
}
