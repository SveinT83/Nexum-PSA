@extends('layouts.default_tech')

@section('title', 'New Email provider')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center gap-3">
        <div><h1 class="h4 mb-1">New Email provider</h1><p class="text-body-secondary mb-0">Stage a new independent provider connection.</p></div>
        <a class="btn btn-outline-secondary" href="{{ route('tech.admin.system.integrations.email-providers.index') }}">Close</a>
    </div>
@endsection

@section('content')
    <form method="POST" action="{{ route('tech.admin.system.integrations.email-providers.store') }}" class="vstack gap-4" autocomplete="off">
        @csrf
        <!-- Safe label -->
        <div class="card shadow-sm"><div class="card-body">
            <label for="name" class="form-label">Provider label</label>
            <input id="name" name="name" class="form-control @error('name') is-invalid @enderror" maxlength="120" required value="{{ old('name') }}">
            <div class="form-text">This is the only provider identity shown from Email account pages.</div>
            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div></div>

        <!-- Endpoint and credentials are submitted only to Integration -->
        <div class="card shadow-sm"><div class="card-header"><h2 class="h6 mb-0">IMAP</h2></div><div class="card-body"><div class="row g-3">
            <div class="col-md-5"><label for="imap_host" class="form-label">Hostname or IP</label><input id="imap_host" name="imap_host" class="form-control" maxlength="253" required autocomplete="off"></div>
            <div class="col-md-2"><label for="imap_port" class="form-label">Port</label><select id="imap_port" name="imap_port" class="form-select"><option value="993">993</option><option value="143">143</option></select></div>
            <div class="col-md-3"><label for="imap_transport" class="form-label">TLS mode</label><select id="imap_transport" name="imap_transport" class="form-select"><option value="implicit_tls">Implicit TLS</option><option value="starttls">Required STARTTLS</option></select></div>
            <div class="col-md-6"><label for="imap_username" class="form-label">Username</label><input id="imap_username" name="imap_username" class="form-control" required autocomplete="off"></div>
            <div class="col-md-6"><label for="imap_secret" class="form-label">Password</label><input type="password" id="imap_secret" name="imap_secret" class="form-control" required autocomplete="new-password"></div>
        </div></div></div>

        <div class="card shadow-sm"><div class="card-header"><h2 class="h6 mb-0">SMTP</h2></div><div class="card-body"><div class="row g-3">
            <div class="col-md-5"><label for="smtp_host" class="form-label">Hostname or IP</label><input id="smtp_host" name="smtp_host" class="form-control" maxlength="253" required autocomplete="off"></div>
            <div class="col-md-2"><label for="smtp_port" class="form-label">Port</label><select id="smtp_port" name="smtp_port" class="form-select"><option value="465">465</option><option value="587">587</option></select></div>
            <div class="col-md-3"><label for="smtp_transport" class="form-label">TLS mode</label><select id="smtp_transport" name="smtp_transport" class="form-select"><option value="implicit_tls">Implicit TLS</option><option value="starttls">Required STARTTLS</option></select></div>
            <div class="col-md-6"><label for="smtp_username" class="form-label">Username</label><input id="smtp_username" name="smtp_username" class="form-control" required autocomplete="off"></div>
            <div class="col-md-6"><label for="smtp_secret" class="form-label">Password</label><input type="password" id="smtp_secret" name="smtp_secret" class="form-control" required autocomplete="new-password"></div>
        </div></div></div>

        <div class="card shadow-sm"><div class="card-header"><h2 class="h6 mb-0">Endpoint trust</h2></div><div class="card-body">
            <select name="trust_mode" class="form-select mb-3" id="trust_mode">
                <option value="public">Public endpoints only</option>
                @if($canManagePrivate)<option value="trusted_private">Approved named private CIDR</option>@endif
            </select>
            @if($canManagePrivate)
                <div class="row g-3">
                    <div class="col-md-5"><label for="trusted_cidr_name" class="form-label">Named CIDR</label><select id="trusted_cidr_name" name="trusted_cidr_name" class="form-select"><option value="">Select only for private trust</option>@foreach($trustedCidrNames as $cidrName)<option value="{{ $cidrName }}">{{ $cidrName }}</option>@endforeach</select></div>
                    <div class="col-md-7"><label for="private_endpoint_reason" class="form-label">Approval reason</label><textarea id="private_endpoint_reason" name="private_endpoint_reason" class="form-control" rows="2" maxlength="1000"></textarea></div>
                </div>
            @endif
        </div></div>

        <div class="d-flex justify-content-end gap-2"><a class="btn btn-outline-secondary" href="{{ route('tech.admin.system.integrations.email-providers.index') }}">Cancel</a><button class="btn btn-primary" type="submit">Stage provider</button></div>
    </form>
@endsection

@section('sidebar')
    <x-nav.admin-menu group="integrations" />
@endsection

