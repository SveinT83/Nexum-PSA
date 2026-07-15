@extends('customerportal::layouts.portal')

@section('title', 'Notifications')

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Portal Notifications -->
    <!-- ------------------------------------------------- -->
    <div class="d-flex align-items-center justify-content-between gap-3 mb-3">
        <div>
            <h1 class="h4 mb-1">Notifications</h1>
            <div class="text-muted small">{{ $context->client->name }} @if($context->site) &middot; {{ $context->site->name }} @endif</div>
        </div>

        @if($unreadCount > 0)
            <form method="POST" action="{{ route('customer-portal.notifications.read-all') }}" class="mb-0">
                @csrf
                <button type="submit" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-check2-all me-1" aria-hidden="true"></i>
                    Mark all read
                </button>
            </form>
        @endif
    </div>

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card shadow-sm">
                <div class="card-header bg-body d-flex align-items-center justify-content-between gap-2">
                    <h2 class="h6 mb-0">Activity</h2>
                    <span class="badge text-bg-light border">{{ $unreadCount }} unread</span>
                </div>

                @if($notifications->isEmpty())
                    <div class="card-body text-center text-muted py-5">
                        <i class="bi bi-bell-slash fs-3 d-block mb-2" aria-hidden="true"></i>
                        No portal notifications yet.
                    </div>
                @else
                    <div class="list-group list-group-flush">
                        @foreach($notifications as $notification)
                            @php
                                $data = $notification->data;
                                $notificationType = $data['type'] ?? '';
                                $icon = match($notificationType) {
                                    'portal_ticket_created', 'portal_ticket_reply', 'portal_ticket_status_changed' => 'bi-ticket-detailed',
                                    'portal_document_published', 'portal_document_updated' => 'bi-folder2-open',
                                    'portal_knowledge_published', 'portal_knowledge_updated' => 'bi-journal-text',
                                    'portal_quote_sent', 'portal_quote_accepted' => 'bi-file-earmark-check',
                                    'portal_contract_sent', 'portal_contract_accepted' => 'bi-file-earmark-text',
                                    'portal_order_published', 'portal_order_status_changed' => 'bi-receipt',
                                    default => 'bi-bell',
                                };
                            @endphp

                            <div class="list-group-item {{ $notification->read_at ? '' : 'bg-light' }}">
                                <div class="d-flex align-items-start gap-3">
                                    <div class="text-primary pt-1">
                                        <i class="bi {{ $icon }}" aria-hidden="true"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <div class="d-flex align-items-start justify-content-between gap-2">
                                            <div>
                                                <div class="fw-semibold">{{ $data['title'] ?? 'Portal notification' }}</div>
                                                <div class="small text-muted">{{ $data['body'] ?? '' }}</div>
                                                <div class="small text-muted mt-1">{{ $notification->created_at?->diffForHumans() }}</div>
                                            </div>
                                            @unless($notification->read_at)
                                                <span class="badge text-bg-primary">New</span>
                                            @endunless
                                        </div>

                                        <div class="d-flex flex-wrap gap-2 mt-2">
                                            <form method="POST" action="{{ route('customer-portal.notifications.open', $notification) }}" class="mb-0">
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-primary">
                                                    <i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>
                                                    Open
                                                </button>
                                            </form>

                                            @unless($notification->read_at)
                                                <form method="POST" action="{{ route('customer-portal.notifications.read', $notification) }}" class="mb-0">
                                                    @csrf
                                                    <button type="submit" class="btn btn-sm btn-outline-secondary">
                                                        <i class="bi bi-check2 me-1" aria-hidden="true"></i>
                                                        Mark read
                                                    </button>
                                                </form>
                                            @endunless
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    @if($notifications->hasPages())
                        <div class="card-footer bg-body">
                            {{ $notifications->links() }}
                        </div>
                    @endif
                @endif
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card shadow-sm">
                <div class="card-header bg-body">
                    <h2 class="h6 mb-0">Delivery preferences</h2>
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('customer-portal.notifications.preferences.update') }}">
                        @csrf

                        <div class="vstack gap-3">
                            @foreach($types as $type => $label)
                                @php
                                    $setting = $settings[$type] ?? null;
                                    $mailEnabled = $setting->mail_enabled ?? true;
                                    $databaseEnabled = $setting->database_enabled ?? true;
                                @endphp

                                <div class="border-bottom pb-3">
                                    <input type="hidden" name="settings[{{ $loop->index }}][notification_type]" value="{{ $type }}">
                                    <div class="fw-semibold small mb-2">{{ $label }}</div>
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="form-check form-switch mb-0">
                                            <input type="checkbox" class="form-check-input" id="portal_mail_{{ $type }}" name="settings[{{ $loop->index }}][mail_enabled]" value="1" @checked($mailEnabled)>
                                            <label class="form-check-label small" for="portal_mail_{{ $type }}">Email</label>
                                        </div>
                                        <div class="form-check form-switch mb-0">
                                            <input type="checkbox" class="form-check-input" id="portal_database_{{ $type }}" name="settings[{{ $loop->index }}][database_enabled]" value="1" @checked($databaseEnabled)>
                                            <label class="form-check-label small" for="portal_database_{{ $type }}">In-app</label>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <button type="submit" class="btn btn-primary w-100 mt-3">
                            <i class="bi bi-check-lg me-1" aria-hidden="true"></i>
                            Save preferences
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
