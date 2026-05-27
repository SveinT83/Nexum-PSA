@extends('layouts.default_tech')

@section('title', 'Notification Preferences')

@section('pageHeader')
    <h1><i class="bi bi-bell me-2"></i>Notification Preferences</h1>
@endsection

@section('content')
<div class="row justify-content-center">
    <div class="col-md-10">
        @if(session('success'))
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                {{ session('success') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif

        <p class="text-muted mb-4">
            Choose how you want to receive notifications for each event type.
            In-app notifications appear in the bell icon at the top of the page.
        </p>

        <form action="{{ route('tech.profile.notifications.update') }}" method="POST">
            @csrf

            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Event</th>
                            <th class="text-center"><i class="bi bi-envelope me-1"></i> Email</th>
                            <th class="text-center"><i class="bi bi-app-indicator me-1"></i> In-App</th>
                            @if($talkEnabled)
                                <th class="text-center"><i class="bi bi-chat-dots me-1"></i> Nextcloud Talk</th>
                                <th>Talk Webhook URL</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($types as $type => $label)
                            @php
                                $s = $settings[$type] ?? null;
                                $mailOn = $s->mail_enabled ?? true;
                                $dbOn = $s->database_enabled ?? true;
                                $talkOn = $s->nextcloud_talk_enabled ?? false;
                                $talkUrl = $s->nextcloud_talk_webhook_url ?? '';
                            @endphp
                            <tr>
                                <td>
                                    <strong>{{ $label }}</strong>
                                    <input type="hidden" name="settings[{{ $loop->index }}][notification_type]" value="{{ $type }}">
                                </td>
                                <td class="text-center">
                                    <div class="form-check form-switch d-inline-block">
                                        <input type="checkbox" name="settings[{{ $loop->index }}][mail_enabled]" value="1"
                                               class="form-check-input" id="mail_{{ $type }}"
                                               {{ $mailOn ? 'checked' : '' }}>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <div class="form-check form-switch d-inline-block">
                                        <input type="checkbox" name="settings[{{ $loop->index }}][database_enabled]" value="1"
                                               class="form-check-input" id="db_{{ $type }}"
                                               {{ $dbOn ? 'checked' : '' }}>
                                    </div>
                                </td>
                                @if($talkEnabled)
                                    <td class="text-center">
                                        <div class="form-check form-switch d-inline-block">
                                            <input type="checkbox" name="settings[{{ $loop->index }}][nextcloud_talk_enabled]" value="1"
                                                   class="form-check-input" id="talk_{{ $type }}"
                                                   {{ $talkOn ? 'checked' : '' }}>
                                        </div>
                                    </td>
                                    <td>
                                        <input type="url" name="settings[{{ $loop->index }}][nextcloud_talk_webhook_url]"
                                               class="form-control form-control-sm"
                                               value="{{ old("settings.{$loop->index}.nextcloud_talk_webhook_url", $talkUrl) }}"
                                               placeholder="https://cloud.example.com/apps/webhook/..."
                                               @if(!$talkOn) disabled @endif>
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="d-grid d-md-flex justify-content-md-end mt-3">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg me-1"></i> Save Preferences
                </button>
            </div>
        </form>
    </div>
</div>
@endsection

@section('sidebar')
    @if(isset($sidebarMenuItems))
        <x-nav.side-bar :items="$sidebarMenuItems" />
    @endif
@endsection

@section('rightbar')
    <h3>Tips</h3>
    <ul class="small text-muted">
        <li>In-app notifications appear instantly in the header bell icon.</li>
        <li>Email notifications are sent immediately for high-priority events.</li>
        @if($talkEnabled)
            <li>Nextcloud Talk messages are sent via webhook — you can use a per-user URL or the system default.</li>
        @endif
    </ul>
@endsection

@push('scripts')
<script>
    // Enable/disable webhook URL field based on Talk toggle
    document.querySelectorAll('input[name*="nextcloud_talk_enabled"]').forEach(function(checkbox) {
        checkbox.addEventListener('change', function() {
            const row = this.closest('tr');
            const urlInput = row.querySelector('input[type="url"]');
            if (urlInput) {
                urlInput.disabled = !this.checked;
                if (!this.checked) urlInput.value = '';
            }
        });
    });
</script>
@endpush