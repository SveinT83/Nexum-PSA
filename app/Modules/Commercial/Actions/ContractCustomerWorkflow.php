<?php

namespace App\Modules\Commercial\Actions;

use App\Modules\Commercial\Models\Contracts\Contracts;
use App\Modules\Commercial\Support\ContractCustomerDocument;
use App\Modules\Commercial\Support\PortalContractAccess;
use App\Modules\CustomerPortal\Actions\RecordCustomerPortalAudit;
use App\Modules\CustomerPortal\Support\CustomerPortalContext;
use App\Modules\Notification\Actions\SendCustomerPortalNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Customer identity and immutable acceptance effects are shared by web and typed APIs. */
class ContractCustomerWorkflow
{
    public function currentPortal(Request $request): CustomerPortalContext
    {
        $before = $request->attributes->get('customerPortalContext');
        $user = \App\Models\Core\User::query()->findOrFail($request->user()->id);
        abort_unless($user->isActive(), 403);
        $fresh = app(\App\Modules\CustomerPortal\Support\CustomerPortalContextResolver::class)->resolveForUser($user, $before?->membership->id);
        abort_unless($fresh && $before && (int) $fresh->membership->id === (int) $before->membership->id, 403, 'The portal membership changed.');
        $request->attributes->set('customerPortalContext', $fresh);

        return $fresh;
    }

    public function publicContract(string $token, bool $lock = false): Contracts
    {
        abort_if($token === '' || strlen($token) > 200, 404);
        $query = Contracts::query()->where('secure_token', $token)->whereIn('approval_status', ['sent_quote', 'sent_contract', 'approved', 'won']);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    public function viewed(Request $request, Contracts $contract, ?string $token = null): void
    {
        DB::transaction(function () use ($request, $contract, $token) {
            $locked = $token !== null ? $this->publicContract($token, true) : Contracts::query()->lockForUpdate()->findOrFail($contract->id);
            app(ContractCustomerDocument::class)->resolve($locked);
            $locked->forceFill(['viewed_at' => now(), 'viewed_ip' => $request->ip(), 'viewed_ua' => $request->userAgent()])->save();
        });
    }

    public function acceptPublic(Request $request, string $token, array $data): bool
    {
        return DB::transaction(function () use ($request, $token, $data): bool {
            $locked = Contracts::query()
                ->where('secure_token', $token)
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($locked->approval_status, ['sent_quote', 'sent_contract'], true)) {
                return false;
            }

            app(CaptureContractCustomerDocument::class)->handle($locked, 'won');
            $locked->forceFill([
                'approval_status' => 'won',
                'accepted_at' => now(),
                'accepted_by_name' => $data['name'],
                'accepted_ip' => $request->ip(),
                'accepted_ua' => $request->userAgent(),
            ])->save();

            return true;
        });
    }

    public function acceptPortal(Request $request, Contracts $contract, array $data): ?Contracts
    {
        $context = $this->currentPortal($request);
        $access = app(PortalContractAccess::class);
        $audit = app(RecordCustomerPortalAudit::class);
        $legalAcceptance = app(RecordLegalAcceptance::class);
        $customerDocuments = app(CaptureContractCustomerDocument::class);
        abort_unless($access->canView($context, $contract), 404);
        $acceptedContract = DB::transaction(function () use ($request, $contract, $context, $access, $audit, $legalAcceptance, $customerDocuments, $data): ?Contracts {
            $locked = Contracts::query()
                ->lockForUpdate()
                ->findOrFail($contract->getKey());

            if (! $access->canAccept($locked)) {
                return null;
            }

            $customerDocuments->handle($locked, 'won');

            $locked->forceFill([
                'approval_status' => 'won',
                'accepted_at' => now(),
                'accepted_by_name' => $data['name'],
                'accepted_ip' => $request->ip(),
                'accepted_ua' => $request->userAgent(),
                'portal_accepted_account_id' => $context->account->id,
                'portal_accepted_membership_id' => $context->membership->id,
                'portal_accepted_contact_id' => $context->contact->id,
            ])->save();

            $legalAcceptance->forContract($request, $context, $locked, $data['name']);

            $audit->handle(
                'portal_contract_accepted',
                $context->account,
                $request->user(),
                $context->contact,
                $context->client,
                $context->site,
                [
                    'contract_id' => $locked->id,
                    'approval_status' => 'won',
                    'accepted_by_name' => $data['name'],
                ],
                $request,
            );

            return $locked;
        });
        if (! $acceptedContract) {
            return null;
        }
        $portalNotifications = app(SendCustomerPortalNotification::class);
        DB::afterCommit(function () use ($portalNotifications, $context, $acceptedContract, $data) {
            $portalNotifications->handle(
                type: 'portal_contract_accepted',
                clientId: (int) $context->client->id,
                siteId: null,
                title: 'Avtale godtatt',
                body: $acceptedContract->description.' ble godtatt av '.$data['name'].'.',
                url: route('customer-portal.contracts.show', $acceptedContract),
                sourceType: Contracts::class,
                sourceId: $acceptedContract->id,
                metadata: [
                    'contract_id' => $acceptedContract->id,
                    'accepted_by_name' => $data['name'],
                ],
            );

        });

        return $acceptedContract;
    }
}
