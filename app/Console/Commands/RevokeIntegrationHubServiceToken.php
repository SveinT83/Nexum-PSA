<?php

namespace App\Console\Commands;

use App\Models\Core\User;
use App\Modules\Integration\Models\AiWorkloadTokenBinding;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

class RevokeIntegrationHubServiceToken extends Command
{
    protected $signature = 'integration-hub:revoke-service-token
        {token : Personal access token record ID}
        {--reason=operator_rotation : Sanitized operator reason code}';

    protected $description = 'Revoke one Integration Hub service token binding without displaying token material.';

    public function handle(): int
    {
        $tokenId = (int) $this->argument('token');
        $reason = trim((string) $this->option('reason'));
        if ($tokenId < 1 || ! preg_match('/^[a-z0-9_:-]{1,120}$/', $reason)) {
            $this->error('Token ID or reason code is invalid.');

            return self::FAILURE;
        }

        $token = PersonalAccessToken::query()->with('tokenable')->find($tokenId);
        $actor = $token?->tokenable;
        $binding = AiWorkloadTokenBinding::query()->where('personal_access_token_id', $tokenId)->first();
        if (! $token || ! $binding || ! $actor instanceof User
            || ! $actor->isSystemActor()
            || $actor->system_actor_key !== (string) config('integration-hub.service_actor_key')
            || ! in_array('integration-hub.service', array_map('strval', $token->abilities ?? []), true)) {
            $this->error('The token is not an Integration Hub service token in this installation.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($binding, $token, $reason): void {
            $locked = AiWorkloadTokenBinding::query()->whereKey($binding->id)->lockForUpdate()->firstOrFail();
            $locked->forceFill([
                'revoked_at' => $locked->revoked_at ?? now(),
            ])->save();
            $token->forceFill(['name' => mb_substr($token->name.' [revoked:'.$reason.']', 0, 255)])->save();
        });

        $this->info('Integration Hub service token '.$tokenId.' is revoked.');

        return self::SUCCESS;
    }
}
