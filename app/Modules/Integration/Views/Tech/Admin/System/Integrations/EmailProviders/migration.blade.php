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

