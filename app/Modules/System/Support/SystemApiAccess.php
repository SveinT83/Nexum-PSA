<?php

namespace App\Modules\System\Support;

use App\Http\Middleware\EnforceTechRoutePermission;
use App\Models\Core\User;
use App\Modules\System\Models\SystemApiCommand;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\TransientToken;

/** A token narrows the current internal actor; it cannot create native authority. */
final class SystemApiAccess
{
    public static function actor(Request $request): User
    {
        $old = $request->user();
        $actor = $old instanceof User ? User::find($old->id) : null;
        abort_unless($actor && $actor->isActive() && ! $actor->isSystemActor()
            && ($actor->roles()->exists() || $actor->permissions()->exists()), 403, 'An active internal user is required.');
        $token = $old->currentAccessToken();
        if ($token instanceof PersonalAccessToken && $token->getKey()) {
            $token = PersonalAccessToken::find($token->getKey());
            abort_unless($token && $token->tokenable_type === User::class && (int) $token->tokenable_id === $actor->id
                && (! $token->expires_at || $token->expires_at->isFuture()), 401, 'Token unavailable.');
        }
        $actor->withAccessToken($token);
        $request->setUserResolver(fn () => $actor);
        Auth::setUser($actor);

        return $actor;
    }

    public static function allows(User $actor, string $permission): bool
    {
        return app(EnforceTechRoutePermission::class)->allowsActor($actor, $permission);
    }

    public static function tokenHash(Request $request): string
    {
        $token = $request->user()->currentAccessToken();
        if ($token instanceof PersonalAccessToken && $token->getKey()) {
            return hash('sha256', 'token:'.$token->getKey());
        }
        abort_unless($token instanceof TransientToken && $request->hasSession(), 401, 'A bearer token or authenticated session is required.');

        return hash_hmac('sha256', 'session:'.$request->session()->getId(), config('app.key'));
    }

    public static function receipt(Request $request, SystemApiCommand $command): void
    {
        abort_unless((int) $command->actor_id === $request->user()->id
            && hash_equals($command->token_hash, self::tokenHash($request)), 404, 'Operation unavailable.');
        abort_unless(self::allows($request->user(), $command->native_permission)
            && $request->user()->tokenCan($command->scope), 403, 'Original operation authority was revoked.');
    }

    public function handle(Request $request, Closure $next)
    {
        try {
            $actor = self::actor($request);
            $permission = $request->route()->defaults['system_permission'] ?? null;
            abort_if($permission && ! self::allows($actor, $permission), 403, 'Missing System permission.');
            abort_unless($actor->tokenCan($request->route()->defaults['system_scope']), 403, 'Missing System token scope.');
            SystemApiInput::allowed($request, $request->route()->defaults['system_fields'] ?? []);

            return $next($request);
        } catch (\Illuminate\Validation\ValidationException $error) {
            return response()->json(['message' => 'Invalid System API input.', 'errors' => $error->errors()], 422);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $error) {
            return response()->json(['message' => $error->getStatusCode() === 404 ? 'System target unavailable.' : $error->getMessage()], $error->getStatusCode());
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json(['message' => 'System target unavailable.'], 404);
        } catch (\Throwable) {
            return response()->json(['message' => 'System storage or backend is unavailable.'], 503);
        }
    }
}
