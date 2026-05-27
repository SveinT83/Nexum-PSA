<div class="dropdown" x-data="{ open: false }" x-on:click.away="open = false">
    <button class="btn btn-link position-relative text-dark" x-on:click="open = !open"
            aria-expanded="false" aria-haspopup="true">
        <i class="bi bi-bell fs-5"></i>
        @if($unreadCount > 0)
            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"
                  style="font-size: 0.65rem;">
                {{ $unreadCount > 9 ? '9+' : $unreadCount }}
            </span>
        @endif
    </button>

    <div class="dropdown-menu dropdown-menu-end shadow" style="width: 360px; max-height: 480px; overflow-y: auto;"
         x-show="open" x-transition>

        <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom">
            <h6 class="mb-0">Notifications</h6>
            @if($unreadCount > 0)
                <button class="btn btn-sm btn-link text-muted p-0"
                        wire:click="markAllAsRead">
                    Mark all read
                </button>
            @endif
        </div>

        @if($notifications->isEmpty())
            <div class="text-center text-muted py-4">
                <i class="bi bi-bell-slash fs-4 d-block mb-2"></i>
                No new notifications
            </div>
        @else
            @foreach($notifications as $notification)
                @php
                    $data = $notification->data;
                    $icon = match($data['type'] ?? '') {
                        'ticket_assigned' => 'bi-person-check',
                        'ticket_status_changed' => 'bi-arrow-left-right',
                        'ticket_comment_added' => 'bi-chat-left-text',
                        'ticket_sla_warning' => 'bi-clock-history',
                        'asset_alert' => 'bi-exclamation-triangle',
                        'asset_alert_resolved' => 'bi-check-circle',
                        'user_invited' => 'bi-envelope-paper',
                        default => 'bi-bell',
                    };
                    $color = match($data['type'] ?? '') {
                        'ticket_assigned' => 'primary',
                        'ticket_status_changed' => 'info',
                        'ticket_comment_added' => 'secondary',
                        'ticket_sla_warning' => 'warning',
                        'asset_alert' => 'danger',
                        'asset_alert_resolved' => 'success',
                        default => 'secondary',
                    };
                    $url = $data['url'] ?? '#';
                @endphp

                <a href="{{ $url }}" class="dropdown-item px-3 py-2 {{ $loop->last ? '' : 'border-bottom' }}"
                   wire:click="markAsRead('{{ $notification->id }}')"
                   style="white-space: normal;">

                    <div class="d-flex align-items-start gap-2">
                        <i class="bi {{ $icon }} text-{{ $color }} mt-1"></i>
                        <div class="flex-grow-1">
                            <div class="small fw-bold">
                                {{ $data['ticket_subject'] ?? $data['alert_title'] ?? $data['type'] ?? 'Notification' }}
                            </div>
                            <div class="small text-muted">
                                @switch($data['type'] ?? '')
                                    @case('ticket_assigned')
                                        Assigned by {{ $data['assigned_by'] ?? 'system' }}
                                        @break
                                    @case('ticket_status_changed')
                                        {{ $data['old_status'] }} → {{ $data['new_status'] }}
                                        @break
                                    @case('ticket_comment_added')
                                        {{ $data['comment_author'] ?? 'Someone' }} commented
                                        @break
                                    @case('ticket_sla_warning')
                                        {{ ucfirst($data['severity'] ?? 'warning') }} — {{ ucfirst($data['sla_type'] ?? '') }} SLA
                                        @break
                                    @case('asset_alert')
                                        {{ $data['alert_title'] ?? 'Alert' }}
                                        @break
                                    @default
                                        {{ $data['type'] ?? 'Notification' }}
                                @endswitch
                            </div>
                            <div class="small text-muted">
                                {{ $notification->created_at->diffForHumans() }}
                            </div>
                        </div>
                    </div>
                </a>
            @endforeach
        @endif

        @if($unreadCount > 0 || !$notifications->isEmpty())
            <div class="text-center border-top py-2">
                <a href="{{ route('tech.profile.notifications') }}" class="small">
                    View all notifications →
                </a>
            </div>
        @endif
    </div>
</div>