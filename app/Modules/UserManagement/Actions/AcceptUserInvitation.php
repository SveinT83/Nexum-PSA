<?php

namespace App\Modules\UserManagement\Actions;

use App\Models\Core\User;
use App\Modules\UserManagement\Models\InviteToken;
use App\Modules\UserManagement\Security\Contracts\AccountSecurityMutationBoundary;
use App\Modules\UserManagement\Security\Support\UserSecurityAuthCutover;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/** One-use capability is locked with the target; a disabled account cannot be reactivated. */
class AcceptUserInvitation
{
    public function inspect(string $token): InviteToken
    {
        $invite = InviteToken::where('token', $token)->first();
        abort_unless($invite && $invite->isValid() && $invite->user?->isPending()
            && ! $invite->user->isSystemActor(), 404, 'Invitation unavailable.');

        return $invite;
    }

    public function handle(string $token, #[\SensitiveParameter] string $password): User
    {
        try {
            return DB::transaction(function () use ($token, &$password): User {
                $candidate = $this->inspect($token);
                $user = User::query()->lockForUpdate()->findOrFail($candidate->user_id);
                $invite = InviteToken::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
                abort_unless($invite->isValid() && $user->isPending() && ! $user->isSystemActor(), 404, 'Invitation unavailable.');
                if (app()->bound(AccountSecurityMutationBoundary::class)) {
                    // Dev's independently owned protected adapter remains the only protected writer.
                    return app(AccountSecurityMutationBoundary::class)->acceptInvitation($invite, $password);
                }
                // Main's existing non-cutover installation has no protected adapter binding.
                // Explicitly refuse a protected configuration; never fall back after adapter failure.
                abort_if(app(UserSecurityAuthCutover::class)->isEnforced(), 403, 'protected_mutation_denied');
                $user->forceFill(['password' => Hash::make($password), 'status' => User::STATUS_ACTIVE,
                    'email_verified_at' => now()])->save();
                $invite->markUsed();

                return $user->fresh();
            });
        } finally {
            sodium_memzero($password);
        }
    }
}
