<?php

namespace App\Modules\Email\Services;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailFolderUidNamespace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

class EmailMailboxMaintenanceAuthorization
{
    public const PERMISSION = 'email.mailbox_sync_manage';

    /**
     * Account administration and provider-cursor authority are intentionally
     * separate permissions. Neither grants mailbox content access.
     *
     * @throws AuthorizationException
     */
    public function authorize(User $actor, EmailAccount $account): void
    {
        if (! $actor->isActive()
            || $actor->isSystemActor()
            || ! $actor->can('email.account_manage')
            || ! $actor->can(self::PERMISSION)
            || ! $this->accountIsSyncEligible($account)) {
            throw new AuthorizationException('Mailbox maintenance is unavailable.');
        }
    }

    /**
     * @throws ValidationException
     */
    public function authorizeFolder(User $actor, EmailAccount $account, EmailFolder $folder): void
    {
        $this->authorize($actor, $account);

        if ((int) $folder->account_id !== (int) $account->id
            || ! $folder->is_selectable
            || ! $folder->sync_enabled) {
            throw ValidationException::withMessages([
                'folders' => 'Choose an enabled, selectable folder from this account.',
            ]);
        }
    }

    public function hasCurrentNamespace(EmailFolder $folder): bool
    {
        if ((int) $folder->uid_validity <= 0 || ! $folder->active_uid_namespace_id) {
            return false;
        }

        return EmailFolderUidNamespace::query()
            ->whereKey($folder->active_uid_namespace_id)
            ->where('account_id', $folder->account_id)
            ->where('email_folder_id', $folder->id)
            ->where('uid_validity', $folder->uid_validity)
            ->where('status', EmailFolderUidNamespace::STATUS_ACTIVE)
            ->exists();
    }

    public function accountIsSyncEligible(EmailAccount $account): bool
    {
        return app(EmailAccountProviderRuntimeResolver::class)->databaseReady($account);
    }
}
