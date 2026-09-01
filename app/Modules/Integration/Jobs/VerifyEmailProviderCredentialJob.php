<?php

namespace App\Modules\Integration\Jobs;

use App\Models\Core\User;
use App\Modules\Integration\Actions\VerifyEmailProviderCredential;
use App\Modules\Integration\Exceptions\EmailProviderSecurityException;
use App\Modules\Integration\Models\EmailProviderConnection;
use App\Modules\Integration\Models\EmailProviderCredentialVersion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Runs explicit provider verification in the CLI Email worker, where the
 * hard signal deadline is available. Only opaque identifiers are serialized.
 */
final class VerifyEmailProviderCredentialJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 1;

    public int $uniqueFor = 180;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly int $actorId,
        public readonly string $providerIntegrationId,
        public readonly int $credentialVersion,
    ) {
        $this->onQueue('email');
    }

    public function uniqueId(): string
    {
        return $this->providerIntegrationId.':'.$this->credentialVersion;
    }

    public function handle(VerifyEmailProviderCredential $verify): void
    {
        $actor = User::query()->findOrFail($this->actorId);
        $connection = EmailProviderConnection::query()->findOrFail($this->providerIntegrationId);
        $version = EmailProviderCredentialVersion::query()
            ->where('provider_integration_id', $connection->getKey())
            ->where('version', $this->credentialVersion)
            ->firstOrFail();

        try {
            $verify->execute($actor, $connection, $version);
        } catch (EmailProviderSecurityException) {
            // The action persists a safe failure code and clears its claim.
        }
    }

    public function failed(?Throwable $exception): void
    {
        DB::transaction(function (): void {
            $connection = EmailProviderConnection::query()
                ->lockForUpdate()
                ->find($this->providerIntegrationId);
            $version = EmailProviderCredentialVersion::query()
                ->where('provider_integration_id', $this->providerIntegrationId)
                ->where('version', $this->credentialVersion)
                ->lockForUpdate()
                ->first();

            if (! $connection || ! $version || $version->state !== EmailProviderCredentialVersion::STATE_STAGED) {
                return;
            }
            if ($connection->verification_claim_token
                && $connection->verification_claim_expires_at?->isFuture()) {
                // Never clear a live claim that may belong to a concurrent
                // inline or replacement attempt. Its bounded lease owns recovery.
                return;
            }

            $connection->forceFill([
                'last_verification_code' => 'provider_verification_failed',
            ])->save();
            $version->forceFill(['verification_code' => 'provider_verification_failed'])->save();
        });
    }
}
