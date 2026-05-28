@extends('layouts.default_tech')

@section('title', 'Notification Channels')

@section('pageHeader')
    <h1><i class="bi bi-broadcast me-2"></i>Notification Channels</h1>
@endsection

@section('content')
<div class="row justify-content-center">
    <div class="col-md-8">
        <p class="text-muted mb-4">
            Configure system-wide notification channels. Users can then choose which channels
            to use for each notification type in their personal preferences.
        </p>

        <div class="list-group">
            @foreach($channels as $channel)
                <div class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="mb-1">
                            @if($channel->driver === 'nextcloud_talk')
                                <i class="bi bi-chat-dots me-2"></i>
                            @else
                                <i class="bi bi-broadcast me-2"></i>
                            @endif
                            {{ $channel->label }}
                        </h5>
                        <p class="mb-1 text-muted small">Driver: {{ $channel->driver }}</p>
                    </div>
                    <div class="d-flex align-items-center gap-3">
                        @if($channel->is_enabled)
                            <span class="badge bg-success">Enabled</span>
                        @else
                            <span class="badge bg-secondary">Disabled</span>
                        @endif

                        @if($channel->last_tested_at)
                            <span class="small text-muted">
                                Last tested: {{ $channel->last_tested_at->diffForHumans() }}
                                @if($channel->last_test_result === 'OK')
                                    <i class="bi bi-check-circle-fill text-success"></i>
                                @else
                                    <i class="bi bi-x-circle-fill text-danger" title="{{ $channel->last_test_result }}"></i>
                                @endif
                            </span>
                        @endif

                        <a href="{{ route('tech.admin.notification-channels.edit', $channel) }}"
                           class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-gear me-1"></i> Configure
                        </a>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</div>
@endsection

@section('sidebar')
    <x-nav.admin-menu group="system" />
@endsection
