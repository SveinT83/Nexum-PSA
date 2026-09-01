@extends('layouts.default_tech')

@section('title', 'Manage Email provider')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center gap-3">
        <h1 class="h4 mb-0">{{ $connection->integration?->name ?? 'Email provider' }}</h1>
        <a class="btn btn-outline-secondary" href="{{ route('tech.admin.system.integrations.email-providers.index') }}">Close</a>
    </div>
@endsection

@section('content')
    @php($stagedCredential = $connection->credentialVersions->firstWhere('state', 'staged'))
    @if(session('status'))<div class="alert alert-success" role="status">{{ session('status') }}</div>@endif
    @if(session('error'))<div class="alert alert-danger" role="alert">{{ session('error') }}</div>@endif

    <!-- Provider identity and endpoint changes require a fresh binding by design. -->
    <div class="alert alert-info d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3" role="note">
        <div>
            <div class="fw-semibold">Connection details are stored securely and are never shown again.</div>
            <div class="small">
                @if($connection->activeCredentialVersion)
                    Enter new passwords below to rotate secrets. To change a username, IMAP server, SMTP server, port or TLS mode, create a replacement provider and then rebind the Email account.
                @elseif($stagedCredential)
                    This provider already has a saved staged configuration and credential version. Verify version v{{ $stagedCredential->version }} below. If any username, password, server, port or TLS setting may be wrong, create a replacement provider and enter all details again.
                @else
                    This provider has no usable credential version. Create a replacement provider and enter all connection details.
                @endif
            </div>
        </div>
        <div class="d-flex flex-wrap gap-2 flex-shrink-0">
            @if($stagedCredential)
                <a class="btn btn-sm btn-outline-primary" href="#credential-versions">Go to verification</a>
            @endif
            <a class="btn btn-sm btn-primary" href="{{ route('tech.admin.system.integrations.email-providers.create') }}">Create replacement provider</a>
        </div>
    </div>

    <div class="row g-4">
        <!-- Safe connection state -->
        <div class="col-lg-4"><div class="card shadow-sm h-100"><div class="card-header"><h2 class="h6 mb-0">Connection state</h2></div><div class="card-body">
            <dl class="row small mb-0">
                <dt class="col-6">Lifecycle</dt><dd class="col-6">{{ ucfirst($connection->status) }}</dd>
                <dt class="col-6">Configuration</dt><dd class="col-6">v{{ $connection->configuration_version }}</dd>
                <dt class="col-6">Runtime</dt><dd class="col-6"><span class="badge text-bg-{{ $isRuntimeReady ? 'success' : 'secondary' }}">{{ $isRuntimeReady ? 'Ready' : 'Not ready' }}</span></dd>
                <dt class="col-6">IMAP</dt><dd class="col-6">{{ data_get($connection->capabilities, 'imap') ? 'Verified' : 'Pending' }}</dd>
                <dt class="col-6">SMTP</dt><dd class="col-6">{{ data_get($connection->capabilities, 'smtp') ? 'Verified' : 'Pending' }}</dd>
                <dt class="col-6">Last verification</dt><dd class="col-6">{{ $connection->last_verified_at?->format('Y-m-d H:i') ?? 'Never' }}</dd>
            </dl>
        </div></div></div>

        <!-- Secret rotation never renders usernames or existing ciphertext -->
        <div class="col-lg-8"><div class="card shadow-sm h-100"><div class="card-header"><h2 class="h6 mb-0">Rotate secrets</h2></div><div class="card-body">
            @if($connection->activeCredentialVersion)
                <p class="small text-body-secondary">Username identity is preserved. A username or endpoint change requires a new connection and explicit rebind/rebaseline.</p>
                <form method="POST" action="{{ route('tech.admin.system.integrations.email-providers.credentials.stage', $connection->getKey()) }}" class="row g-3" autocomplete="off">
                    @csrf
                    <div class="col-md-6"><label for="imap_secret" class="form-label">New IMAP password</label><input type="password" id="imap_secret" name="imap_secret" class="form-control" required autocomplete="new-password"></div>
                    <div class="col-md-6"><label for="smtp_secret" class="form-label">New SMTP password</label><input type="password" id="smtp_secret" name="smtp_secret" class="form-control" required autocomplete="new-password"></div>
                    <div class="col-12"><button class="btn btn-outline-primary" type="submit">Stage rotation</button></div>
                </form>
            @else
                <p class="text-body-secondary mb-0">Secret rotation becomes available after the initial staged version is verified and activated.</p>
            @endif
        </div></div></div>
    </div>

    <!-- Mailbox bindings are operational metadata, not provider credentials. -->
    <div class="card shadow-sm mt-4">
        <div class="card-header d-flex justify-content-between align-items-center gap-2">
            <h2 class="h6 mb-0">Bound Email accounts</h2>
            <a class="btn btn-sm btn-outline-secondary" href="{{ route('tech.admin.settings.email.accounts') }}">Manage accounts</a>
        </div>
        <div class="list-group list-group-flush">
            @forelse($connection->emailAccounts as $account)
                <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center gap-3" href="{{ route('tech.admin.settings.email.accounts.edit', $account) }}">
                    <span><span class="fw-semibold">{{ $account->address }}</span><span class="d-block small text-body-secondary">Mailbox settings, defaults, Ticket ingress and access</span></span>
                    <span class="badge text-bg-{{ $account->is_active ? 'success' : 'secondary' }}">{{ $account->is_active ? 'Active' : 'Disabled' }}</span>
                </a>
            @empty
                <div class="list-group-item text-body-secondary">No mailbox is bound to this provider yet. Activate a verified credential version, then add or rebind an Email account.</div>
            @endforelse
        </div>
    </div>

    <!-- Append-only version lifecycle -->
    <div class="card shadow-sm mt-4" id="credential-versions"><div class="card-header"><h2 class="h6 mb-0">Credential versions</h2></div><div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <thead class="table-light"><tr><th>Version</th><th>State</th><th>Verification</th><th>Staged</th><th class="text-end">Actions</th></tr></thead>
        <tbody>
            @foreach($connection->credentialVersions as $credential)
                <tr>
                    <td>v{{ $credential->version }}</td><td><span class="badge text-bg-light border">{{ ucfirst($credential->state) }}</span></td>
                    <td>{{ $credential->verified_at ? 'Verified '.$credential->verified_at->format('Y-m-d H:i') : 'Not verified' }}</td><td>{{ $credential->staged_at?->format('Y-m-d H:i') }}</td>
                    <td class="text-end">
                        @if($credential->state === 'staged')
                            <form class="d-inline" method="POST" action="{{ route('tech.admin.system.integrations.email-providers.credentials.verify', [$connection->getKey(), $credential->version]) }}">@csrf<button class="btn btn-sm btn-outline-primary" type="submit">Verify</button></form>
                            @if($credential->verified_at)<form class="d-inline" method="POST" action="{{ route('tech.admin.system.integrations.email-providers.credentials.activate', [$connection->getKey(), $credential->version]) }}">@csrf<button class="btn btn-sm btn-primary" type="submit">Activate</button></form>@endif
                        @endif
                        @if(in_array($credential->state, ['staged', 'active'], true))
                            <form class="d-inline" method="POST" action="{{ route('tech.admin.system.integrations.email-providers.credentials.revoke', [$connection->getKey(), $credential->version]) }}">@csrf<input type="hidden" name="reason_code" value="operator_revoked"><button class="btn btn-sm btn-outline-danger" type="submit">Revoke</button></form>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table></div></div>
@endsection

@section('sidebar')
    <x-nav.admin-menu group="integrations" />
@endsection

