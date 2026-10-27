<?php

namespace App\Modules\Marketing\Support;

use App\Models\Core\User;
use Laravel\Sanctum\PersonalAccessToken;

/** Web/scheduler jobs retain their existing boundary; API jobs recheck their origin. */
class MarketingQueuedAuthority
{
    public static function allowed(?array $origin): bool
    {
        if ($origin === null) {
            return true;
        }
        if (! config('marketing.api_provider_execution_enabled', false)) {
            return false;
        }
        $actor = User::find($origin['actor_id'] ?? null);
        $token = PersonalAccessToken::find($origin['token_id'] ?? null);
        if (! $actor || ! $token || $token->tokenable_type !== $actor->getMorphClass()
            || (int) $token->tokenable_id !== (int) $actor->id || ($token->expires_at && $token->expires_at->isPast())
            || (config('sanctum.expiration') && $token->created_at->lte(now()->subMinutes(config('sanctum.expiration'))))) {
            return false;
        }
        foreach (['marketing.campaigns.send', 'marketing.providers.execute'] as $scope) {
            if (! $token->can($scope)) {
                return false;
            }
        }
        try {
            MarketingApiAccess::authorize($actor, ['marketing.campaign.send']);
        } catch (\Throwable) {
            return false;
        }

        return true;
    }
}
