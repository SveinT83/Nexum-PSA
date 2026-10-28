<?php

namespace App\Modules\Notification\Actions;

use App\Models\Core\User;
use App\Modules\Notification\Services\WebPushManagedRuntime;
use App\Modules\Notification\Services\WebPushRegistrationMutex;
use App\Modules\Notification\Support\WebPushIdentity;
use App\Modules\Notification\Support\WebPushReadiness;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

/** The existing browser and official API controller share this exact registration/rotation race boundary. */
final class GuardWebPushRegistration
{
    public function __construct(private readonly WebPushRegistrationMutex $mutex, private readonly WebPushManagedRuntime $runtime) {}

    public function run(Request $request, \Closure $persist): mixed
    {
        $work = function () use ($request, $persist) {
            $old = $request->user();
            $user = $old ? User::whereKey($old->id)->lockForUpdate()->first() : null;
            abort_unless($user && $user->isActive() && ! $user->isSystemActor(), 403, 'An active device owner is required.');
            $token = $old->currentAccessToken();
            if ($token instanceof PersonalAccessToken) {
                $token = PersonalAccessToken::whereKey($token->getKey())->lockForUpdate()->first();
                abort_unless($token && (string) $token->tokenable_id === (string) $user->id
                    && $token->tokenable_type === $user->getMorphClass()
                    && (! $token->expires_at || $token->expires_at->isFuture()), 401);
            }
            $user->withAccessToken($token);
            if ($request->is('api/*')) {
                $ability = $request->route()?->defaults['notification_ability'] ?? 'notifications.devices.write';
                abort_unless($token instanceof PersonalAccessToken && $token->can($ability), 403);
            }
            Auth::setUser($user);
            $request->setUserResolver(fn () => $user);
            $identity = $this->runtime->effective();
            if (! app(WebPushReadiness::class)->isReady()) {
                return response()->json(['message' => 'Web Push is not ready in this environment.',
                    'readiness' => app(WebPushReadiness::class)->toArray()], 503);
            }
            if ($identity['managed']) {
                abort_unless($this->mutex->installed() && $this->mutex->sameDatabase(), 503, 'Managed device registration is unavailable.');
                abort_unless(WebPushIdentity::matchesPublicKey($request->input('application_server_key'), $identity['public_key']),
                    409, 'Register this browser again with the current Web Push identity.');
            }

            return $persist();
        };
        if ($this->mutex->installed() && $this->mutex->sameDatabase()) {
            return $this->mutex->run($work);
        }
        // Existing environment-only installations keep their prior path until the additive deployment step.
        abort_unless(! $this->runtime->effective()['managed'], 503, 'Managed device registration requires the additive database update.');

        return $work();
    }
}
