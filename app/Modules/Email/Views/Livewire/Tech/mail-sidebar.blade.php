<div>
    <style>
        .mail-sidebar-scroll {
            max-height: calc(100vh - 420px);
            overflow: auto;
        }

        .mail-sidebar-button {
            min-width: 0;
        }

        .mail-folder-tree-list {
            list-style: none;
            margin: 0;
            padding: 0;
        }

        .mail-folder-tree-children {
            border-inline-start: 1px solid var(--bs-border-color, #dee2e6);
            margin-inline-start: 1.1rem;
            padding-inline-start: .45rem;
        }

        .mail-folder-tree-row {
            align-items: stretch;
            display: flex;
            min-width: 0;
        }

        .mail-folder-tree-toggle,
        .mail-folder-tree-spacer {
            align-items: center;
            display: inline-flex;
            flex: 0 0 2.25rem;
            justify-content: center;
            min-height: 2.75rem;
            width: 2.25rem;
        }

        .mail-folder-tree-toggle {
            border: 0;
            border-radius: .35rem;
        }

        .mail-folder-tree-select,
        .mail-folder-tree-container {
            align-items: center;
            border: 0;
            border-inline-start: 3px solid transparent;
            color: var(--bs-body-color, #212529);
            display: flex;
            flex: 1 1 auto;
            gap: .5rem;
            min-height: 2.75rem;
            min-width: 0;
            padding: .35rem .5rem;
            text-align: start;
        }

        .mail-folder-tree-select {
            background: transparent;
            border-radius: .3rem;
        }

        .mail-folder-tree-select:hover {
            background: var(--bs-tertiary-bg, #f8f9fa);
        }

        .mail-folder-tree-select.active {
            background: rgba(var(--bs-primary-rgb), .1);
            border-inline-start-color: var(--bs-primary);
            color: var(--bs-body-color, #212529);
            font-weight: 600;
        }

        .mail-folder-tree-select.contains-current,
        .mail-folder-tree-container.contains-current {
            border-inline-start-color: rgba(var(--bs-primary-rgb), .65);
        }

        .mail-folder-tree-select:focus-visible,
        .mail-folder-tree-toggle:focus-visible {
            outline: 2px solid var(--bs-primary);
            outline-offset: 1px;
        }

        .mail-folder-tree-container {
            color: var(--bs-secondary-color, #6c757d);
            font-weight: 600;
        }

        .mail-folder-tree-label {
            min-width: 0;
        }

        .mail-min-w-0 {
            min-width: 0;
        }

        .mail-folder-manager-backdrop {
            align-items: center;
            background: rgba(15, 23, 42, .58);
            display: flex;
            inset: 0;
            justify-content: center;
            padding: 1rem;
            position: fixed;
            z-index: 1060;
        }

        .mail-folder-manager-modal {
            display: flex;
            max-height: calc(100dvh - 2rem);
            max-width: min(760px, calc(100vw - 2rem));
            width: 100%;
        }

        .mail-folder-manager-modal .modal-content {
            background: var(--bs-body-bg, #fff);
            border: 1px solid var(--bs-border-color, #dee2e6);
            color: var(--bs-body-color, #212529);
            display: flex;
            flex-direction: column;
            max-height: inherit;
            overflow: hidden;
        }

        .mail-folder-manager-modal .modal-header,
        .mail-folder-manager-modal .modal-footer {
            flex: 0 0 auto;
            padding: .85rem 1rem;
        }

        .mail-folder-manager-modal .modal-body {
            flex: 1 1 auto;
            min-height: 0;
            overflow-y: auto;
            overscroll-behavior: contain;
            padding: .85rem;
        }

        .mail-folder-manager-modal .modal-header,
        .mail-folder-manager-modal .modal-body,
        .mail-folder-manager-modal .modal-footer,
        .mail-folder-manager-modal .list-group-item {
            background: var(--bs-body-bg, #fff);
        }

        .mail-folder-manager-modal form,
        .mail-folder-manager-modal .mail-folder-inline-panel {
            background: var(--bs-tertiary-bg, #f8f9fa);
        }

        .mail-folder-manager-modal .mail-folder-create-panel,
        .mail-folder-manager-modal .mail-folder-inline-panel {
            padding: .65rem;
        }

        .mail-folder-manager-modal .mail-folder-row {
            border: 1px solid var(--bs-border-color, #dee2e6);
            border-radius: .4rem;
            margin-bottom: .38rem;
            margin-left: calc(var(--mail-folder-depth, 0) * 1rem);
            padding: .5rem .6rem;
        }

        .mail-folder-manager-modal .mail-folder-row-main {
            min-height: 2rem;
        }

        .mail-folder-manager-modal .mail-folder-row-muted {
            opacity: .82;
        }

        .mail-folder-manager-modal .mail-folder-child-row {
            background: var(--bs-tertiary-bg, #f8f9fa);
            border-left-width: 3px;
        }

        .mail-folder-manager-modal .mail-folder-tree-control {
            flex: 0 0 1.15rem;
            line-height: 1;
            width: 1.15rem;
        }

        .mail-folder-manager-modal .mail-folder-tree-control .btn {
            line-height: 1;
        }

        .mail-folder-manager-modal .mail-folder-meta,
        .mail-folder-manager-modal .mail-folder-blockers {
            line-height: 1.2;
        }

        .mail-folder-manager-modal .mail-folder-row .badge {
            font-size: .68rem;
            padding: .16rem .35rem;
        }

        .mail-folder-manager-modal .mail-folder-row:last-child {
            margin-bottom: 0;
        }

        @media (max-width: 767.98px) {
            .mail-folder-manager-backdrop {
                align-items: stretch;
                padding: .5rem;
            }

            .mail-folder-manager-modal {
                max-height: calc(100dvh - 1rem);
                max-width: calc(100vw - 1rem);
            }

            .mail-sidebar-scroll {
                max-height: none;
            }

            .mail-folder-tree-children {
                margin-inline-start: .7rem;
                padding-inline-start: .3rem;
            }
        }
    </style>

    <!-- ------------------------------------------------- -->
    <!-- Mail Sidebar Navigation -->
    <!-- ------------------------------------------------- -->
    <nav class="border-top mt-3 pt-3 pb-3" aria-label="Mail workspace navigation">
        <div class="px-2 mb-3">
            <div class="small text-uppercase fw-semibold text-muted">Mail</div>
        </div>

        <div class="mb-3">
            <div class="small text-uppercase fw-semibold text-muted px-2 mb-2">Views</div>
            <div class="nav nav-pills flex-column gap-1">
                @if($hasOrdinaryMailboxAccess)
                    <button
                        type="button"
                        class="nav-link mail-sidebar-button d-flex align-items-center justify-content-between gap-2 px-3 py-2 {{ $viewMode === 'unread' && ! $folderId ? 'active' : 'link-dark bg-light border' }}"
                        wire:click="setView('unread')">
                        <span class="d-flex align-items-center gap-2 text-truncate">
                            <i class="bi bi-envelope-exclamation" aria-hidden="true"></i>
                            <span>Unread</span>
                        </span>
                        <span class="badge {{ $viewMode === 'unread' && ! $folderId ? 'text-bg-light' : 'text-bg-success' }}">{{ number_format($stats['unread_for_me']) }}</span>
                    </button>
                @endif

                <button
                    type="button"
                    class="nav-link mail-sidebar-button d-flex align-items-center justify-content-between gap-2 px-3 py-2 {{ $viewMode === 'inbox' && ! $folderId ? 'active' : 'link-dark bg-light border' }}"
                    wire:click="setView('inbox')">
                    <span class="d-flex align-items-center gap-2 text-truncate">
                        <i class="bi bi-inbox" aria-hidden="true"></i>
                        <span>Inbox</span>
                    </span>
                    <span class="badge {{ $viewMode === 'inbox' && ! $folderId ? 'text-bg-light' : 'text-bg-primary' }}">{{ number_format($stats['inbox']) }}</span>
                </button>

                <button
                    type="button"
                    class="nav-link mail-sidebar-button d-flex align-items-center justify-content-between gap-2 px-3 py-2 {{ $viewMode === 'drafts' && ! $folderId ? 'active' : 'link-dark bg-light border' }}"
                    wire:click="setView('drafts')">
                    <span class="d-flex align-items-center gap-2 text-truncate">
                        <i class="bi bi-file-earmark-text" aria-hidden="true"></i>
                        <span>Drafts</span>
                    </span>
                    <span class="badge {{ $viewMode === 'drafts' && ! $folderId ? 'text-bg-light' : 'text-bg-secondary' }}">{{ number_format($stats['drafts']) }}</span>
                </button>

                <button
                    type="button"
                    class="nav-link mail-sidebar-button d-flex align-items-center justify-content-between gap-2 px-3 py-2 {{ $viewMode === 'all' && ! $folderId ? 'active' : 'link-dark bg-light border' }}"
                    wire:click="setView('all')">
                    <span class="d-flex align-items-center gap-2 text-truncate">
                        <i class="bi bi-collection" aria-hidden="true"></i>
                        <span>All mail</span>
                    </span>
                    <span class="badge {{ $viewMode === 'all' && ! $folderId ? 'text-bg-light' : 'text-bg-secondary' }}">{{ number_format($stats['all']) }}</span>
                </button>
            </div>
        </div>

        <div class="mb-3">
            <div class="small text-uppercase fw-semibold text-muted px-2 mb-2">Mailboxes</div>
            <div class="list-group list-group-flush">
                <button
                    type="button"
                    class="list-group-item list-group-item-action px-2 py-2 {{ ! $accountId ? 'fw-semibold' : '' }}"
                    wire:click="selectAccount('')">
                    All accessible
                </button>
                @forelse($accounts as $account)
                    <button
                        type="button"
                        class="list-group-item list-group-item-action px-2 py-2 {{ (int) $accountId === (int) $account->id ? 'fw-semibold text-primary' : '' }}"
                        wire:click="selectAccount({{ $account->id }})">
                        <div class="d-flex align-items-center justify-content-between gap-2">
                            <span class="text-truncate">{{ $account->address }}</span>
                            @if($activeBreakGlassAccesses->contains('email_account_id', $account->id))
                                <span class="badge text-bg-danger">Emergency</span>
                            @else
                                <span class="badge text-bg-light border">{{ $account->account_kind }}</span>
                            @endif
                        </div>
                    </button>
                @empty
                    <div class="text-muted small px-2">No accessible mailboxes.</div>
                @endforelse
            </div>
        </div>

        <div>
            <div class="d-flex align-items-center justify-content-between gap-2 px-2 mb-2">
                <div class="small text-uppercase fw-semibold text-muted">Folders</div>
                <div class="d-flex align-items-center gap-1">
                    @if($this->canRefreshSelectedFolder())
                        <button
                            type="button"
                            class="btn btn-sm btn-outline-secondary btn-icon"
                            wire:click="refreshSelectedFolder"
                            wire:loading.attr="disabled"
                            wire:target="refreshSelectedFolder"
                            title="Refresh selected folder">
                            <span wire:loading.remove wire:target="refreshSelectedFolder">
                                <i class="bi bi-arrow-clockwise" aria-hidden="true"></i>
                            </span>
                            <span wire:loading wire:target="refreshSelectedFolder" class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                            <span class="visually-hidden">Refresh folder</span>
                        </button>
                    @endif
                    @if($this->canManageFoldersForSelectedAccount())
                        <button type="button" class="btn btn-sm btn-outline-secondary btn-icon" wire:click="openFolderManager" title="Manage folders">
                            <i class="bi bi-gear" aria-hidden="true"></i>
                            <span class="visually-hidden">Manage folders</span>
                        </button>
                    @endif
                </div>
            </div>
            @if($mailActionStatus)
                <div class="alert alert-{{ $mailActionStatus['type'] ?? 'info' }} py-2 px-2 mx-2 small" role="status">
                    {{ $mailActionStatus['message'] ?? '' }}
                </div>
            @endif
            <div class="mail-sidebar-scroll">
                @if($folders->isEmpty())
                    <div class="text-muted small px-2">No folders discovered yet.</div>
                @else
                    @foreach($accounts as $account)
                        @continue($accountId && (int) $accountId !== (int) $account->id)
                        @php($accountFolderTree = $folderTreesByAccount->get($account->id, []))
                        @continue($accountFolderTree === [])
                        <div class="mb-3">
                            @if($accounts->count() > 1 && ! $accountId)
                                <div id="mail-folder-account-{{ $account->id }}" class="small fw-semibold text-muted text-truncate px-2 mb-1">
                                    {{ $account->address }}
                                </div>
                            @else
                                <span id="mail-folder-account-{{ $account->id }}" class="visually-hidden">Folders for {{ $account->address }}</span>
                            @endif
                            @include('email::Livewire.Tech.partials.mail-folder-tree', [
                                'nodes' => $accountFolderTree,
                                'listId' => 'mail-folder-tree-'.$account->id,
                                'labelledBy' => 'mail-folder-account-'.$account->id,
                            ])
                        </div>
                    @endforeach
                @endif
            </div>
        </div>
    </nav>

    @if($folderManagerOpen)
        <div class="mail-folder-manager-backdrop">
            <div class="mail-folder-manager-modal modal-dialog modal-dialog-scrollable" role="dialog" aria-modal="true" aria-labelledby="mail-folder-manager-title">
                <div class="modal-content shadow">
                    <div class="modal-header">
                        <div>
                            <h2 id="mail-folder-manager-title" class="modal-title h5">Manage folders</h2>
                            <div class="small text-muted">{{ $this->folderManagerAccountLabel() }}</div>
                            @php($folderManagerAccounts = $this->folderManagerAccounts())
                            @if($folderManagerAccounts->count() > 1)
                                <select class="form-select form-select-sm mt-2" wire:change="changeFolderManagerAccount($event.target.value)" aria-label="Mailbox to manage">
                                    @foreach($folderManagerAccounts as $managerAccount)
                                        <option value="{{ $managerAccount->id }}" @selected((int) $folderManagerAccountId === (int) $managerAccount->id)>
                                            {{ $managerAccount->address }}
                                        </option>
                                    @endforeach
                                </select>
                            @endif
                        </div>
                        <button type="button" class="btn-close" wire:click="closeFolderManager" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        @if($mailActionStatus)
                            <div class="alert alert-{{ $mailActionStatus['type'] ?? 'info' }} py-2 px-2 small" role="status">
                                {{ $mailActionStatus['message'] ?? '' }}
                            </div>
                        @endif

                        <form class="mail-folder-create-panel border rounded mb-3" wire:submit.prevent="createProviderFolder">
                            <div class="row g-2 align-items-end">
                                <div class="col-12 col-md">
                                    <label for="mail-new-folder-parent" class="form-label small fw-semibold mb-1">Create in</label>
                                    <select id="mail-new-folder-parent" class="form-select form-select-sm @error('newFolderParentId') is-invalid @enderror" wire:model.defer="newFolderParentId">
                                        <option value="">Root</option>
                                        @foreach($this->folderParentTargetsFor() as $target)
                                            <option value="{{ $target->id }}">{{ $target->path }}</option>
                                        @endforeach
                                    </select>
                                    @error('newFolderParentId')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                                <div class="col-12 col-md">
                                    <label for="mail-new-folder-name" class="form-label small fw-semibold mb-1">New folder</label>
                                    <div class="input-group input-group-sm">
                                        <input id="mail-new-folder-name" type="text" class="form-control @error('newFolderName') is-invalid @enderror" wire:model.defer="newFolderName" maxlength="180" placeholder="Client">
                                        <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="createProviderFolder" title="Create folder">
                                            <i class="bi bi-check-lg" aria-hidden="true"></i>
                                            <span class="visually-hidden">Create folder</span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            @error('newFolderName')
                                <div class="text-danger small mt-1">{{ $message }}</div>
                            @enderror
                        </form>

                        <div>
                            @forelse($this->folderManagerRows() as $row)
                                <div class="mail-folder-row {{ (int) $row['depth'] > 0 ? 'mail-folder-child-row' : '' }} {{ $row['is_selectable'] ? '' : 'mail-folder-row-muted' }}" style="--mail-folder-depth: {{ min((int) $row['depth'], 6) }};">
                                    <div class="mail-folder-row-main d-flex align-items-start justify-content-between gap-2">
                                        <div class="d-flex align-items-start gap-2 mail-min-w-0">
                                            <div class="mail-folder-tree-control">
                                                @if($row['has_children'])
                                                    <button type="button" class="btn btn-sm btn-link text-muted p-0" wire:click="toggleFolderManagerFolder({{ $row['id'] }})" title="{{ $row['is_expanded'] ? 'Hide subfolders' : 'Show subfolders' }}">
                                                        <i class="bi {{ $row['is_expanded'] ? 'bi-chevron-down' : 'bi-chevron-right' }}" aria-hidden="true"></i>
                                                        <span class="visually-hidden">{{ $row['is_expanded'] ? 'Hide subfolders' : 'Show subfolders' }}</span>
                                                    </button>
                                                @elseif((int) $row['depth'] > 0)
                                                    <i class="bi bi-arrow-return-right text-muted" aria-hidden="true"></i>
                                                @endif
                                            </div>
                                            <div class="mail-min-w-0">
                                            <div class="fw-semibold text-truncate">
                                                <i class="bi {{ match($row['role']) {
                                                    'inbox' => 'bi-inbox',
                                                    'sent' => 'bi-send',
                                                    'drafts' => 'bi-file-earmark-text',
                                                    'trash' => 'bi-trash',
                                                    'junk' => 'bi-shield-exclamation',
                                                    default => 'bi-folder',
                                                } }} me-2" aria-hidden="true"></i>{{ $row['name'] }}
                                            </div>
                                            <div class="mail-folder-meta small text-muted text-truncate">{{ $row['path'] }}</div>
                                            <div class="d-flex flex-wrap gap-1 mt-1">
                                                <span class="badge text-bg-light border">{{ $this->mailCountLabel($row['active_placements']) }}</span>
                                                @if(! $row['is_selectable'])
                                                    <span class="badge text-bg-light border">container</span>
                                                @endif
                                                @if($row['rule_references'] > 0)
                                                    <span class="badge text-bg-warning">{{ $row['rule_references'] }} rules</span>
                                                @endif
                                                @if($row['operation_count'] > 0)
                                                    <span class="badge text-bg-info">{{ $row['operation_count'] }} operations</span>
                                                @endif
                                                @if($row['child_count'] > 0)
                                                    <span class="badge text-bg-secondary">{{ $row['child_count'] }} subfolders</span>
                                                @endif
                                            </div>
                                            @if(! $row['has_actions'] && ! empty($row['action_blockers']))
                                                <div class="mail-folder-blockers small text-muted mt-1">
                                                    <i class="bi bi-lock me-1" aria-hidden="true"></i>No actions: {{ implode(', ', $row['action_blockers']) }}
                                                </div>
                                            @endif
                                            </div>
                                        </div>
                                        <div class="d-flex align-items-center gap-1">
                                            @if($row['can_rename'])
                                                <button type="button" class="btn btn-sm btn-outline-secondary btn-icon" wire:click="startFolderRename({{ $row['id'] }})" title="Rename folder">
                                                    <i class="bi bi-pencil" aria-hidden="true"></i>
                                                    <span class="visually-hidden">Rename folder</span>
                                                </button>
                                            @endif
                                            @if($row['can_move_folder'])
                                                <button type="button" class="btn btn-sm btn-outline-secondary btn-icon" wire:click="startFolderMove({{ $row['id'] }})" title="Move folder">
                                                    <i class="bi bi-arrow-up-right-square" aria-hidden="true"></i>
                                                    <span class="visually-hidden">Move folder</span>
                                                </button>
                                            @endif
                                            @if($row['can_move_before_delete'])
                                                <button type="button" class="btn btn-sm btn-outline-secondary btn-icon" wire:click="startFolderDelete({{ $row['id'] }})" title="Move mail before delete">
                                                    <i class="bi bi-folder-symlink" aria-hidden="true"></i>
                                                    <span class="visually-hidden">Move mail before delete</span>
                                                </button>
                                            @endif
                                            @if($row['can_delete'])
                                                <button type="button" class="btn btn-sm btn-outline-danger btn-icon" wire:click="startFolderDelete({{ $row['id'] }})" title="Delete folder">
                                                    <i class="bi bi-trash" aria-hidden="true"></i>
                                                    <span class="visually-hidden">Delete folder</span>
                                                </button>
                                            @endif
                                        </div>
                                    </div>

                                    @if((int) $folderRenameFolderId === (int) $row['id'])
                                        <div class="mail-folder-inline-panel border rounded mt-3">
                                            <label for="mail-folder-rename-name" class="form-label small fw-semibold mb-1">Rename to</label>
                                            <div class="input-group input-group-sm">
                                                <input id="mail-folder-rename-name" type="text" class="form-control @error('folderRenameName') is-invalid @enderror" wire:model.defer="folderRenameName" maxlength="180">
                                                <button type="button" class="btn btn-primary" wire:click="renameProviderFolder" wire:loading.attr="disabled" wire:target="renameProviderFolder" title="Rename folder">
                                                    <i class="bi bi-check-lg" aria-hidden="true"></i>
                                                    <span class="visually-hidden">Rename folder</span>
                                                </button>
                                                <button type="button" class="btn btn-outline-secondary" wire:click="cancelFolderRename" title="Cancel rename">
                                                    <i class="bi bi-x-lg" aria-hidden="true"></i>
                                                    <span class="visually-hidden">Cancel rename</span>
                                                </button>
                                            </div>
                                            @error('folderRenameName')
                                                <div class="text-danger small mt-1">{{ $message }}</div>
                                            @enderror
                                        </div>
                                    @endif

                                    @if((int) $folderMoveFolderId === (int) $row['id'])
                                        <div class="mail-folder-inline-panel border rounded mt-3">
                                            <label for="mail-folder-move-parent-{{ $row['id'] }}" class="form-label small fw-semibold mb-1">Move to</label>
                                            <div class="row g-2 align-items-end">
                                                <div class="col-12 col-md">
                                                    <select id="mail-folder-move-parent-{{ $row['id'] }}" class="form-select form-select-sm @error('folderMoveParentFolderId') is-invalid @enderror" wire:model.defer="folderMoveParentFolderId">
                                                        <option value="">Root</option>
                                                        @foreach($this->folderParentTargetsFor($row['id']) as $target)
                                                            <option value="{{ $target->id }}">{{ $target->path }}</option>
                                                        @endforeach
                                                    </select>
                                                    @error('folderMoveParentFolderId')
                                                        <div class="invalid-feedback">{{ $message }}</div>
                                                    @enderror
                                                </div>
                                                <div class="col-12 col-md-auto d-flex gap-2">
                                                    <button type="button" class="btn btn-sm btn-primary" wire:click="moveProviderFolder" wire:loading.attr="disabled" wire:target="moveProviderFolder">
                                                        <i class="bi bi-arrow-up-right-square me-1" aria-hidden="true"></i>Move
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="cancelFolderMove">Cancel</button>
                                                </div>
                                            </div>
                                        </div>
                                    @endif

                                    @if((int) $folderDeleteFolderId === (int) $row['id'])
                                        <div class="mail-folder-inline-panel border rounded mt-3">
                                            @if((int) $row['active_placements'] > 0)
                                                <div class="small fw-semibold mb-2">This folder contains {{ $this->mailCountLabel($row['active_placements']) }}.</div>
                                                <div class="row g-2 align-items-end">
                                                    <div class="col-12 col-md">
                                                        <label for="mail-folder-move-target-{{ $row['id'] }}" class="form-label small fw-semibold mb-1">Move to</label>
                                                        <select id="mail-folder-move-target-{{ $row['id'] }}" class="form-select form-select-sm @error('folderMoveTargetFolderId') is-invalid @enderror" wire:model.defer="folderMoveTargetFolderId">
                                                            <option value="">Choose folder</option>
                                                            @foreach($this->folderMoveTargetsFor($row['id']) as $target)
                                                                <option value="{{ $target->id }}">{{ $target->name ?: $target->path }}{{ $target->role !== 'custom' ? ' / '.$target->role : '' }}</option>
                                                            @endforeach
                                                        </select>
                                                        @error('folderMoveTargetFolderId')
                                                            <div class="invalid-feedback">{{ $message }}</div>
                                                        @enderror
                                                    </div>
                                                    <div class="col-12 col-md-auto d-flex gap-2">
                                                        <button type="button" class="btn btn-sm btn-primary" wire:click="moveManagedFolderMail" wire:loading.attr="disabled" wire:target="moveManagedFolderMail">
                                                            <i class="bi bi-folder-symlink me-1" aria-hidden="true"></i>Move mails
                                                        </button>
                                                        <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="cancelFolderDelete">Cancel</button>
                                                    </div>
                                                </div>
                                            @else
                                                <div class="small fw-semibold mb-1">Delete this empty provider folder?</div>
                                                <div class="small text-muted mb-2">The folder is removed from the IMAP server first, then hidden locally after provider acknowledgement.</div>
                                                <div class="d-flex justify-content-end gap-2">
                                                    <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="cancelFolderDelete">Cancel</button>
                                                    <button type="button" class="btn btn-sm btn-danger" wire:click="deleteProviderFolder" wire:loading.attr="disabled" wire:target="deleteProviderFolder">
                                                        <i class="bi bi-trash me-1" aria-hidden="true"></i>Delete
                                                    </button>
                                                </div>
                                            @endif
                                        </div>
                                    @endif
                                </div>
                            @empty
                                <div class="text-muted small">No selectable folders discovered for this mailbox.</div>
                            @endforelse
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="closeFolderManager">Close</button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
