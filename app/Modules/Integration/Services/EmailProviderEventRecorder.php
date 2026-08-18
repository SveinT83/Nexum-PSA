<?php

namespace App\Modules\Integration\Services;

use App\Models\Core\User;
use App\Modules\Integration\Exceptions\EmailProviderSecurityException;
use App\Modules\Integration\Models\EmailProviderConnection;
use App\Modules\Integration\Models\EmailProviderEvent;
use Illuminate\Support\Str;

final class EmailProviderEventRecorder
{
    public function record(
        #[\SensitiveParameter] EmailProviderConnection $connection,
        #[\SensitiveParameter] ?User $actor,
        string $eventType,
        ?string $reasonCode = null,
        ?int $credentialVersion = null,
        #[\SensitiveParameter] ?string $operationKey = null,
    ): EmailProviderEvent {
        if (preg_match('/^[a-z0-9_.-]{1,64}$/', $eventType) !== 1
            || ($reasonCode !== null && preg_match('/^[a-z0-9_.-]{1,80}$/', $reasonCode) !== 1)) {
            throw new EmailProviderSecurityException('event_metadata_invalid');
        }

        return EmailProviderEvent::query()->create([
            'event_key' => (string) Str::uuid(),
            'provider_integration_id' => $connection->getKey(),
            'actor_id' => $actor?->id,
            'event_type' => $eventType,
            'reason_code' => $reasonCode,
            'configuration_version' => $connection->configuration_version,
            'credential_version' => $credentialVersion,
            'operation_fingerprint' => filled($operationKey)
                ? hash_hmac('sha256', $operationKey, (string) config('app.key'))
                : null,
            'occurred_at' => now(),
        ]);
    }
}
