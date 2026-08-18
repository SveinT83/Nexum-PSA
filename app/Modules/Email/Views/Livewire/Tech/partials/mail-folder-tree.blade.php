<ul
    id="{{ $listId }}"
    class="mail-folder-tree-list {{ ($nested ?? false) ? 'mail-folder-tree-children' : '' }}"
    @if(! ($nested ?? false)) aria-labelledby="{{ $labelledBy }}" @endif>
    @foreach($nodes as $node)
        @php
            $isCurrent = (int) $folderId === (int) $node['id'];
            $childrenId = 'mail-folder-children-'.$node['account_id'].'-'.$node['id'];
            $unreadCount = $node['unseen_count'] === null ? null : max(0, (int) $node['unseen_count']);
            $syncFailed = $node['sync_status'] === 'error';
        @endphp
        <li
            wire:key="mail-folder-node-{{ $node['account_id'] }}-{{ $node['id'] }}"
            data-mail-folder-node="{{ $node['id'] }}"
            data-mail-folder-depth="{{ $node['depth'] }}">
            <div class="mail-folder-tree-row">
                @if($node['has_children'])
                    <button
                        type="button"
                        class="mail-folder-tree-toggle btn btn-sm btn-link text-muted p-0"
                        wire:click.stop="toggleFolderNavigationFolder({{ $node['id'] }}, {{ $node['is_expanded'] ? 'true' : 'false' }})"
                        aria-expanded="{{ $node['is_expanded'] ? 'true' : 'false' }}"
                        aria-controls="{{ $childrenId }}"
                        title="{{ $node['is_expanded'] ? 'Hide' : 'Show' }} subfolders of {{ $node['path'] }}">
                        <i class="bi {{ $node['is_expanded'] ? 'bi-chevron-down' : 'bi-chevron-right' }}" aria-hidden="true"></i>
                        <span class="visually-hidden">{{ $node['is_expanded'] ? 'Hide' : 'Show' }} subfolders of {{ $node['path'] }}</span>
                    </button>
                @else
                    <span class="mail-folder-tree-spacer" aria-hidden="true"></span>
                @endif

                @if($node['is_selectable'])
                    <button
                        type="button"
                        id="mail-folder-select-{{ $node['account_id'] }}-{{ $node['id'] }}"
                        class="mail-folder-tree-select {{ $isCurrent ? 'active' : '' }} {{ $node['contains_selected_descendant'] ? 'contains-current' : '' }}"
                        wire:click="selectFolder({{ $node['id'] }})"
                        @if($isCurrent) aria-current="page" @endif
                        title="{{ $node['path'] }}">
                        <i class="bi {{ match($node['role']) {
                            'inbox' => 'bi-inbox',
                            'sent' => 'bi-send',
                            'drafts' => 'bi-file-earmark-text',
                            'archive' => 'bi-archive',
                            'trash' => 'bi-trash',
                            'junk' => 'bi-shield-exclamation',
                            default => 'bi-folder',
                        } }} flex-shrink-0" aria-hidden="true"></i>
                        <span class="mail-folder-tree-label text-truncate">{{ $node['name'] }}</span>
                        <span class="visually-hidden">Folder path {{ $node['path'] }}.</span>
                        @if($syncFailed)
                            <span class="badge text-bg-light border flex-shrink-0" data-mail-folder-unread-state="error" title="Mailbox unread unavailable">
                                <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
                                <span class="visually-hidden">Mailbox unread count is unavailable.</span>
                            </span>
                        @elseif($unreadCount !== null && $unreadCount > 0)
                            <span class="badge text-bg-warning flex-shrink-0" data-mail-folder-unread-state="count" title="{{ number_format($unreadCount) }} mailbox unread">
                                <span aria-hidden="true">{{ number_format($unreadCount) }}</span>
                                <span class="visually-hidden">{{ number_format($unreadCount) }} mailbox unread messages.</span>
                            </span>
                        @elseif($unreadCount === null)
                            <span class="badge text-bg-light border flex-shrink-0" data-mail-folder-unread-state="unknown" title="Mailbox unread not reported">
                                <span aria-hidden="true">—</span>
                                <span class="visually-hidden">Mailbox unread count has not been reported.</span>
                            </span>
                        @endif
                        @if($node['contains_selected_descendant'])
                            <span class="badge rounded-pill text-bg-primary flex-shrink-0" data-mail-folder-contains-current title="Contains current folder">
                                <i class="bi bi-record-circle-fill" aria-hidden="true"></i>
                                <span class="visually-hidden">Contains current folder.</span>
                            </span>
                        @endif
                    </button>
                @else
                    <div class="mail-folder-tree-container {{ $node['contains_selected_descendant'] ? 'contains-current' : '' }}" title="{{ $node['path'] }}">
                        <i class="bi bi-folder-fill flex-shrink-0" aria-hidden="true"></i>
                        <span class="mail-folder-tree-label text-truncate">{{ $node['name'] }}</span>
                        <span class="visually-hidden">Folder container at path {{ $node['path'] }}.</span>
                        @if($node['contains_selected_descendant'])
                            <span class="badge rounded-pill text-bg-primary flex-shrink-0" data-mail-folder-contains-current title="Contains current folder">
                                <i class="bi bi-record-circle-fill" aria-hidden="true"></i>
                                <span class="visually-hidden">Contains current folder.</span>
                            </span>
                        @endif
                    </div>
                @endif
            </div>

            @if($node['has_children'])
                <div id="{{ $childrenId }}" @if(! $node['is_expanded']) hidden @endif>
                    @if($node['is_expanded'])
                        @include('email::Livewire.Tech.partials.mail-folder-tree', [
                            'nodes' => $node['children'],
                            'listId' => $childrenId.'-list',
                            'labelledBy' => $labelledBy,
                            'nested' => true,
                        ])
                    @endif
                </div>
            @endif
        </li>
    @endforeach
</ul>
