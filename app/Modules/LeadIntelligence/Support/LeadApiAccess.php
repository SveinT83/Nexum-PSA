<?php

namespace App\Modules\LeadIntelligence\Support;

use App\Http\Middleware\EnforceTechRoutePermission;
use App\Models\Core\User;
use App\Modules\LeadIntelligence\Models\LeadApiCommand;
use App\Modules\LeadIntelligence\Models\LeadResearchRun;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

/** Native Lead Intelligence rights and token authority are independent. */
class LeadApiAccess
{
    public static function authorize(User $actor): void
    {
        abort_unless($actor->isActive() && ! $actor->isSystemActor()
            && ($actor->roles()->exists() || $actor->permissions()->exists())
            && app(EnforceTechRoutePermission::class)->allowsActor($actor, 'sales.lead_manage'),
            403, 'An active internal actor with sales.lead_manage is required.');
    }

    public static function tokenHash(Request $request): string
    {
        $token = $request->user()->currentAccessToken();
        if ($token && method_exists($token, 'getKey') && $token->getKey()) {
            return hash('sha256', (string) $token->getKey());
        }
        if (app()->runningUnitTests()) {
            return hash('sha256', 'test:'.$request->user()->id);
        }
        abort_unless($token instanceof \Laravel\Sanctum\TransientToken && $request->hasSession(), 401);

        return hash('sha256', 'session:'.$request->session()->getId());
    }

    /** Recheck the persisted API origin before worker/provider effects, never a caller summary. */
    public static function forRun(LeadResearchRun $run): void
    {
        if (! $run->api_command_id) {
            return; // Native scheduled/manual runs keep their existing execution policy.
        }
        $command = LeadApiCommand::findOrFail($run->api_command_id);
        $actor = User::find($command->actor_id);
        abort_unless($actor, 403);
        self::authorize($actor);
        abort_unless(config('lead-intelligence.api_external_execution', false), 409, 'API external execution is disabled.');
        $tokenId = $command->result['token_id'] ?? null;
        abort_unless($tokenId || app()->runningUnitTests(), 403, 'API queued research requires a bearer token.');
        if ($tokenId) {
            $token = PersonalAccessToken::find($tokenId);
            abort_unless($token && $token->tokenable_id === $actor->id && $token->can('lead-intelligence.run')
                && (! $token->expires_at || $token->expires_at->isFuture())
                && (! config('sanctum.expiration') || $token->created_at->gt(now()->subMinutes(config('sanctum.expiration')))), 403,
                'The initiating API token is no longer authorized.');
            $actor->withAccessToken($token);
        }
        Auth::setUser($actor);
    }

    public function handle(Request $request, Closure $next)
    {
        $previous = $request->user();
        $actor = $previous ? User::find($previous->id) : null;
        abort_unless($actor, 403);
        self::authorize($actor);
        $token = $previous->currentAccessToken();
        if ($token && method_exists($token, 'getKey') && $token->getKey()) {
            $token = PersonalAccessToken::find($token->getKey());
            abort_unless($token && $token->tokenable_id === $actor->id
                && (! $token->expires_at || $token->expires_at->isFuture())
                && (! config('sanctum.expiration') || $token->created_at->gt(now()->subMinutes(config('sanctum.expiration')))), 403,
                'The initiating token is no longer authorized.');
        }
        $actor->withAccessToken($token);
        Auth::setUser($actor);
        $request->setUserResolver(fn () => $actor);
        $ability = $request->route()->defaults['lead_ability'] ?? null;
        abort_if($ability && ! $actor->tokenCan($ability), 403, 'Missing Lead Intelligence token ability.');

        return $next($request);
    }
}
