<?php

namespace App\Modules\Clients\Actions;

use App\Modules\Commercial\Actions\RecordLegalAcceptance;
use App\Modules\Commercial\Models\Contracts\ContractItem;
use App\Modules\CustomerPortal\Models\CustomerPortalMembership;
use App\Modules\CustomerPortal\Support\CustomerPortalContext;
use App\Modules\Integration\Models\CloudFactory\Subscription;
use App\Modules\Integration\Services\CloudFactory\CloudFactoryIntegration;
use App\Modules\Integration\Services\CloudFactory\CloudFactoryLicenceService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

/** Shared current licence effects; API transport adds durable command intent around these actions. */
class PortalClientLicenceWorkflow
{
    public function issue(
        Request $request,
        CloudFactoryIntegration $integrations,
        CloudFactoryLicenceService $licences,
        RecordLegalAcceptance $acceptance,
    ): \App\Modules\Integration\Models\CloudFactory\Operation {
        $context = $this->context($request);
        $this->authorizeOrdering($context);
        $data = $request->validate([
            'contract_item_id' => ['required', 'integer', Rule::exists('contract_items', 'id')],
            'quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'name' => ['required', 'string', 'max:255'],
            'confirm' => ['required', 'accepted'],
        ]);

        $item = $this->eligibleItem($context, (int) $data['contract_item_id'], true);
        $offer = $item->cloudFactoryOffer;
        $event = $acceptance->forLicence(
            $request,
            $context,
            'licence_issue',
            $item,
            $offer,
            null,
            (int) $data['quantity'],
            null,
            $data['name'],
        );

        try {
            $operation = $licences->issue(
                $integrations->getOrCreate(),
                $context->client,
                $offer,
                (int) $data['quantity'],
                $request->user()->id,
                $item,
            );
            $event->forceFill([
                'cloudfactory_operation_id' => $operation->id,
                'status' => $operation->status,
            ])->save();
        } catch (Throwable $exception) {
            $event->forceFill([
                'status' => 'failed',
                'metadata' => ['error' => mb_substr($exception->getMessage(), 0, 500)],
            ])->save();

            if ($exception instanceof \Illuminate\Validation\ValidationException) {
                throw $exception;
            }

            throw $exception;
        }

        return $operation;
    }

    public function quantity(
        Request $request,
        Subscription $subscription,
        CloudFactoryIntegration $integrations,
        CloudFactoryLicenceService $licences,
        RecordLegalAcceptance $acceptance,
    ): \App\Modules\Integration\Models\CloudFactory\Operation {
        $context = $this->context($request);
        $this->authorizeOrdering($context);
        $this->owns($context, $subscription);
        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:0', 'max:100000'],
            'name' => ['required', 'string', 'max:255'],
            'confirm' => ['required', 'accepted'],
        ]);

        $subscription->loadMissing(['contractItem.contract', 'contractItem.service', 'offer']);
        $item = $subscription->contractItem;
        abort_unless($item && $subscription->offer, 404);
        $event = $acceptance->forLicence(
            $request,
            $context,
            'licence_quantity_change',
            $item,
            $subscription->offer,
            $subscription,
            (int) $data['quantity'],
            (int) $subscription->quantity,
            $data['name'],
        );

        try {
            $operation = $licences->changeQuantity(
                $integrations->getOrCreate(),
                $subscription,
                (int) $data['quantity'],
                $request->user()->id,
            );
            $event->forceFill([
                'cloudfactory_operation_id' => $operation->id,
                'status' => $operation->status,
            ])->save();
        } catch (Throwable $exception) {
            $event->forceFill([
                'status' => 'failed',
                'metadata' => ['error' => mb_substr($exception->getMessage(), 0, 500)],
            ])->save();

            if ($exception instanceof \Illuminate\Validation\ValidationException) {
                throw $exception;
            }

            throw $exception;
        }

        return $operation;
    }

    public function renewal(
        Request $request,
        Subscription $subscription,
        CloudFactoryIntegration $integrations,
        CloudFactoryLicenceService $licences,
        RecordLegalAcceptance $acceptance,
    ): \App\Modules\Integration\Models\CloudFactory\Operation {
        $context = $this->context($request);
        $this->authorizeOrdering($context);
        $this->owns($context, $subscription);
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'name' => ['required', 'string', 'max:255'],
            'confirm' => ['required', 'accepted'],
        ]);

        $subscription->loadMissing(['contractItem.contract', 'contractItem.service', 'offer']);
        $item = $subscription->contractItem;
        abort_unless($item && $subscription->offer, 404);
        $event = $acceptance->forLicence(
            $request,
            $context,
            'licence_renewal_change',
            $item,
            $subscription->offer,
            $subscription,
            (int) $subscription->quantity,
            (int) $subscription->quantity,
            $data['name'],
        );

        try {
            $operation = $licences->setAutoRenew(
                $integrations->getOrCreate(),
                $subscription,
                (bool) $data['enabled'],
                $request->user()->id,
            );
            $event->forceFill([
                'cloudfactory_operation_id' => $operation->id,
                'status' => $operation->status,
            ])->save();
        } catch (Throwable $exception) {
            $event->forceFill([
                'status' => 'failed',
                'metadata' => ['error' => mb_substr($exception->getMessage(), 0, 500)],
            ])->save();

            if ($exception instanceof \Illuminate\Validation\ValidationException) {
                throw $exception;
            }

            throw $exception;
        }

        return $operation;
    }

    private function eligibleItem(
        CustomerPortalContext $context,
        int $itemId,
        bool $requireAdditions,
    ): ContractItem {
        $today = now()->toDateString();

        return ContractItem::query()
            ->whereKey($itemId)
            ->whereHas('contract', fn ($query) => $query
                ->where('client_id', $context->client->id)
                ->where('approval_status', 'won')
                ->when($requireAdditions, fn ($contract) => $contract->where('allow_license_additions', true))
                ->whereDate('start_date', '<=', $today)
                ->where(fn ($period) => $period
                    ->whereNull('end_date')
                    ->orWhereDate('end_date', '>=', $today)))
            ->whereNotNull('cloudfactory_offer_id')
            ->whereHas('cloudFactoryOffer', fn ($query) => $query
                ->where('sell_enabled', true)
                ->where('excluded', false)
                ->where('purchasable', true))
            ->with(['contract', 'service.serviceTerms.currentVersion', 'cloudFactoryOffer'])
            ->firstOrFail();
    }

    private function authorizeOrdering(CustomerPortalContext $context): void
    {
        abort_unless(
            $context->site === null
                && $context->membership->role === CustomerPortalMembership::ROLE_CUSTOMER_ADMIN,
            403,
            'Customer admin access is required for licence ordering.'
        );
    }

    private function owns(CustomerPortalContext $context, Subscription $subscription): void
    {
        abort_unless((int) $subscription->client_id === (int) $context->client->id, 404);
        $integration = \App\Models\System\Integrations\Integration::query()->where('type', 'cloudfactory')->first();
        abort_unless($integration && (string) $subscription->integration_id === (string) $integration->id, 404);

    }

    private function context(Request $request): CustomerPortalContext
    {
        /** @var CustomerPortalContext $context */
        $context = $request->attributes->get('customerPortalContext');

        return $context;
    }
}
