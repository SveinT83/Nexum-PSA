<?php

namespace App\Modules\Email\Livewire\Tech;

use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailFolderNavigationPreference;
use App\Modules\Email\Services\MailboxAccess;
use App\Modules\Email\Services\ResolveMailboxAccessDecision;
use App\Modules\Email\Support\EmailProviderPath;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Livewire\Attributes\On;

class MailSidebar extends MailWorkspace
{
    #[On('mail-state-changed')]
    public function refreshAfterMailStateChanged(): void
    {
        // Receiving the event is enough for Livewire to re-render sidebar counts.
    }

    public function selectFolder(mixed $folderId): void
    {
        if (! is_numeric($folderId) || (int) $folderId < 1) {
            return;
        }

        $visibleFolders = $this->accessibleNavigationFolders();
        $folder = $visibleFolders->firstWhere('id', (int) $folderId);
        if (! $folder instanceof EmailFolder || ! $folder->is_selectable) {
            return;
        }

        parent::selectFolder($folder->id);

        $foldersByPath = $visibleFolders
            ->where('account_id', $folder->account_id)
            ->keyBy(fn (EmailFolder $item): string => (string) $item->path);
        $ancestorIds = collect();
        $this->includeFolderAncestors($folder, $foldersByPath, $ancestorIds);
        $this->persistFolderNavigationStates($ancestorIds->keys(), true);
    }

    public function toggleFolderNavigationFolder(int $folderId, bool $currentlyExpanded = false): void
    {
        if ($folderId < 1) {
            return;
        }

        $visibleFolders = $this->accessibleNavigationFolders();
        $folder = $visibleFolders->firstWhere('id', $folderId);

        if (! $folder instanceof EmailFolder) {
            return;
        }

        $hasVisibleChild = $visibleFolders->contains(fn (EmailFolder $candidate): bool => (int) $candidate->account_id === (int) $folder->account_id
            && $this->providerParentPath($candidate) === (string) $folder->path
            && (int) $candidate->id !== (int) $folder->id
        );
        if (! $hasVisibleChild) {
            return;
        }

        $this->persistFolderNavigationStates(collect([$folderId]), ! $currentlyExpanded);
    }

    public function render(): View
    {
        $data = $this->navigationData();
        $containers = $data['accountIds'] === []
            ? collect()
            : EmailFolder::query()
                ->whereIn('account_id', $data['accountIds'])
                ->where('is_selectable', false)
                ->get();
        $allFolders = $data['folders']
            ->concat($containers)
            ->unique('id')
            ->values();
        $preferenceStates = ! auth()->id() || $allFolders->isEmpty()
            ? collect()
            : EmailFolderNavigationPreference::query()
                ->where('user_id', auth()->id())
                ->whereIn('email_folder_id', $allFolders->pluck('id'))
                ->get(['email_folder_id', 'is_expanded'])
                ->keyBy(fn (EmailFolderNavigationPreference $preference): int => (int) $preference->email_folder_id);

        $data['folderTreesByAccount'] = $this->folderNavigationTrees($allFolders, $preferenceStates);

        return view('email::Livewire.Tech.mail-sidebar', $data);
    }

    /**
     * Build the View-authorized navigation hierarchy without the folder manager's
     * Organize-only action counts. Non-selectable rows survive only as parents of
     * a currently selectable descendant; deleted/non-selectable leaves stay hidden.
     *
     * @return Collection<int, array<int, array<string, mixed>>>
     */
    private function folderNavigationTrees(Collection $folders, Collection $preferenceStates): Collection
    {
        return $folders
            ->groupBy(fn (EmailFolder $folder): int => (int) $folder->account_id)
            ->map(fn (Collection $accountFolders): array => $this->folderNavigationTree(
                $accountFolders,
                $preferenceStates,
            ));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function folderNavigationTree(Collection $folders, Collection $preferenceStates): array
    {
        $folders = $folders->unique('path')->values();
        $foldersByPath = $folders->keyBy(fn (EmailFolder $folder): string => (string) $folder->path);
        $visibleIds = $folders
            ->filter(fn (EmailFolder $folder): bool => (bool) $folder->is_selectable)
            ->pluck('id')
            ->map(fn (int|string $id): int => (int) $id)
            ->flip();

        foreach ($folders->where('is_selectable', true) as $folder) {
            $this->includeFolderAncestors($folder, $foldersByPath, $visibleIds);
        }

        $visibleFolders = $folders
            ->filter(fn (EmailFolder $folder): bool => $visibleIds->has((int) $folder->id))
            ->values();
        $visibleFolderIds = $visibleFolders
            ->pluck('id')
            ->map(fn (int|string $id): int => (int) $id)
            ->flip();
        $visibleByPath = $visibleFolders->keyBy(fn (EmailFolder $folder): string => (string) $folder->path);
        $childrenByParent = $visibleFolders->groupBy(function (EmailFolder $folder) use ($visibleByPath): string {
            $parentPath = $this->providerParentPath($folder);

            return $parentPath !== null
                && $parentPath !== (string) $folder->path
                && $visibleByPath->has($parentPath)
                    ? $parentPath
                    : '';
        });
        $effectiveExpanded = $preferenceStates
            ->filter(fn (EmailFolderNavigationPreference $preference): bool => $preference->is_expanded && $visibleFolderIds->has((int) $preference->email_folder_id)
            )
            ->keys()
            ->map(fn (int|string $id): int => (int) $id)
            ->flip();

        $selectedAncestorIds = collect();
        $selected = $visibleFolders->firstWhere('id', (int) $this->folderId);
        if ($selected instanceof EmailFolder) {
            $this->includeFolderAncestors($selected, $visibleByPath, $effectiveExpanded);
            $this->includeFolderAncestors($selected, $visibleByPath, $selectedAncestorIds);
        }

        $collapsedIds = $preferenceStates
            ->filter(fn (EmailFolderNavigationPreference $preference): bool => ! $preference->is_expanded && $visibleFolderIds->has((int) $preference->email_folder_id)
            )
            ->keys()
            ->map(fn (int|string $id): int => (int) $id)
            ->all();
        $effectiveExpanded->forget($collapsedIds);

        $visited = [];
        $build = function (EmailFolder $folder, int $depth) use (&$build, &$visited, $childrenByParent, $effectiveExpanded, $selectedAncestorIds): array {
            $visited[(int) $folder->id] = true;
            $children = $this->sortFolderNavigationSiblings(
                $childrenByParent->get((string) $folder->path, collect())
                    ->reject(fn (EmailFolder $child): bool => isset($visited[(int) $child->id])),
            );
            $childNodes = [];

            foreach ($children as $child) {
                if (! isset($visited[(int) $child->id])) {
                    $childNodes[] = $build($child, $depth + 1);
                }
            }

            return [
                'id' => (int) $folder->id,
                'account_id' => (int) $folder->account_id,
                'name' => (string) ($folder->name ?: $folder->path),
                'path' => (string) $folder->path,
                'role' => (string) $folder->role,
                'is_selectable' => (bool) $folder->is_selectable,
                'depth' => $depth,
                'has_children' => $childNodes !== [],
                'is_expanded' => $effectiveExpanded->has((int) $folder->id),
                'contains_selected_descendant' => $selectedAncestorIds->has((int) $folder->id)
                    && ! $effectiveExpanded->has((int) $folder->id),
                'unseen_count' => $folder->unseen_count,
                'sync_status' => (string) $folder->sync_status,
                'children' => $childNodes,
            ];
        };

        $nodes = [];
        foreach ($this->sortFolderNavigationSiblings($childrenByParent->get('', collect())) as $root) {
            if (! isset($visited[(int) $root->id])) {
                $nodes[] = $build($root, 0);
            }
        }

        // Corrupt or cyclic provider metadata must not recurse forever or make a
        // selectable folder disappear. Any remaining node is rendered as a root.
        foreach ($this->sortFolderNavigationSiblings($visibleFolders) as $folder) {
            if (! isset($visited[(int) $folder->id])) {
                $nodes[] = $build($folder, 0);
            }
        }

        return $nodes;
    }

    private function includeFolderAncestors(
        EmailFolder $folder,
        Collection $foldersByPath,
        Collection $includedIds,
    ): void {
        $parentPath = $this->providerParentPath($folder);
        $seenPaths = [];

        while ($parentPath !== null && ! isset($seenPaths[$parentPath])) {
            $seenPaths[$parentPath] = true;
            $parent = $foldersByPath->get($parentPath);

            if (! $parent instanceof EmailFolder) {
                break;
            }

            $includedIds->put((int) $parent->id, true);
            $parentPath = $this->providerParentPath($parent);
        }
    }

    private function providerParentPath(EmailFolder $folder): ?string
    {
        $path = $folder->getAttribute('parent_path');
        if ($path === null) {
            return null;
        }

        try {
            return EmailProviderPath::normalize(is_string($path) ? $path : '');
        } catch (InvalidArgumentException) {
            // Invalid legacy hierarchy evidence must not be attached to a
            // byte-distinct sibling merely because whitespace was trimmed.
            return null;
        }
    }

    private function sortFolderNavigationSiblings(Collection $folders): Collection
    {
        return $folders
            ->sort(function (EmailFolder $first, EmailFolder $second): int {
                return [
                    $this->folderNavigationRoleOrder($first->role),
                    mb_strtolower((string) ($first->name ?: $first->path)),
                    mb_strtolower((string) $first->path),
                ] <=> [
                    $this->folderNavigationRoleOrder($second->role),
                    mb_strtolower((string) ($second->name ?: $second->path)),
                    mb_strtolower((string) $second->path),
                ];
            })
            ->values();
    }

    private function folderNavigationRoleOrder(?string $role): int
    {
        return match ($role) {
            EmailFolder::ROLE_INBOX => 0,
            EmailFolder::ROLE_DRAFTS => 1,
            EmailFolder::ROLE_SENT => 2,
            EmailFolder::ROLE_ARCHIVE => 3,
            EmailFolder::ROLE_TRASH => 4,
            EmailFolder::ROLE_JUNK => 5,
            default => 6,
        };
    }

    /**
     * Resolve only rows that belong to mailboxes the technician may currently
     * view. Non-selectable provider rows survive only when they lead to a
     * selectable descendant, matching the rendered navigation tree.
     */
    private function accessibleNavigationFolders(): Collection
    {
        $accountIds = app(MailboxAccess::class)
            ->scopeContentAccounts(
                EmailAccount::query(),
                auth()->user(),
                ResolveMailboxAccessDecision::CONTENT_VIEW,
            )
            ->pluck('id');

        $folders = EmailFolder::query()
            ->whereIn('account_id', $accountIds)
            ->get();
        $visibleIds = $folders
            ->where('is_selectable', true)
            ->pluck('id')
            ->map(fn (int|string $id): int => (int) $id)
            ->flip();

        foreach ($folders->groupBy('account_id') as $accountFolders) {
            $foldersByPath = $accountFolders->keyBy(fn (EmailFolder $folder): string => (string) $folder->path);
            foreach ($accountFolders->where('is_selectable', true) as $folder) {
                $this->includeFolderAncestors($folder, $foldersByPath, $visibleIds);
            }
        }

        return $folders
            ->filter(fn (EmailFolder $folder): bool => $visibleIds->has((int) $folder->id))
            ->values();
    }

    /**
     * Persist only server-resolved folder IDs. Each row is independent so two
     * browser sessions can open different branches without overwriting state.
     */
    private function persistFolderNavigationStates(Collection $folderIds, bool $isExpanded): void
    {
        $userId = (int) auth()->id();
        $ids = $folderIds
            ->filter(fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->take(500)
            ->values();

        if ($userId < 1 || $ids->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($ids, $isExpanded, $userId): void {
            $now = now();
            EmailFolderNavigationPreference::query()->upsert(
                $ids->map(fn (int $folderId): array => [
                    'user_id' => $userId,
                    'email_folder_id' => $folderId,
                    'is_expanded' => $isExpanded,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all(),
                ['user_id', 'email_folder_id'],
                ['is_expanded', 'updated_at'],
            );
        });
    }
}
