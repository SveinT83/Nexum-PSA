@extends('layouts.default_tech')

@section('title', 'Email providers')

@section('pageHeader')
    <div class="d-flex align-items-center justify-content-between gap-3">
        <div>
            <h1 class="h4 mb-1">Email providers</h1>
            <p class="text-body-secondary mb-0">Integration-owned IMAP/SMTP credentials and verified lifecycle state.</p>
        </div>
        <a href="{{ route('tech.admin.system.integrations.email-providers.create') }}" class="btn btn-primary">
            <i class="bi bi-plus-lg" aria-hidden="true"></i> New provider
        </a>
    </div>
@endsection

@section('content')
    @if(session('status'))
        <div class="alert alert-success" role="status">{{ session('status') }}</div>
    @endif

    @if(session('error'))
        <div class="alert alert-danger" role="alert">{{ session('error') }}</div>
    @endif

    <!-- Explain the provider/account boundary before lifecycle actions. -->
    <div class="alert alert-info" role="status">
        <div class="fw-semibold mb-1">How Email configuration is split</div>
        <div class="small">
            A provider stores the protected IMAP/SMTP connection and credential lifecycle. An Email account stores the mailbox address, defaults, Ticket ingress and user access, then binds to one active verified provider.
            Create or migrate the provider first, verify and activate it, and then manage the mailbox under
            <a class="alert-link" href="{{ route('tech.admin.settings.email.accounts') }}">Email Accounts</a>.
            Endpoint or username changes use a new verified provider and an explicit replacement cutover; stored connection values are never shown again.
        </div>
    </div>

    <!-- Provider lifecycle records -->
    <div class="card shadow-sm mb-4">
        <div class="card-header"><h2 class="h6 mb-0">Provider connections</h2></div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr><th>Label</th><th>Status</th><th>Verified version</th><th>Capabilities</th><th>Mailboxes</th><th class="text-end">Action</th></tr>
                </thead>
                <tbody>
                    @forelse($connections as $connection)
                        <tr>
                            <td>
                                <div class="fw-semibold">{{ $connection->integration?->name ?? 'Email provider' }}</div>
                                <div class="small text-body-secondary">Configuration v{{ $connection->configuration_version }}</div>
                            </td>
                            <td><span class="badge text-bg-{{ $connection->status === 'active' ? 'success' : ($connection->status === 'revoked' ? 'danger' : 'secondary') }}">{{ ucfirst($connection->status) }}</span></td>
                            <td>
                                @if($connection->activeCredentialVersion)
                                    v{{ $connection->activeCredentialVersion->version }} · {{ ucfirst($connection->activeCredentialVersion->state) }}
                                @else
                                    <span class="text-body-secondary">Not active</span>
                                @endif
                            </td>
                            <td class="small">
                                IMAP {{ data_get($connection->capabilities, 'imap') ? 'ready' : 'pending' }} ·
                                SMTP {{ data_get($connection->capabilities, 'smtp') ? 'ready' : 'pending' }}
                            </td>
                            <td class="small">{{ $connection->email_accounts_count }} bound</td>
                            <td class="text-end"><a href="{{ route('tech.admin.system.integrations.email-providers.show', $connection->getKey()) }}" class="btn btn-sm btn-outline-primary">Manage</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-body-secondary py-4">No Integration-owned Email providers yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- Legacy migration preview -->
    <div class="card shadow-sm mb-4">
        <div class="card-header"><h2 class="h6 mb-0">Legacy mailbox migration</h2></div>
        <div class="card-body">
            <p class="small text-body-secondary mb-2">Preview is the first safety step for accounts that still contain legacy connection evidence. It makes no DNS or provider call and changes nothing.</p>
            <ol class="small text-body-secondary mb-3">
                <li>Preview the selected mailbox.</li>
                <li>Stage its legacy settings, or choose a separately verified replacement if the old configuration is blocked.</li>
                <li>Verify and activate the provider, pause and drain the mailbox, preview cutover, then apply it.</li>
            </ol>
            <form method="POST" action="{{ route('tech.admin.system.integrations.email-providers.migrations.preview') }}">
                @csrf
                <div class="vstack gap-2 mb-3">
                    @forelse($legacyAccounts as $account)
                        <label class="border rounded p-2 d-flex align-items-center gap-2">
                            <input class="form-check-input mt-0" type="checkbox" name="account_ids[]" value="{{ $account->id }}">
                            <span><span class="fw-semibold">{{ $account->address }}</span>@if($account->description)<span class="text-body-secondary"> · {{ $account->description }}</span>@endif</span>
                            <span class="badge text-bg-{{ $account->is_active ? 'success' : 'secondary' }} ms-auto">{{ $account->is_active ? 'Active' : 'Disabled' }}</span>
                        </label>
                    @empty
                        <div class="text-body-secondary">No legacy mailbox accounts remain.</div>
                    @endforelse
                </div>
                @if($legacyAccounts->isNotEmpty())
                    <button class="btn btn-outline-primary" type="submit">Create migration preview</button>
                @endif
            </form>
        </div>
    </div>

    <!-- Durable migration history -->
    <div class="card shadow-sm">
        <div class="card-header"><h2 class="h6 mb-0">Migration and cutover history</h2></div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light"><tr><th>Operation</th><th>Status</th><th>Scope</th><th>Created</th><th class="text-end">Action</th></tr></thead>
                <tbody>
                    @forelse($runs as $run)
                        <tr>
                            <td>{{ str($run->operation)->replace('_', ' ')->title() }}</td>
                            <td><span class="badge text-bg-light border">{{ ucfirst($run->status) }}</span></td>
                            <td>{{ $run->account_count }} account{{ $run->account_count === 1 ? '' : 's' }} · {{ $run->blocked_count }} blocked</td>
                            <td>{{ $run->created_at?->format('Y-m-d H:i') }}</td>
                            <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="{{ route('tech.admin.system.integrations.email-providers.migrations.show', $run->public_id) }}">Open</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-body-secondary py-4">No migration runs.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection

@section('sidebar')
    <x-nav.admin-menu group="integrations" />
@endsection

