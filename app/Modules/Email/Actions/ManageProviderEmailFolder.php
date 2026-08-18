<?php

namespace App\Modules\Email\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailRemoteOperation;
use App\Modules\Email\Models\EmailRule;
use App\Modules\Email\Services\MailboxAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ManageProviderEmailFolder
{
    public const RENAME_FOLDER = 'folder_rename';

    public const MOVE_FOLDER = 'folder_move';

    public const DELETE_FOLDER = 'folder_delete';

    public function __construct(
        private readonly MailboxAccess $mailboxAccess,
        private readonly RecordEmailRemoteOperation $recordRemoteOperation,
        private readonly RunEmailRemoteOperation $runRemoteOperation,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function rename(EmailFolder $folder, User $actor, string $newName): EmailRemoteOperation
    {
        $folder->loadMissing('account');
        $this->authorize($folder, $actor);
        $this->assertFolderSafeForMutation($folder);

        $targetPath = $this->targetPathForRename($folder, $newName);
        $this->assertTargetPathAvailable($folder, $targetPath, 'folderRenameName');

        $operation = $this->recordRemoteOperation->pending(
            $folder->account,
            self::RENAME_FOLDER,
            $this->idempotencyKey($folder, self::RENAME_FOLDER, $targetPath),
            $actor,
            $folder,
            null,
            [
                'source_folder_path' => $folder->path,
                'target_folder_path' => $targetPath,
                'folder_id' => $folder->id,
            ],
        );

        return $this->runRemoteOperation->handle($operation->fresh(['account', 'folder']));
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function move(EmailFolder $folder, User $actor, ?EmailFolder $targetParent): EmailRemoteOperation
    {
        $folder->loadMissing('account');
        $this->authorize($folder, $actor);
        $this->assertFolderSafeForMutation($folder);

        $targetPath = $this->targetPathForMove($folder, $targetParent);
        $this->assertTargetPathAvailable($folder, $targetPath, 'folderMoveParentFolderId');

        $operation = $this->recordRemoteOperation->pending(
            $folder->account,
            self::MOVE_FOLDER,
            $this->idempotencyKey($folder, self::MOVE_FOLDER, $targetPath),
            $actor,
            $folder,
            null,
            [
                'source_folder_path' => $folder->path,
                'target_folder_path' => $targetPath,
                'folder_id' => $folder->id,
                'target_parent_folder_id' => $targetParent?->id,
            ],
        );

        return $this->runRemoteOperation->handle($operation->fresh(['account', 'folder']));
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function delete(EmailFolder $folder, User $actor): EmailRemoteOperation
    {
        $folder->loadMissing('account');
        $this->authorize($folder, $actor);
        $this->assertFolderSafeForMutation($folder);

        $activePlacements = $this->activePlacementCount($folder);
        if ($activePlacements > 0) {
            $mailLabel = number_format($activePlacements).' '.($activePlacements === 1 ? 'mail' : 'mails');

            throw ValidationException::withMessages([
                'folderDeleteFolderId' => "Move the {$mailLabel} in this folder before deleting it.",
            ]);
        }

        $ruleReferences = $this->ruleReferenceCount($folder);
        if ($ruleReferences > 0) {
            throw ValidationException::withMessages([
                'folderDeleteFolderId' => "This folder is used by {$ruleReferences} rules. Update those rules before deleting it.",
            ]);
        }

        $operation = $this->recordRemoteOperation->pending(
            $folder->account,
            self::DELETE_FOLDER,
            $this->idempotencyKey($folder, self::DELETE_FOLDER, $folder->path),
            $actor,
            $folder,
            null,
            [
                'source_folder_path' => $folder->path,
                'folder_id' => $folder->id,
            ],
        );

        return $this->runRemoteOperation->handle($operation->fresh(['account', 'folder']));
    }

    /**
     * @return array<int, string>
     */
    public function mutationBlockers(EmailFolder $folder, bool $forDelete = false): array
    {
        $blockers = [];

        if ($folder->role !== EmailFolder::ROLE_CUSTOM || filled($folder->special_use)) {
            $blockers[] = 'System folder';
        }

        if (! $folder->is_selectable) {
            $blockers[] = 'Not selectable';
        }

        if (! $folder->sync_enabled) {
            $blockers[] = 'Not active';
        }

        if ($this->childFolderCount($folder) > 0) {
            $blockers[] = 'Has subfolders';
        }

        if ($this->activeOperationCount($folder) > 0) {
            $blockers[] = 'Pending operation';
        }

        if ($forDelete && $this->ruleReferenceCount($folder) > 0) {
            $blockers[] = 'Used by rules';
        }

        return $blockers;
    }

    public function activePlacementCount(EmailFolder $folder): int
    {
        return EmailMailboxPlacement::query()
            ->where('email_folder_id', $folder->id)
            ->where('local_state', EmailMailboxPlacement::LOCAL_ACTIVE)
            ->count();
    }

    public function ruleReferenceCount(EmailFolder $folder): int
    {
        return EmailRule::query()
            ->get(['id', 'actions_json'])
            ->filter(fn (EmailRule $rule): bool => $this->actionsReferenceFolder($rule->actions_json ?? [], (int) $folder->id))
            ->count();
    }

    public function activeOperationCount(EmailFolder $folder): int
    {
        return EmailRemoteOperation::query()
            ->where('account_id', $folder->account_id)
            ->whereIn('status', [
                EmailRemoteOperation::STATUS_PENDING,
                EmailRemoteOperation::STATUS_RUNNING,
                EmailRemoteOperation::STATUS_FAILED,
            ])
            ->where(function (Builder $query) use ($folder): void {
                $query
                    ->where('email_folder_id', $folder->id)
                    ->orWhere('source_folder_path', $folder->path)
                    ->orWhere('target_folder_path', $folder->path);
            })
            ->count();
    }

    public function childFolderCount(EmailFolder $folder): int
    {
        $delimiter = $folder->delimiter ?: (str_contains($folder->path, '/') ? '/' : '/');
        $prefix = $folder->path.$delimiter;

        return EmailFolder::query()
            ->where('account_id', $folder->account_id)
            ->where('sync_enabled', true)
            ->whereKeyNot($folder->id)
            ->where(function (Builder $query) use ($folder, $prefix): void {
                $query
                    ->where('parent_path', $folder->path)
                    ->orWhere('path', 'like', $prefix.'%');
            })
            ->count();
    }

    /**
     * @throws ValidationException
     */
    private function assertFolderSafeForMutation(EmailFolder $folder): void
    {
        $blockers = $this->mutationBlockers($folder);

        if ($blockers === []) {
            return;
        }

        throw ValidationException::withMessages([
            'folder' => 'This provider folder cannot be changed: '.implode(', ', $blockers).'.',
        ]);
    }

    /**
     * @throws AuthorizationException
     */
    private function authorize(EmailFolder $folder, User $actor): void
    {
        if (! $folder->account instanceof EmailAccount
            || ! $this->mailboxAccess->canAccessAccount($actor, $folder->account, MailboxAccess::ORGANIZE)) {
            throw new AuthorizationException('You need mailbox Organize access before managing provider folders.');
        }
    }

    /**
     * @throws ValidationException
     */
    private function targetPathForRename(EmailFolder $folder, string $newName): string
    {
        $name = $this->normalizeSimpleFolderName($newName);
        $delimiter = $folder->delimiter ?: (str_contains($folder->path, '/') ? '/' : '/');
        $targetPath = $folder->parent_path
            ? $folder->parent_path.$delimiter.$name
            : $name;

        if ($targetPath === $folder->path) {
            throw ValidationException::withMessages([
                'folderRenameName' => 'Enter a different folder name.',
            ]);
        }

        if (mb_strlen($targetPath) > 512) {
            throw ValidationException::withMessages([
                'folderRenameName' => 'The renamed provider folder path is too long.',
            ]);
        }

        return $targetPath;
    }

    /**
     * @throws ValidationException
     */
    private function targetPathForMove(EmailFolder $folder, ?EmailFolder $targetParent): string
    {
        if ($targetParent) {
            if ((int) $targetParent->account_id !== (int) $folder->account_id) {
                throw ValidationException::withMessages([
                    'folderMoveParentFolderId' => 'Move folders only inside the same mailbox.',
                ]);
            }

            if ((int) $targetParent->id === (int) $folder->id) {
                throw ValidationException::withMessages([
                    'folderMoveParentFolderId' => 'Choose a different parent folder.',
                ]);
            }

            if ($this->isDescendantPath((string) $targetParent->path, $folder)) {
                throw ValidationException::withMessages([
                    'folderMoveParentFolderId' => 'Move a folder only outside its own subtree.',
                ]);
            }
        }

        $name = $this->simpleFolderName($folder);
        $delimiter = $targetParent?->delimiter ?: $folder->delimiter ?: '/';
        $targetPath = $targetParent
            ? rtrim((string) $targetParent->path, $delimiter).$delimiter.$name
            : $name;

        if ($targetPath === $folder->path) {
            throw ValidationException::withMessages([
                'folderMoveParentFolderId' => 'Choose a different parent folder.',
            ]);
        }

        if (mb_strlen($targetPath) > 512) {
            throw ValidationException::withMessages([
                'folderMoveParentFolderId' => 'The moved provider folder path is too long.',
            ]);
        }

        return $targetPath;
    }

    /**
     * @throws ValidationException
     */
    private function assertTargetPathAvailable(EmailFolder $folder, string $targetPath, string $field): void
    {
        if (EmailFolder::query()
            ->where('account_id', $folder->account_id)
            ->where('path', $targetPath)
            ->where('is_selectable', true)
            ->where('sync_enabled', true)
            ->whereKeyNot($folder->id)
            ->exists()) {
            throw ValidationException::withMessages([
                $field => 'A provider folder with this path already exists in Nexum.',
            ]);
        }
    }

    private function simpleFolderName(EmailFolder $folder): string
    {
        $delimiter = $folder->delimiter ?: (str_contains($folder->path, '/') ? '/' : '/');
        $path = (string) $folder->path;

        if (str_contains($path, $delimiter)) {
            return Str::afterLast($path, $delimiter);
        }

        return (string) ($folder->name ?: $folder->path);
    }

    private function isDescendantPath(string $candidatePath, EmailFolder $folder): bool
    {
        $delimiter = $folder->delimiter ?: (str_contains($folder->path, '/') ? '/' : '/');

        return str_starts_with($candidatePath, $folder->path.$delimiter);
    }

    /**
     * @throws ValidationException
     */
    private function normalizeSimpleFolderName(string $value): string
    {
        $name = trim(preg_replace('/[\r\n\t]+/', ' ', $value) ?? '');
        $name = Str::of($name)
            ->replaceMatches('/[[:cntrl:]]+/', '')
            ->trim()
            ->toString();

        if ($name === '') {
            throw ValidationException::withMessages([
                'folderRenameName' => 'Enter a provider folder name.',
            ]);
        }

        if (mb_strlen($name) > 180) {
            throw ValidationException::withMessages([
                'folderRenameName' => 'Provider folder names must be 180 characters or shorter.',
            ]);
        }

        if (str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, '..')) {
            throw ValidationException::withMessages([
                'folderRenameName' => 'Rename uses a simple folder name, not a path.',
            ]);
        }

        $reserved = ['inbox', 'sent', 'drafts', 'trash', 'deleted', 'archive', 'junk', 'spam'];

        if (in_array(mb_strtolower($name), $reserved, true)) {
            throw ValidationException::withMessages([
                'folderRenameName' => 'System folder names are reserved for provider-discovered folders.',
            ]);
        }

        return $name;
    }

    /**
     * @param  array<int, mixed>|array<string, mixed>  $actions
     */
    private function actionsReferenceFolder(array $actions, int $folderId): bool
    {
        return Collection::make($actions)
            ->contains(function (mixed $value, mixed $key) use ($folderId): bool {
                if ($key === 'target_folder_id' && (int) $value === $folderId) {
                    return true;
                }

                return is_array($value) && $this->actionsReferenceFolder($value, $folderId);
            });
    }

    private function idempotencyKey(EmailFolder $folder, string $operation, string $target): string
    {
        return 'mail-folder-op:'.Str::limit(
            $operation.':'.$folder->id.':'.sha1($folder->path.'|'.$target.'|'.(int) $folder->updated_at?->getTimestamp()),
            143,
            '',
        );
    }
}
