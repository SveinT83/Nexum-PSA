@extends('layouts.default_tech')

@section('title', 'Notification Preferences')

@section('sidebar')
    @include('usermanagement::profile.partials.sidebar')
@endsection

@section('pageHeader')
    <h1><i class="bi bi-bell me-2"></i>Notification Preferences</h1>
@endsection

@section('content')
<div class="row justify-content-center">
    <div class="col-12 col-xl-10">
        <p class="text-muted mb-4">
            Choose how you want to receive each internal notification. Web Push always requires an
            explicitly registered device and remains off until you enable it for an event.
        </p>

        @include('notification::settings.partials.web-push-devices')

        <form action="{{ route('tech.profile.notifications.update') }}" method="POST">
            @csrf

            @php $settingIndex = 0; @endphp
            @foreach($groups as $groupKey => $group)
                <section class="card mb-3" aria-labelledby="notification-group-{{ $groupKey }}">
                    <div class="card-header py-2">
                        <h2 id="notification-group-{{ $groupKey }}" class="h6 mb-0">{{ $group['label'] }}</h2>
                        <p class="small text-muted mb-0">{{ $group['description'] }}</p>
                    </div>

                    <div class="list-group list-group-flush">
                        @foreach($group['types'] as $type => $definition)
                            @php
                                $s = $settings[$type] ?? null;
                                $channels = $definition['channels'];
                                $pushPolicy = $definition['web_push'];
                                $mailOn = $channels['mail'] && ($s->mail_enabled ?? $definition['defaults']['mail_enabled']);
                                $dbOn = $channels['database'] && ($s->database_enabled ?? $definition['defaults']['database_enabled']);
                                $pushOn = $channels['web_push'] && ($s->web_push_enabled ?? false);
                                $pushPreviewOn = $channels['web_push_preview'] && ($s->web_push_preview_enabled ?? false);
                                $talkOn = $channels['nextcloud_talk'] && ($s->nextcloud_talk_enabled ?? false);
                                $talkUrl = $s->nextcloud_talk_webhook_url ?? '';
                            @endphp

                            <fieldset class="list-group-item px-3 py-3">
                                <legend class="float-none w-auto h6 mb-1">{{ $definition['label'] }}</legend>
                                <p id="notification-description-{{ $type }}" class="small text-muted mb-3">
                                    {{ $definition['description'] }}
                                </p>
                                <input type="hidden" name="settings[{{ $settingIndex }}][notification_type]" value="{{ $type }}">

                                <div class="row g-2 align-items-start" aria-describedby="notification-description-{{ $type }}">
                                    <div class="col-6 col-md-3 col-xl-2">
                                        @if($channels['mail'])
                                            <div class="form-check form-switch">
                                                <input type="checkbox" name="settings[{{ $settingIndex }}][mail_enabled]" value="1"
                                                       class="form-check-input" id="mail_{{ $type }}" {{ $mailOn ? 'checked' : '' }}>
                                                <label class="form-check-label" for="mail_{{ $type }}">Email</label>
                                            </div>
                                        @else
                                            <span class="small text-muted">Email unavailable</span>
                                        @endif
                                    </div>

                                    <div class="col-6 col-md-3 col-xl-2">
                                        @if($channels['database'])
                                            <div class="form-check form-switch">
                                                <input type="checkbox" name="settings[{{ $settingIndex }}][database_enabled]" value="1"
                                                       class="form-check-input" id="db_{{ $type }}" {{ $dbOn ? 'checked' : '' }}>
                                                <label class="form-check-label" for="db_{{ $type }}">In-App</label>
                                            </div>
                                        @else
                                            <span class="small text-muted">In-App unavailable</span>
                                        @endif
                                    </div>

                                    <div class="col-6 col-md-3 col-xl-2">
                                        @if($channels['web_push'])
                                            <div class="form-check form-switch">
                                                <input type="checkbox" name="settings[{{ $settingIndex }}][web_push_enabled]" value="1"
                                                       class="form-check-input" id="push_{{ $type }}" {{ $pushOn ? 'checked' : '' }}>
                                                <label class="form-check-label" for="push_{{ $type }}">Web Push</label>
                                            </div>
                                        @else
                                            <span class="small text-muted d-block">Web Push unavailable</span>
                                            <span class="small text-muted">{{ $pushPolicy['exclusion_reason'] }}</span>
                                        @endif
                                    </div>

                                    <div class="col-6 col-md-3 col-xl-2">
                                        @if($channels['web_push_preview'])
                                            <div class="form-check form-switch">
                                                <input type="checkbox" name="settings[{{ $settingIndex }}][web_push_preview_enabled]" value="1"
                                                       class="form-check-input" id="push_preview_{{ $type }}" {{ $pushPreviewOn ? 'checked' : '' }}>
                                                <label class="form-check-label" for="push_preview_{{ $type }}">Preview</label>
                                            </div>
                                        @else
                                            <span class="small text-muted">Preview unavailable</span>
                                        @endif
                                    </div>

                                    @if($talkEnabled)
                                        <div class="col-6 col-md-3 col-xl-2">
                                            @if($channels['nextcloud_talk'])
                                                <div class="form-check form-switch">
                                                    <input type="checkbox" name="settings[{{ $settingIndex }}][nextcloud_talk_enabled]" value="1"
                                                           class="form-check-input js-talk-toggle" id="talk_{{ $type }}"
                                                           data-webhook-input="talk_url_{{ $type }}" {{ $talkOn ? 'checked' : '' }}>
                                                    <label class="form-check-label" for="talk_{{ $type }}">Nextcloud Talk</label>
                                                </div>
                                            @else
                                                <span class="small text-muted">Talk unavailable</span>
                                            @endif
                                        </div>

                                        @if($channels['nextcloud_talk'])
                                            <div class="col-12 col-xl-4">
                                                <label for="talk_url_{{ $type }}" class="form-label small mb-1">Talk webhook URL</label>
                                                <input type="url" name="settings[{{ $settingIndex }}][nextcloud_talk_webhook_url]"
                                                       id="talk_url_{{ $type }}" class="form-control form-control-sm"
                                                       value="{{ old("settings.{$settingIndex}.nextcloud_talk_webhook_url", $talkUrl) }}"
                                                       placeholder="https://cloud.example.com/apps/webhook/..." {{ $talkOn ? '' : 'disabled' }}>
                                            </div>
                                        @endif
                                    @endif
                                </div>
                            </fieldset>

                            @php $settingIndex++; @endphp
                        @endforeach
                    </div>
                </section>
            @endforeach

            <div class="d-grid d-md-flex justify-content-md-end mt-3">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg me-1"></i>Save Preferences
                </button>
            </div>
        </form>
    </div>
</div>
@endsection

@section('rightbar')
    <h3>Tips</h3>
    <ul class="small text-muted">
        <li>In-app notifications appear in the header bell.</li>
        <li>Web Push is opt-in per device and event.</li>
        <li>Unavailable event channels show the policy reason.</li>
        @if($talkEnabled)
            <li>Nextcloud Talk can use a per-user webhook or the system default.</li>
        @endif
    </ul>
@endsection

@section('scripts')
@parent
<script>
    document.querySelectorAll('.js-talk-toggle').forEach(function (checkbox) {
        checkbox.addEventListener('change', function () {
            const input = document.getElementById(this.dataset.webhookInput);
            if (!input) return;
            input.disabled = !this.checked;
            if (!this.checked) input.value = '';
        });
    });
</script>
@endsection
