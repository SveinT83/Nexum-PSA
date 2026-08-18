<?php

namespace App\Modules\Email\Services;

use App\Modules\Email\Models\EmailAccount;
use App\Modules\Integration\Exceptions\EmailProviderSecurityException;

final class EmailProviderBindingSnapshot
{
    public function __construct(
        private readonly DefaultEmailAccountResolver $defaults,
        private readonly EmailAccountProviderRuntimeResolver $runtime,
    ) {}

    /** @return array{account_id: int|null, provider_binding_version: int|null} */
    public function captureScope(string $scope): array
    {
        return $this->captureAccount($this->defaults->forScope($scope));
    }

    /** @return array{account_id: int|null, provider_binding_version: int|null} */
    public function captureAccount(#[\SensitiveParameter] ?EmailAccount $account): array
    {
        if (! $account) {
            return ['account_id' => null, 'provider_binding_version' => null];
        }

        return [
            'account_id' => (int) $account->id,
            'provider_binding_version' => $this->runtime->captureBindingVersion($account),
        ];
    }

    public function resolveScope(
        string $scope,
        ?int $expectedAccountId,
        ?int $expectedBindingVersion,
    ): EmailAccount {
        $account = $this->defaults->forScope($scope);

        if (! $account || (int) $account->id !== (int) $expectedAccountId) {
            throw new EmailProviderSecurityException('provider_account_selection_stale');
        }

        return $this->resolveAccount($account, $expectedAccountId, $expectedBindingVersion);
    }

    public function resolveAccount(
        #[\SensitiveParameter] ?EmailAccount $account,
        ?int $expectedAccountId,
        ?int $expectedBindingVersion,
    ): EmailAccount {
        if (! $account
            || ! $expectedAccountId
            || ! $expectedBindingVersion
            || $expectedBindingVersion < 1
            || (int) $account->id !== $expectedAccountId) {
            throw new EmailProviderSecurityException('provider_binding_snapshot_missing');
        }

        $account = EmailAccount::query()->find($expectedAccountId);
        if (! $account
            || ! $this->runtime->databaseReady($account)
            || $this->runtime->bindingVersion($account) !== $expectedBindingVersion) {
            throw new EmailProviderSecurityException('provider_binding_stale');
        }

        return $account;
    }
}
