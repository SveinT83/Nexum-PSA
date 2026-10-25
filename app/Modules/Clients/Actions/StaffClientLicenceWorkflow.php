<?php

namespace App\Modules\Clients\Actions;

use App\Models\Clients\Client;
use App\Modules\Integration\Models\CloudFactory\Offer;
use App\Modules\Integration\Models\CloudFactory\Subscription;
use App\Modules\Integration\Services\CloudFactory\CloudFactoryIntegration;
use App\Modules\Integration\Services\CloudFactory\CloudFactoryLicenceService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

/** Shared current licence effects; API transport adds durable command intent around these actions. */
class StaffClientLicenceWorkflow
{
    public function issue(
        Request $request,
        Client $client,
        CloudFactoryIntegration $integrations,
        CloudFactoryLicenceService $licences,
    ): \App\Modules\Integration\Models\CloudFactory\Operation {
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

            throw $exception;
        }

        return $operation;
    }

    public function quantity(
        Request $request,
        Client $client,
        Subscription $subscription,
        CloudFactoryIntegration $integrations,
        CloudFactoryLicenceService $licences,
    ): \App\Modules\Integration\Models\CloudFactory\Operation {
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

            throw $exception;
        }

        return $operation;
    }

    public function renewal(
        Request $request,
        Client $client,
        Subscription $subscription,
        CloudFactoryIntegration $integrations,
        CloudFactoryLicenceService $licences,
    ): \App\Modules\Integration\Models\CloudFactory\Operation {
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

            throw $exception;
        }

        return $operation;
    }

    public function status(
        Request $request,
        Client $client,
        Subscription $subscription,
        CloudFactoryIntegration $integrations,
        CloudFactoryLicenceService $licences,
    ): \App\Modules\Integration\Models\CloudFactory\Operation {
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

            throw $exception;
        }

        return $operation;
    }

    private function owns(Client $client, Subscription $subscription): void
    {
        abort_unless((int) $subscription->client_id === (int) $client->id, 404);
        $integration = \App\Models\System\Integrations\Integration::query()->where('type', 'cloudfactory')->first();
        abort_unless($integration && (string) $subscription->integration_id === (string) $integration->id, 404);

    }
}
