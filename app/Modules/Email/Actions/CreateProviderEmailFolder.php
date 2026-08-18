<?php

namespace App\Modules\Email\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Services\EmailFolderProjector;
use App\Modules\Email\Services\EmailAccountProviderRuntimeResolver;
use App\Modules\Email\Services\ImapClient;
use App\Modules\Email\Services\MailboxAccess;
use App\Modules\Email\Support\EmailAccountProviderLock;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class CreateProviderEmailFolder
{
    public function __construct(
        private readonly MailboxAccess $mailboxAccess,
        private readonly EmailFolderProjector $folderProjector,
    ) {}

    public function handle(EmailAccount $account, User $actor, string $folderName): EmailFolder
    {
        if (! $this->mailboxAccess->canAccessAccount($actor, $account, MailboxAccess::ORGANIZE)) {
            throw new AuthorizationException('You need mailbox Organize access before creating provider folders.');
        }

        $folderPath = $this->normalizeFolderPath($folderName);

        $existingFolder = EmailFolder::query()
            ->where('account_id', $account->id)
            ->where('path', $folderPath)
            ->where('sync_enabled', true)
            ->first();

        if ($existingFolder?->is_selectable) {
            throw ValidationException::withMessages([
                'newFolderName' => 'This provider folder already exists in Nexum.',
            ]);
        }

        $providerLock = EmailAccountProviderLock::acquire((int) $account->id, 180);
        if (! $providerLock) {
            throw ValidationException::withMessages([
                'newFolderName' => 'Another provider mailbox operation is active. Try again after it finishes.',
            ]);
        }

        $expectedBindingVersion = app(EmailAccountProviderRuntimeResolver::class)
            ->captureBindingVersion($account);
        $client = app()->makeWith(ImapClient::class, [
            'account' => $account,
            'expectedProviderBindingVersion' => $expectedBindingVersion,
        ]);
        $mutationStarted = false;

        try {
            $client->connect();
            $mutationStarted = true;
            $folderData = $client->createFolder($folderPath);

            $folder = $this->folderProjector->upsertFolder($account, $folderData + [
                'path' => $folderPath,
                'name' => basename(str_replace('\\', '/', $folderPath)) ?: $folderPath,
                'sync_status' => EmailFolder::SYNC_SYNCED,
                'last_synced_at' => now(),
            ]);
        } catch (Throwable) {
            throw new RuntimeException($mutationStarted
                ? 'The provider folder outcome could not be confirmed.'
                : 'The provider folder could not be created.');
        } finally {
            try {
                $client->disconnect();
            } catch (Throwable) {
                // Cleanup cannot replace the sanitized provider outcome above.
            }

            $providerLock->release();
        }

        if (! $folder) {
            throw new RuntimeException('The provider folder was created, but Nexum could not project it locally.');
        }

        return $folder;
    }

    private function normalizeFolderPath(string $value): string
    {
        $folderPath = trim(preg_replace('/[\r\n\t]+/', ' ', $value) ?? '');
        $folderPath = preg_replace('#/+#', '/', $folderPath) ?? '';
        $folderPath = trim($folderPath, "/ \t\n\r\0\x0B");

        if ($folderPath === '') {
            throw ValidationException::withMessages([
                'newFolderName' => 'Enter a provider folder name.',
            ]);
        }

        if (mb_strlen($folderPath) > 180) {
            throw ValidationException::withMessages([
                'newFolderName' => 'Provider folder names must be 180 characters or shorter.',
            ]);
        }

        if (str_contains($folderPath, '\\') || str_contains($folderPath, '..')) {
            throw ValidationException::withMessages([
                'newFolderName' => 'Use a simple provider folder path without backslashes or parent-directory segments.',
            ]);
        }

        $reserved = ['inbox', 'sent', 'drafts', 'trash', 'deleted', 'archive', 'junk', 'spam'];

        if (in_array(mb_strtolower($folderPath), $reserved, true)) {
            throw ValidationException::withMessages([
                'newFolderName' => 'Create custom provider folders only. Existing system folders come from IMAP discovery.',
            ]);
        }

        return Str::of($folderPath)
            ->replaceMatches('/[[:cntrl:]]+/', '')
            ->trim()
            ->toString();
    }
}
