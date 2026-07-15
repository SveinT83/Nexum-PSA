<?php

namespace App\Modules\CustomerPortal\Actions;

use App\Models\Core\User;
use App\Modules\CustomerPortal\Models\CustomerPortalAccount;
use App\Modules\CustomerPortal\Models\CustomerPortalInvitation;
use App\Modules\CustomerPortal\Models\CustomerPortalMembership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AcceptCustomerPortalInvitation
{
    public function __construct(private readonly RecordCustomerPortalAudit $audit)
    {
    }

    public function passwordRequired(CustomerPortalInvitation $invitation): bool
    {
        $user = User::query()->where('email', $invitation->email)->first();

        return ! $user || $user->isPending();
    }

    /**
     * @return array{user: User, account: CustomerPortalAccount, membership: CustomerPortalMembership}
     */
    public function handle(CustomerPortalInvitation $invitation, ?string $password = null): array
    {
        if (! $invitation->isValid()) {
            throw ValidationException::withMessages(['token' => 'This portal invitation is invalid or expired.']);
        }

        return DB::transaction(function () use ($invitation, $password): array {
            $user = User::query()->where('email', $invitation->email)->first();

            if ($user && $user->isDisabled()) {
                throw ValidationException::withMessages(['email' => 'The matching user account is disabled. Contact an administrator.']);
            }

            if ($user && $user->contact_id && (int) $user->contact_id !== (int) $invitation->contact_id) {
                throw ValidationException::withMessages(['email' => 'The matching user account is linked to another Contact.']);
            }

            if (! $user) {
                $user = User::query()->create([
                    'contact_id' => $invitation->contact_id,
                    'name' => $invitation->contact?->display_name ?: $invitation->email,
                    'email' => $invitation->email,
                    'password' => Hash::make((string) $password),
                    'status' => User::STATUS_ACTIVE,
                ]);
            } elseif ($user->isPending()) {
                $user->forceFill([
                    'contact_id' => $user->contact_id ?: $invitation->contact_id,
                    'password' => Hash::make((string) $password),
                    'status' => User::STATUS_ACTIVE,
                    'email_verified_at' => now(),
                ])->save();
            } else {
                $user->forceFill([
                    'contact_id' => $user->contact_id ?: $invitation->contact_id,
                ])->save();
            }

            if (! $user->email_verified_at) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            $existingAccount = CustomerPortalAccount::query()
                ->where('contact_id', $invitation->contact_id)
                ->where('user_id', '!=', $user->id)
                ->first();

            if ($existingAccount) {
                throw ValidationException::withMessages(['email' => 'This Contact is already linked to another portal account.']);
            }

            $existingUserAccount = CustomerPortalAccount::query()
                ->where('user_id', $user->id)
                ->first();

            if ($existingUserAccount && (int) $existingUserAccount->contact_id !== (int) $invitation->contact_id) {
                throw ValidationException::withMessages(['email' => 'This user account is already linked to another portal Contact.']);
            }

            $account = CustomerPortalAccount::query()->updateOrCreate(
                ['user_id' => $user->id],
                [
                    'contact_id' => $invitation->contact_id,
                    'status' => CustomerPortalAccount::STATUS_ACTIVE,
                    'last_login_at' => now(),
                    'metadata' => ['activated_from_invitation_id' => $invitation->id],
                ]
            );

            $membership = CustomerPortalMembership::query()
                ->where('customer_portal_account_id', $account->id)
                ->where('client_id', $invitation->client_id)
                ->when($invitation->site_id, fn ($query) => $query->where('site_id', $invitation->site_id), fn ($query) => $query->whereNull('site_id'))
                ->first();

            if ($membership) {
                $membership->forceFill([
                    'role' => $invitation->role,
                    'status' => CustomerPortalMembership::STATUS_ACTIVE,
                    'disabled_at' => null,
                ])->save();
            } else {
                $membership = CustomerPortalMembership::query()->create([
                    'customer_portal_account_id' => $account->id,
                    'client_id' => $invitation->client_id,
                    'site_id' => $invitation->site_id,
                    'role' => $invitation->role,
                    'status' => CustomerPortalMembership::STATUS_ACTIVE,
                    'created_by' => $invitation->created_by,
                ]);
            }

            $invitation->forceFill([
                'user_id' => $user->id,
                'accepted_at' => now(),
            ])->save();

            $this->audit->handle('portal_invitation_accepted', $account, $user, $invitation->contact, $invitation->client, $invitation->site, [
                'invitation_id' => $invitation->id,
                'membership_id' => $membership->id,
                'role' => $membership->role,
            ]);

            return [
                'user' => $user,
                'account' => $account,
                'membership' => $membership,
            ];
        });
    }
}
