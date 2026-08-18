<?php

namespace App\Modules\Notification\Support;

use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Services\EmailProviderBindingSnapshot;
use App\Modules\Integration\Exceptions\EmailProviderSecurityException;
use App\Modules\Notification\Channels\EmailAccountMailChannel;

/**
 * Freezes the selected Email account and binding while Laravel evaluates via().
 *
 * Laravel evaluates via() before cloning a queued notification into its
 * durable channel job. Keeping only safe IDs, a scope and a binding generation
 * in these public properties therefore prevents a later worker from silently
 * adopting a rotated, rebound, revoked or newly selected provider.
 */
trait RoutesEmailThroughAccount
{
    public bool $emailAccountMailSnapshotCaptured = false;

    public ?string $emailAccountMailScope = null;

    public ?int $emailAccountMailAccountId = null;

    public ?int $emailAccountMailProviderBindingVersion = null;

    public ?string $emailAccountMailSnapshotFailureCode = null;

    protected function freezeEmailAccountMailSnapshot(string $scope): void
    {
        if (! $this->emailAccountMailSnapshotCaptured) {
            $this->emailAccountMailSnapshotCaptured = true;

            if (! array_key_exists($scope, EmailAccount::DEFAULT_SCOPES)) {
                $this->emailAccountMailSnapshotFailureCode = 'provider_notification_scope_invalid';

                return;
            }

            $this->emailAccountMailScope = $scope;

            try {
                $snapshot = app(EmailProviderBindingSnapshot::class)->captureScope($scope);
                $this->emailAccountMailAccountId = $snapshot['account_id'];
                $this->emailAccountMailProviderBindingVersion = $snapshot['provider_binding_version'];

                if (! $this->emailAccountMailAccountId || ! $this->emailAccountMailProviderBindingVersion) {
                    $this->emailAccountMailSnapshotFailureCode = 'provider_binding_snapshot_missing';
                }
            } catch (EmailProviderSecurityException $exception) {
                $this->emailAccountMailSnapshotFailureCode = $exception->reasonCode;
            } catch (\Throwable) {
                // via() may also select database, Web Push and Talk. Preserve
                // those channels while the Email channel records a safe block.
                $this->emailAccountMailSnapshotFailureCode = 'provider_binding_snapshot_unavailable';
            }
        }
    }

    protected function emailAccountMailChannel(string $scope): string
    {
        $this->freezeEmailAccountMailSnapshot($scope);

        return EmailAccountMailChannel::class;
    }

    /**
     * @return array{
     *     captured: bool,
     *     scope: string|null,
     *     account_id: int|null,
     *     provider_binding_version: int|null,
     *     failure_code: string|null
     * }
     */
    public function emailAccountMailSnapshot(): array
    {
        return [
            'captured' => $this->emailAccountMailSnapshotCaptured,
            'scope' => $this->emailAccountMailScope,
            'account_id' => $this->emailAccountMailAccountId,
            'provider_binding_version' => $this->emailAccountMailProviderBindingVersion,
            'failure_code' => $this->emailAccountMailSnapshotFailureCode,
        ];
    }
}
