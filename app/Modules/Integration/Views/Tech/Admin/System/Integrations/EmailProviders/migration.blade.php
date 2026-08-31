@extends('layouts.default_tech')

@section('title', 'Email provider migration')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center gap-3">
        <div><h1 class="h4 mb-1">{{ str($run->operation)->replace('_', ' ')->title() }}</h1><p class="text-body-secondary mb-0">Durable exact-scope run · {{ ucfirst($run->status) }}</p></div>
        <a class="btn btn-outline-secondary" href="{{ route('tech.admin.system.integrations.email-providers.index') }}">Close</a>
    </div>
@endsection

@section('content')
    @if(session('status'))<div class="alert alert-success" role="status">{{ session('status') }}</div>@endif
    @if(session('error'))<div class="alert alert-danger" role="alert">{{ session('error') }}</div>@endif
    <div class="card shadow-sm mb-4"><div class="card-body d-flex flex-wrap gap-3">
        <span><strong>Accounts:</strong> {{ $run->account_count }}</span><span><strong>Ready:</strong> {{ $run->ready_count }}</span><span><strong>Blocked:</strong> {{ $run->blocked_count }}</span>
        @if($run->preview_expires_at)<span><strong>Preview expires:</strong> {{ $run->preview_expires_at->format('Y-m-d H:i') }}</span>@endif
        @if($run->rollback_deadline_at)<span><strong>Rollback deadline:</strong> {{ $run->rollback_deadline_at->format('Y-m-d H:i') }}</span>@endif
    </div></div>

    @if($run->operation === 'legacy_migration' && $run->status === 'previewed')
        <!-- Per-account trust is explicit and is re-authorized by the action -->
        <form method="POST" action="{{ route('tech.admin.system.integrations.email-providers.migrations.stage', $run->public_id) }}">
            @csrf
            <div class="card shadow-sm mb-4"><div class="card-header"><h2 class="h6 mb-0">Stage exact preview</h2></div><div class="card-body">
                @foreach($run->items as $item)
                    <div class="border rounded p-3 mb-3">
                        <div class="fw-semibold mb-2">{{ $item->account?->address ?? 'Unavailable account' }}</div>
                        <select class="form-select mb-2" name="trust[{{ $item->email_account_id }}][trust_mode]">
                            <option value="public">Public endpoints only</option>
                            @if($canManagePrivate)<option value="trusted_private">Approved named private CIDR</option>@endif
                        </select>
                        @if($canManagePrivate)
                            <select class="form-select mb-2" name="trust[{{ $item->email_account_id }}][trusted_cidr_name]"><option value="">Named CIDR (private only)</option>@foreach($trustedCidrNames as $cidrName)<option value="{{ $cidrName }}">{{ $cidrName }}</option>@endforeach</select>
                            <textarea class="form-control" name="trust[{{ $item->email_account_id }}][private_endpoint_reason]" maxlength="1000" rows="2" placeholder="Approval reason for private endpoint"></textarea>
                        @endif
                    </div>
                @endforeach
                <button class="btn btn-primary" type="submit" @disabled($run->blocked_count > 0)>Stage locally</button>
            </div></div>
        </form>
    @endif

    <!-- Item status and bounded operator actions -->
    <div class="card shadow-sm mb-4"><div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <thead class="table-light"><tr><th>Mailbox</th><th>Status</th><th>Readiness</th><th class="text-end">Actions</th></tr></thead>
        <tbody>
            @foreach($run->items as $item)
                <tr>
                    <td>{{ $item->account?->address ?? 'Unavailable account' }}</td>
                    <td><span class="badge text-bg-light border">{{ ucfirst($item->status) }}</span></td>
                    <td class="small">{{ $item->block_code ? str($item->block_code)->replace('_', ' ') : 'Ready' }}</td>
                    <td class="text-end">
                        @if($run->operation === 'legacy_migration' && $item->status === 'staged')
                            <form class="d-inline" method="POST" action="{{ route('tech.admin.system.integrations.email-providers.migrations.items.verify', [$run->public_id, $item->id]) }}">@csrf<button class="btn btn-sm btn-outline-primary" type="submit">Verify</button></form>
                        @endif
                        @if($run->operation === 'legacy_migration' && $item->status === 'verified')
                            <form class="d-inline" method="POST" action="{{ route('tech.admin.system.integrations.email-providers.migrations.items.activate', [$run->public_id, $item->id]) }}">@csrf<button class="btn btn-sm btn-primary" type="submit">Activate</button></form>
                        @endif
                        @if(in_array($item->status, ['active', 'ready', 'cutover'], true) && $item->account)
                            @if(!$item->account->provider_runtime_paused_at)
                                <form class="d-inline" method="POST" action="{{ route('tech.admin.system.integrations.email-providers.migrations.items.pause', [$run->public_id, $item->id]) }}">@csrf<input type="hidden" name="reason_code" value="provider_cutover"><button class="btn btn-sm btn-outline-warning" type="submit">Pause & drain</button></form>
                            @else
                                <form class="d-inline" method="POST" action="{{ route('tech.admin.system.integrations.email-providers.migrations.items.resume', [$run->public_id, $item->id]) }}">@csrf<button class="btn btn-sm btn-outline-success" type="submit">Resume</button></form>
                            @endif
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table></div></div>

    <!-- A blocked legacy configuration needs an explicit verified replacement, not an in-place secret edit. -->
    @foreach($run->items->where('status', 'blocked') as $blockedItem)
        @continue(! in_array($blockedItem->block_code, ['legacy_material_incomplete', 'legacy_configuration_not_supported'], true))
        @php($blockedAccount = $blockedItem->account)
        <div class="card border-warning shadow-sm mb-4">
            <div class="card-header"><h2 class="h6 mb-0">Restore {{ $blockedAccount?->address ?? 'blocked mailbox' }} with a verified provider</h2></div>
            <div class="card-body">
                <p class="mb-2">
                    The saved legacy connection cannot be migrated safely.
                    @if($blockedItem->block_code === 'legacy_material_incomplete')
                        Required legacy connection fields are missing.
                    @else
                        Its old port, transport, or authentication combination is outside the current security policy.
                    @endif
                </p>
                <p class="small text-body-secondary">
                    Create and verify a replacement under Email providers, then bind it here. The mailbox must be disabled and its provider work paused and drained. The binding change is local-only and retains the legacy evidence for a seven-day rollback window.
                </p>

                @if(!$blockedAccount)
                    <div class="alert alert-danger mb-0" role="alert">The mailbox no longer exists.</div>
                @elseif($blockedAccount->is_active)
                    <div class="alert alert-warning d-flex flex-wrap justify-content-between align-items-center gap-2 mb-0" role="alert">
                        <span>Deactivate this mailbox before replacing its provider.</span>
                        <a class="btn btn-sm btn-outline-dark" href="{{ route('tech.admin.settings.email.accounts.edit', $blockedAccount) }}">Open Email account</a>
                    </div>
                @elseif(!$blockedAccount->provider_runtime_paused_at || !$blockedAccount->provider_runtime_drained_at)
                    <form method="POST" action="{{ route('tech.admin.system.integrations.email-providers.migrations.items.pause', [$run->public_id, $blockedItem->id]) }}">
                        @csrf
                        <input type="hidden" name="reason_code" value="provider_replacement">
                        <button class="btn btn-outline-warning" type="submit">Pause and drain mailbox</button>
                    </form>
                @elseif($availableProviders->isEmpty())
                    <div class="alert alert-info d-flex flex-wrap justify-content-between align-items-center gap-2 mb-0" role="status">
                        <span>No active, exactly verified provider is available.</span>
                        <a class="btn btn-sm btn-primary" href="{{ route('tech.admin.system.integrations.email-providers.create') }}">Create replacement provider</a>
                    </div>
                @else
                    <form method="POST" action="{{ route('tech.admin.system.integrations.email-providers.migrations.items.rebind', [$run->public_id, $blockedItem->id]) }}" class="row g-3 align-items-end">
                        @csrf
                        <div class="col-md-8">
                            <label for="provider_integration_id_{{ $blockedItem->id }}" class="form-label">Active verified provider</label>
                            <select id="provider_integration_id_{{ $blockedItem->id }}" name="provider_integration_id" class="form-select" required>
                                <option value="">Select provider</option>
                                @foreach($availableProviders as $provider)
                                    <option value="{{ $provider->getKey() }}">{{ $provider->integration?->name ?? 'Email provider' }} · IMAP/SMTP ready</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4">
                            <button class="btn btn-primary w-100" type="submit">Bind verified provider</button>
                        </div>
                    </form>
                @endif
            </div>
        </div>
    @endforeach

    <div class="d-flex flex-wrap gap-2 justify-content-end">
        @if($run->operation === 'legacy_migration' && $run->status === 'staged')
            <form method="POST" action="{{ route('tech.admin.system.integrations.email-providers.migrations.cutover-preview', $run->public_id) }}">@csrf<button class="btn btn-outline-primary" type="submit">Preview cutover readiness</button></form>
        @endif
        @if($run->operation === 'cutover' && $run->status === 'previewed')
            <form method="POST" action="{{ route('tech.admin.system.integrations.email-providers.migrations.cutover', $run->public_id) }}">@csrf<button class="btn btn-primary" type="submit" @disabled($run->blocked_count > 0)>Apply exact cutover</button></form>
        @endif
        @if($run->operation === 'cutover' && $run->status === 'applied' && $run->rollback_deadline_at?->isFuture())
            <form method="POST" action="{{ route('tech.admin.system.integrations.email-providers.migrations.rollback', $run->public_id) }}">@csrf<button class="btn btn-outline-danger" type="submit">Rollback to intact legacy binding</button></form>
        @endif
    </div>
@endsection

@section('sidebar')
    <x-nav.admin-menu group="integrations" />
@endsection

