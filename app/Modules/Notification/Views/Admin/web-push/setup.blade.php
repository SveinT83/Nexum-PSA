@extends('layouts.default_tech')

@section('title', 'Web Push Setup')

@section('pageHeader')
    <div class="col"><h1 class="h4 mb-0">Web Push Setup</h1></div>
    <div class="col-auto"><x-buttons.back url="{{ route('tech.admin.notification-channels.index') }}" class="mb-0">Back</x-buttons.back></div>
@endsection

@section('content')
@php
    $identity = $setup['identity'];
    $supported = $identity['installation_supported'] && $setup['database_supported'];
    $pending = $identity['registration_reset_required'];
    $labels = ['verified' => 'Verified', 'healthy' => 'Healthy', 'pending' => 'Waiting for worker',
        'unknown' => 'Unknown', 'blocked' => 'Blocked', 'stale' => 'Stale', 'not_verified' => 'Not verified',
        'insecure_origin' => 'HTTPS required', 'unavailable' => 'Unavailable'];
    $installationMessages = [
        'installation_not_provisioned' => 'The installation owner must provision protected setup support and verify the complete web and worker rollout.',
        'installation_platform_unsupported' => 'Managed setup is unavailable on this installation platform. Existing deployment-owned configuration remains separate.',
        'private_store_ownership_or_permissions' => 'The installation owner must verify protected storage ownership and permissions.',
        'installation_attestation_expired' => 'The installation review has expired. Ask its owner to renew it after checking the runtime.',
        'installation_attestation_mismatch' => 'The installation identity or runtime contract needs an owner review.',
        'managed_setup_forbidden' => 'The installation owner has disabled managed setup.',
    ];
@endphp
<div class="row justify-content-center"><div class="col-xl-9">
    {{-- Installation requirements and honest delivery boundary --}}
    <p class="text-muted">Configure a stable push identity, verify readiness, then enable Web Push. Device registration and event preferences remain each user's choice.</p>
    @if($errors->any())
        <div class="alert alert-danger" role="alert"><ul class="mb-0">@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></div>
    @endif
    @unless($identity['installation_supported'])
        <div class="alert alert-warning" role="status">
            {{ $installationMessages[$identity['installation_reason']] ?? 'The installation setup could not be verified. Ask its owner to reconcile the protected configuration.' }}
            <div class="small mt-1">Use the managed Web Push deployment runbook. This page never accepts private keys or edits deployment environment files.</div>
        </div>
    @endunless
    @unless($setup['database_supported'])
        <div class="alert alert-warning">The installation owner must apply the Web Push registration synchronization update and verify the subscription database before managed setup.</div>
    @endunless
    @if($identity['force_disabled'])
        <div class="alert alert-warning">The installation's emergency switch blocks Web Push. Activation remains unavailable until its owner resolves it.</div>
    @endif
    @if($pending)
        <div class="alert alert-danger">An identity replacement is incomplete. Delivery remains disabled. Resume the recorded operation below or ask the installation owner to reconcile it.</div>
    @endif

    {{-- Readiness is observed, never inferred from a queue acceptance --}}
    <section class="card shadow-sm mb-4">
        <div class="card-header d-flex justify-content-between align-items-center"><h2 class="h6 mb-0">1. Readiness</h2>
            <a class="btn btn-sm btn-outline-secondary" href="{{ route('tech.admin.notification-channels.web-push.setup.index') }}">Refresh status</a></div>
        <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-sm-5">Installation support</dt><dd class="col-sm-7">{{ $supported ? 'Verified' : 'Owner setup required' }}</dd>
                <dt class="col-sm-5">Push identity</dt><dd class="col-sm-7">{{ $identity['identity_valid'] ? 'Valid stable identity' : 'Not configured or needs owner review' }}</dd>
                <dt class="col-sm-5">HTTPS and service worker</dt><dd class="col-sm-7">{{ $labels[$setup['service_worker']] ?? 'Not verified' }}</dd>
                <dt class="col-sm-5">Background queue</dt><dd class="col-sm-7">{{ $labels[$setup['queue']['state']] ?? 'Unknown' }}</dd>
                <dt class="col-sm-5">Worker identity read-back</dt><dd class="col-sm-7">{{ $labels[$setup['worker']] ?? 'Not verified' }}</dd>
                <dt class="col-sm-5">Global switch</dt><dd class="col-sm-7">{{ $identity['state'] === 'unavailable' ? 'Unavailable; delivery blocked' : ($identity['enabled'] ? 'On' : 'Off') }}</dd>
            </dl>
            @if($setup['queue']['state'] !== 'healthy')
                <div class="alert alert-warning mt-3">
                    @if($setup['queue']['reason_code'] === 'runtime_health_integration_unavailable')
                        The System runtime-health integration is not installed. The installation owner must deploy it and verify the approved default queue before activation.
                    @elseif($setup['queue']['reason_code'] === 'runtime_health_not_enabled')
                        Runtime-health checks are not enabled by the installation owner. Activation requires fresh evidence for the approved default queue.
                    @elseif($setup['queue']['reason_code'] === 'default_queue_not_approved')
                        The default notification queue is outside the approved worker scope. Ask the installation owner to reconcile its exact scope before continuing.
                    @else
                        The default notification queue does not have fresh healthy evidence. Ask the installation owner to inspect its existing worker; this page does not start workers or release queues.
                    @endif
                </div>
            @endif
            <p class="small text-muted mb-0">A worker check sends no notification. It verifies the exact installed runtime and push identity; browser receipt is checked separately after registration.</p>
            @if($setup['checked_at'])<p class="small text-muted mb-0 mt-1">Last check: {{ $setup['checked_at'] }}</p>@endif
        </div>
    </section>

    {{-- Ordinary setup creates or adopts a stable identity without revealing keys --}}
    @unless($identity['managed'])
        <section class="card shadow-sm mb-4"><div class="card-header"><h2 class="h6 mb-0">2. Configure identity</h2></div>
            <div class="card-body">
                @if($identity['enabled'])<div class="alert alert-warning">Web Push is currently enabled through deployment settings. Configuring managed setup pauses delivery until you verify and explicitly enable it.</div>@endif
                <p class="small">Use a stable service contact such as <code>mailto:admin@example.com</code> or an HTTPS contact page. Existing deployment keys are retained; incomplete keys need an installation owner review.</p>
                <form method="POST" action="{{ route('tech.admin.notification-channels.web-push.setup.initialize') }}">
                    @csrf
                    <input type="hidden" name="revision" value="{{ $identity['revision'] }}">
                    <input type="hidden" name="request_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                    <input type="hidden" name="confirm" value="1">
                    <label for="pushSubject" class="form-label">Stable contact subject</label>
                    <input id="pushSubject" name="subject" class="form-control mb-3" maxlength="512" required
                        value="{{ old('subject', $identity['subject']) }}" autocomplete="off" @disabled(! $supported)>
                    <button type="submit" class="btn btn-primary" @disabled(! $supported)>Configure with delivery disabled</button>
                </form>
            </div>
        </section>
    @else
        <section class="card shadow-sm mb-4"><div class="card-header"><h2 class="h6 mb-0">2. Verify and activate</h2></div>
            <div class="card-body">
                <p class="small mb-1">Contact: {{ $identity['subject'] ?? 'Needs owner review' }}</p>
                <p class="small text-muted">{{ $identity['source'] === 'environment' ? 'Existing installation-owned keys are in use.' : 'Nexum manages the protected installation identity.' }}</p>
                <div class="d-flex flex-wrap gap-2">
                    <form method="POST" action="{{ route('tech.admin.notification-channels.web-push.setup.check') }}">
                        @csrf
                        <input type="hidden" name="revision" value="{{ $identity['revision'] }}">
                        <input type="hidden" name="request_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                        <input type="hidden" name="confirm" value="1">
                        <button type="submit" class="btn btn-outline-primary" @disabled(! $supported || ! $identity['identity_valid'] || $setup['queue']['state'] !== 'healthy' || $pending)>Run readiness check</button>
                    </form>
                    <form method="POST" action="{{ route('tech.admin.notification-channels.web-push.setup.activation') }}">
                        @csrf @method('PATCH')
                        <input type="hidden" name="revision" value="{{ $identity['revision'] }}">
                        <input type="hidden" name="request_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                        @if($identity['stored_enabled'])
                            <input type="hidden" name="confirm" value="1">
                        @else
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" name="confirm" value="1" id="confirm-webpush-enable" required>
                                <label class="form-check-label" for="confirm-webpush-enable">I confirm enabling Web Push delivery for this installation. Browser permission and event preferences stay separate.</label>
                            </div>
                        @endif
                        <input type="hidden" name="enabled" value="{{ $identity['stored_enabled'] ? '0' : '1' }}">
                        <button type="submit" class="btn {{ $identity['stored_enabled'] ? 'btn-outline-danger' : 'btn-success' }}"
                            @disabled(! $supported || (! $identity['stored_enabled'] && ! $setup['ready_to_enable']))>
                            {{ $identity['stored_enabled'] ? 'Disable Web Push' : 'Enable Web Push' }}
                        </button>
                    </form>
                </div>
                <p class="form-text mb-0">Enabling the global switch does not grant browser permission or enable business events for anyone.</p>
            </div>
        </section>
    @endunless

    {{-- Personal device consent and separately controlled delivery test --}}
    <section class="card shadow-sm mb-4"><div class="card-header"><h2 class="h6 mb-0">3. Register and test your device</h2></div>
        <div class="card-body"><p class="small">After activation, open your own Notifications page, choose Enable on this device and allow browser notifications. Then explicitly send a test to that device and verify its receipt.</p>
            <a class="btn btn-sm btn-outline-primary" href="{{ route('tech.profile.notifications') }}">Open my notification preferences</a>
            <a class="btn btn-sm btn-outline-secondary" href="{{ route('tech.admin.notification-channels.web-push.devices.index') }}">Manage device registrations</a>
        </div>
    </section>

    {{-- Identity replacement is separate, disable-first and strongly confirmed --}}
    @if($identity['managed'] && $identity['source'] === 'managed')
        <details class="card shadow-sm mb-4"><summary class="card-header">Replace or restore identity</summary>
            <div class="card-body">
                <div class="alert alert-warning">These actions disable delivery and revoke existing device registrations. Every device must subscribe again after verification and activation. Encrypted identity history is retained.</div>
                @if(! $pending || $identity['pending_operation_kind'] === 'rotation')
                    <form method="POST" class="mb-3" action="{{ route('tech.admin.notification-channels.web-push.setup.rotate') }}">
                        @csrf
                        <input type="hidden" name="revision" value="{{ $identity['revision'] }}">
                        <input type="hidden" name="request_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                        <input type="hidden" name="confirm" value="1">
                        <label class="form-label" for="rotateConfirmation">Type ROTATE WEB PUSH</label>
                        <input id="rotateConfirmation" name="confirmation" class="form-control mb-2" autocomplete="off" required @disabled(! $supported)>
                        <button type="submit" class="btn btn-outline-danger" @disabled(! $supported)>{{ $pending ? 'Resume recorded rotation' : 'Rotate identity with delivery disabled' }}</button>
                    </form>
                @endif
                @php $restore = $identity['pending_operation_kind'] === 'rollback' ? $identity['pending_target_revision'] : $identity['previous_identity_revision']; @endphp
                @if($restore && (! $pending || $identity['pending_operation_kind'] === 'rollback'))
                    <form method="POST" action="{{ route('tech.admin.notification-channels.web-push.setup.rollback') }}">
                        @csrf
                        <input type="hidden" name="revision" value="{{ $identity['revision'] }}">
                        <input type="hidden" name="archive_revision" value="{{ $restore }}">
                        <input type="hidden" name="request_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                        <input type="hidden" name="confirm" value="1">
                        <label class="form-label" for="restoreConfirmation">Type ROLL BACK WEB PUSH</label>
                        <input id="restoreConfirmation" name="confirmation" class="form-control mb-2" autocomplete="off" required @disabled(! $supported)>
                        <button type="submit" class="btn btn-outline-danger" @disabled(! $supported)>{{ $pending ? 'Resume recorded restoration' : 'Restore previous identity with delivery disabled' }}</button>
                    </form>
                @endif
            </div>
        </details>
    @endif
</div></div>
@endsection
