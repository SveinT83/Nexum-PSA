<?php

namespace App\Modules\Integration\Services;

use App\Models\Core\User;
use App\Modules\Integration\Models\EmailProviderConnection;
use Illuminate\Auth\Access\AuthorizationException;

final class EmailProviderManagementAuthorization
{
    public const MANAGE_PERMISSION = 'integration.email_provider_manage';

    public const PRIVATE_ENDPOINT_PERMISSION = 'integration.email_private_endpoint_manage';

    public const MAILBOX_SYNC_PERMISSION = 'email.mailbox_sync_manage';

    public const EMAIL_ACCOUNT_PERMISSION = 'email.account_manage';

    public function authorizeProvider(#[\SensitiveParameter] User $actor, bool $requiresMailboxSync = false): User
    {
        $actor = User::query()->find($actor->id);

        if (! $actor?->isActive()
            || $actor->isSystemActor()
            || ! $actor->can(self::MANAGE_PERMISSION)
            || ($requiresMailboxSync && ! $actor->can(self::MAILBOX_SYNC_PERMISSION))) {
            throw new AuthorizationException('Email provider management is unavailable.');
        }

        return $actor;
    }

    public function authorizeBinding(#[\SensitiveParameter] User $actor): User
    {
        $actor = $this->authorizeProvider($actor, true);

        if (! $actor->can(self::EMAIL_ACCOUNT_PERMISSION)) {
            throw new AuthorizationException('Email provider binding is unavailable.');
        }

        return $actor;
    }

    public function authorizePrivateEndpoint(
        #[\SensitiveParameter] User $actor,
        #[\SensitiveParameter] string $reason,
        #[\SensitiveParameter] string $cidrName,
    ): User {
        $actor = $this->authorizeProvider($actor, true);

        if (! $actor->can(self::PRIVATE_ENDPOINT_PERMISSION)
            || blank(trim($reason))
            || blank(trim($cidrName))) {
            throw new AuthorizationException('Private Email provider endpoint approval is unavailable.');
        }

        return $actor;
    }

    public function authorizeConnectionTrust(
        #[\SensitiveParameter] User $actor,
        #[\SensitiveParameter] EmailProviderConnection $connection,
    ): User {
        if ($connection->trust_mode === 'trusted_private') {
            return $this->authorizePrivateEndpoint(
                $actor,
                (string) $connection->private_endpoint_reason,
                (string) $connection->trusted_cidr_name,
            );
        }

        return $this->authorizeProvider($actor, true);
    }
}
