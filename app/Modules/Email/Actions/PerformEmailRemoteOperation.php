<?php

namespace App\Modules\Email\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailRemoteOperation;
use App\Modules\Email\Services\MailboxAccess;
use App\Modules\Email\Support\EmailProviderPath;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class PerformEmailRemoteOperation
{
    public const MARK_SEEN = 'mark_seen';

    public const MARK_UNSEEN = 'mark_unseen';

    public const FLAG = 'flag';

    public const UNFLAG = 'unflag';

    public const ARCHIVE = 'archive';

    public const TRASH = 'trash';

    public const MOVE = 'move';

    /**
     * @return array<int, string>
     */
    public static function allowedOperations(): array
    {
        return [
            self::MARK_SEEN,
            self::MARK_UNSEEN,
            self::FLAG,
            self::UNFLAG,
            self::ARCHIVE,
            self::TRASH,
            self::MOVE,
        ];
    }

    public function __construct(
        private readonly MailboxAccess $mailboxAccess,
        private readonly RecordEmailRemoteOperation $recordRemoteOperation,
        private readonly RunEmailRemoteOperation $runRemoteOperation,
    ) {}

    /**
     * Submit and execute a provider-authoritative mailbox operation.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(
        EmailMailboxPlacement $placement,
        string $operation,
        User $actor,
        ?EmailFolder $requestedTargetFolder = null,
    ): EmailRemoteOperation {
        $placement->loadMissing(['account', 'folder', 'message.account']);

        if (! in_array($operation, self::allowedOperations(), true)) {
            throw new InvalidArgumentException('Unsupported email remote operation.');
        }

        if (! $placement->account || ! $this->mailboxAccess->canAccessAccount($actor, $placement->account, MailboxAccess::ORGANIZE)) {
            throw new AuthorizationException('You cannot organize this mailbox placement.');
        }

        if ($placement->local_state !== EmailMailboxPlacement::LOCAL_ACTIVE) {
            throw ValidationException::withMessages([
                'placement' => 'This mailbox placement is no longer active.',
            ]);
        }

        if ($placement->provider_missing_at !== null) {
            throw ValidationException::withMessages([
                'placement' => 'This mailbox placement is no longer available at the provider.',
            ]);
        }

        $targetFolder = $this->targetFolderFor($placement, $operation, $requestedTargetFolder);
        $storedSourcePath = $placement->getAttribute('folder_path');
        if (! is_string($storedSourcePath) || $storedSourcePath === '') {
            $storedSourcePath = $placement->folder?->getAttribute('path');
        }
        try {
            $sourceFolderPath = EmailProviderPath::normalize((string) $storedSourcePath);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'placement' => 'This mailbox placement is missing provider folder or UID evidence.',
            ]);
        }
        if ((int) $placement->imap_uid <= 0) {
            throw ValidationException::withMessages([
                'placement' => 'This mailbox placement is missing provider folder or UID evidence.',
            ]);
        }
        try {
            $targetFolderPath = EmailProviderPath::normalizeNullable(
                $targetFolder?->getAttribute('path'),
            );
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'target_folder' => 'The selected provider folder path is invalid.',
            ]);
        }

        $request = [
            'source_folder_path' => $sourceFolderPath,
            'target_folder_path' => $targetFolderPath,
            'placement_sync_version' => (int) $placement->sync_version,
            'placement_imap_uid' => (int) $placement->imap_uid,
            'placement_uid_validity' => (int) $placement->imap_uid_validity,
            'target_state' => $this->targetState($operation),
        ];

        $remoteOperation = $this->recordRemoteOperation->pending(
            $placement->account,
            $operation,
            $this->idempotencyKey($placement, $operation, $request),
            $actor,
            $placement->folder,
            $placement,
            $request,
        );

        return $this->runRemoteOperation->handle($remoteOperation->fresh(['account', 'folder', 'placement.message']));
    }

    /**
     * @throws ValidationException
     */
    public function targetFolderFor(
        EmailMailboxPlacement $placement,
        string $operation,
        ?EmailFolder $requestedTargetFolder = null,
    ): ?EmailFolder {
        if ($operation === self::MOVE) {
            if (! $requestedTargetFolder) {
                throw ValidationException::withMessages([
                    'target_folder' => 'Choose a provider folder before moving this message.',
                ]);
            }

            if ((int) $requestedTargetFolder->account_id !== (int) $placement->account_id
                || ! $requestedTargetFolder->is_selectable
                || ! $requestedTargetFolder->sync_enabled) {
                throw ValidationException::withMessages([
                    'target_folder' => 'The selected target folder is not available for this mailbox.',
                ]);
            }

            if ((int) $requestedTargetFolder->id === (int) $placement->email_folder_id) {
                throw ValidationException::withMessages([
                    'target_folder' => 'Choose a different folder before moving this message.',
                ]);
            }

            return $requestedTargetFolder;
        }

        if ($operation === self::ARCHIVE && $requestedTargetFolder) {
            if ((int) $requestedTargetFolder->account_id !== (int) $placement->account_id
                || ! $this->folderHasRole($requestedTargetFolder, EmailFolder::ROLE_ARCHIVE)
                || ! $requestedTargetFolder->is_selectable
                || ! $requestedTargetFolder->sync_enabled
                || (int) $requestedTargetFolder->id === (int) $placement->email_folder_id) {
                throw ValidationException::withMessages([
                    'target_folder' => 'The selected Archive folder is not available for this mailbox.',
                ]);
            }

            return $requestedTargetFolder;
        }

        $role = match ($operation) {
            self::ARCHIVE => EmailFolder::ROLE_ARCHIVE,
            self::TRASH => EmailFolder::ROLE_TRASH,
            default => null,
        };

        if (! $role) {
            return null;
        }

        $folder = EmailFolder::query()
            ->where('account_id', $placement->account_id)
            ->where('is_selectable', true)
            ->where('sync_enabled', true)
            ->whereKeyNot($placement->email_folder_id)
            ->get()
            ->filter(fn (EmailFolder $candidate): bool => $this->folderHasRole($candidate, $role))
            ->sort(fn (EmailFolder $first, EmailFolder $second): int => $this->targetFolderRank($first, $role)
                <=> $this->targetFolderRank($second, $role))
            ->first();

        if (! $folder) {
            throw ValidationException::withMessages([
                'target_folder' => "This account does not have a selectable {$role} folder discovered from the provider.",
            ]);
        }

        return $folder;
    }

    private function folderHasRole(EmailFolder $folder, string $role): bool
    {
        return EmailFolder::inferRole(
            (string) $folder->path,
            $folder->special_use,
            $folder->delimiter,
        ) === $role;
    }

    /** @return array{int, int, string, int} */
    private function targetFolderRank(EmailFolder $folder, string $role): array
    {
        $explicitSpecialUse = filled($folder->special_use)
            && EmailFolder::inferRole('', $folder->special_use, $folder->delimiter) === $role;
        $delimiter = filled($folder->delimiter) ? (string) $folder->delimiter : null;
        $depth = $delimiter
            ? substr_count((string) $folder->path, $delimiter)
            : preg_match_all('/[.\\\\\/]/u', (string) $folder->path);

        return [
            $explicitSpecialUse ? 0 : 1,
            max(0, (int) $depth),
            mb_strtolower(trim((string) $folder->path)),
            (int) $folder->id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function targetState(string $operation): array
    {
        return match ($operation) {
            self::MARK_SEEN => ['provider_seen' => true],
            self::MARK_UNSEEN => ['provider_seen' => false],
            self::FLAG => ['provider_flagged' => true],
            self::UNFLAG => ['provider_flagged' => false],
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $request
     */
    private function idempotencyKey(EmailMailboxPlacement $placement, string $operation, array $request): string
    {
        return 'mail-op:'.Str::limit(
            $operation.':'.$placement->id.':'.sha1(json_encode($request, JSON_THROW_ON_ERROR)),
            148,
            '',
        );
    }
}
