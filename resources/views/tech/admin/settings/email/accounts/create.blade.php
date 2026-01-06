@extends('layouts.default_tech')

@php
    $isEdit = isset($account);
    $title = $isEdit ? 'Edit email account' : 'Add email account';
    $defaultsFor = old('defaults_for', $isEdit ? ($account->defaults_for ?? []) : []);
@endphp

@section('title', $title)

@section('pageHeader')
  <div class="d-flex align-items-center justify-content-between">
    <h1>{{ $title }}</h1>
    <a href="{{ route('tech.admin.settings.email.accounts') }}" class="btn btn-link">Close</a>
  </div>
@endsection

@section('content')
  <div class="col-12">
    @if ($errors->any())
      <div class="alert alert-danger">
        <strong>There were some problems with your input:</strong>
        <ul class="mb-0">
          @foreach ($errors->all() as $error)
            <li>{{ $error }}</li>
          @endforeach
        </ul>
      </div>
    @endif

  <form method="POST" action="{{ $isEdit ? route('tech.admin.settings.email.accounts.update', $account) : route('tech.admin.settings.email.accounts.store') }}" class="row g-4">
      @csrf
      @if($isEdit)
        @method('PUT')
      @endif

      <div class="col-12">
        <div class="card">
          <div class="card-body">
            <h2 class="h5 mb-3">General information</h2>
            <div class="row g-3">
              <div class="col-md-6">
                <label for="address" class="form-label">Email address</label>
                <input type="email" class="form-control" id="address" name="address" required value="{{ old('address', $isEdit ? $account->address : '') }}">
              </div>
              <div class="col-md-6">
                <label for="from_name" class="form-label">Display name (From)</label>
                <input type="text" class="form-control" id="from_name" name="from_name" value="{{ old('from_name', $isEdit ? $account->from_name : '') }}">
              </div>
              <div class="col-12">
                <label for="description" class="form-label">Description</label>
                <input type="text" class="form-control" id="description" name="description" value="{{ old('description', $isEdit ? $account->description : '') }}">
              </div>
              <div class="col-md-3">
                <input type="hidden" name="is_active" value="0">
                <div class="form-check form-switch mt-4">
                  <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1" {{ old('is_active', $isEdit ? (int)$account->is_active : 1) ? 'checked' : '' }}>
                  <label class="form-check-label" for="is_active">Active</label>
                </div>
              </div>
              <div class="col-md-9">
                <div class="row g-3">
                  <div class="col-md-4">
                    <input type="hidden" name="is_global_default" value="0">
                    <div class="form-check mt-4">
                      <input class="form-check-input" type="checkbox" id="is_global_default" name="is_global_default" value="1" {{ old('is_global_default', $isEdit ? (int)$account->is_global_default : 0) ? 'checked' : '' }}>
                      <label class="form-check-label" for="is_global_default">Default (Global)</label>
                    </div>
                  </div>
                  <div class="col-md-8">
                    <label class="form-label">Defaults for systems</label>
                    <div class="d-flex gap-3 flex-wrap">
                      <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="def_tickets" name="defaults_for[]" value="tickets" {{ in_array('tickets', (array)$defaultsFor) ? 'checked' : '' }}>
                        <label class="form-check-label" for="def_tickets">Tickets</label>
                      </div>
                      <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="def_sales" name="defaults_for[]" value="sales" {{ in_array('sales', (array)$defaultsFor) ? 'checked' : '' }}>
                        <label class="form-check-label" for="def_sales">Sales</label>
                      </div>
                      <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="def_alerts" name="defaults_for[]" value="alerts" {{ in_array('alerts', (array)$defaultsFor) ? 'checked' : '' }}>
                        <label class="form-check-label" for="def_alerts">Alerts/System</label>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="col-12">
        <div class="card">
          <div class="card-body">
            <h2 class="h5 mb-3">IMAP settings</h2>
            <div class="row g-3">
              <div class="col-md-5">
                <label for="imap_host" class="form-label">Server</label>
                <input type="text" class="form-control" id="imap_host" name="imap_host" required value="{{ old('imap_host', $isEdit ? $account->imap_host : '') }}">
              </div>
              <div class="col-md-2">
                <label for="imap_port" class="form-label">Port</label>
                <input type="number" class="form-control" id="imap_port" name="imap_port" required min="1" max="65535" value="{{ old('imap_port', $isEdit ? $account->imap_port : 993) }}">
              </div>
              <div class="col-md-3">
                <label for="imap_encryption" class="form-label">Encryption</label>
                <select id="imap_encryption" name="imap_encryption" class="form-select" required>
                  @php $imapEnc = old('imap_encryption', $isEdit ? $account->imap_encryption : 'ssl'); @endphp
                  <option value="ssl" {{ $imapEnc === 'ssl' ? 'selected' : '' }}>SSL</option>
                  <option value="tls" {{ $imapEnc === 'tls' ? 'selected' : '' }}>TLS</option>
                  <option value="starttls" {{ $imapEnc === 'starttls' ? 'selected' : '' }}>STARTTLS</option>
                  <option value="none" {{ $imapEnc === 'none' ? 'selected' : '' }}>None</option>
                </select>
              </div>
              <div class="col-md-4">
                <label for="imap_username" class="form-label">Username</label>
                <input type="text" class="form-control" id="imap_username" name="imap_username" required value="{{ old('imap_username', $isEdit ? $account->imap_username : '') }}">
              </div>
              <div class="col-md-4">
                <label for="imap_secret" class="form-label">Password</label>
                <input type="password" class="form-control" id="imap_secret" name="imap_secret" required>
              </div>
              <div class="col-md-4">
                <label for="imap_auth_type" class="form-label">Auth type</label>
                @php $imapAuth = old('imap_auth_type', $isEdit ? $account->imap_auth_type : 'plain'); @endphp
                <select id="imap_auth_type" name="imap_auth_type" class="form-select" required>
                  <option value="plain" {{ $imapAuth === 'plain' ? 'selected' : '' }}>PLAIN</option>
                  <option value="login" {{ $imapAuth === 'login' ? 'selected' : '' }}>LOGIN</option>
                  <option value="cram-md5" {{ $imapAuth === 'cram-md5' ? 'selected' : '' }}>CRAM-MD5</option>
                </select>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="col-12">
        <div class="card">
          <div class="card-body">
            <h2 class="h5 mb-3">SMTP settings</h2>
            <div class="row g-3">
              <div class="col-md-5">
                <label for="smtp_host" class="form-label">Server</label>
                <input type="text" class="form-control" id="smtp_host" name="smtp_host" required value="{{ old('smtp_host', $isEdit ? $account->smtp_host : '') }}">
              </div>
              <div class="col-md-2">
                <label for="smtp_port" class="form-label">Port</label>
                <input type="number" class="form-control" id="smtp_port" name="smtp_port" required min="1" max="65535" value="{{ old('smtp_port', $isEdit ? $account->smtp_port : 587) }}">
              </div>
              <div class="col-md-3">
                <label for="smtp_encryption" class="form-label">Encryption</label>
                <select id="smtp_encryption" name="smtp_encryption" class="form-select" required>
                  @php $smtpEnc = old('smtp_encryption', $isEdit ? $account->smtp_encryption : 'tls'); @endphp
                  <option value="tls" {{ $smtpEnc === 'tls' ? 'selected' : '' }}>TLS</option>
                  <option value="ssl" {{ $smtpEnc === 'ssl' ? 'selected' : '' }}>SSL</option>
                  <option value="starttls" {{ $smtpEnc === 'starttls' ? 'selected' : '' }}>STARTTLS</option>
                  <option value="none" {{ $smtpEnc === 'none' ? 'selected' : '' }}>None</option>
                </select>
              </div>
              <div class="col-md-4">
                <label for="smtp_username" class="form-label">Username</label>
                <input type="text" class="form-control" id="smtp_username" name="smtp_username" required value="{{ old('smtp_username', $isEdit ? $account->smtp_username : '') }}">
              </div>
              <div class="col-md-4">
                <label for="smtp_secret" class="form-label">Password</label>
                <input type="password" class="form-control" id="smtp_secret" name="smtp_secret" required>
              </div>
              <div class="col-md-4">
                <label for="smtp_auth_type" class="form-label">Auth type</label>
                @php $smtpAuth = old('smtp_auth_type', $isEdit ? $account->smtp_auth_type : 'login'); @endphp
                <select id="smtp_auth_type" name="smtp_auth_type" class="form-select" required>
                  <option value="login" {{ $smtpAuth === 'login' ? 'selected' : '' }}>LOGIN</option>
                  <option value="plain" {{ $smtpAuth === 'plain' ? 'selected' : '' }}>PLAIN</option>
                  <option value="cram-md5" {{ $smtpAuth === 'cram-md5' ? 'selected' : '' }}>CRAM-MD5</option>
                </select>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="col-12 d-flex justify-content-end gap-2">
        <a href="{{ route('tech.admin.settings.email.accounts') }}" class="btn btn-outline-secondary">Close</a>
        @if($isEdit)
          <button type="button" class="btn btn-outline-primary" onclick="document.getElementById('email-test-form')?.submit();">Run Full Test</button>
        @endif
        <button type="submit" class="btn btn-primary">Save</button>
      </div>
    </form>

    @if($isEdit)
      <form id="email-test-form" method="POST" action="{{ route('tech.admin.settings.email.accounts.test', $account) }}" class="d-none">
        @csrf
      </form>
    @endif
    @if(session('email_test'))
      @php($t = session('email_test'))
      <div class="col-12">
        <div class="alert {{ $t['overall'] === 'OK' ? 'alert-success' : ($t['overall'] === 'Warning' ? 'alert-warning' : 'alert-danger') }} mt-3">
          <div class="fw-semibold">Connection test: {{ $t['overall'] }}</div>
          <div class="small">IMAP: {{ $t['imap_ok'] ? 'OK' : 'Fail' }} ({{ $t['imap_ms'] }} ms) {{ $t['imap_error'] ? '— '.$t['imap_error'] : '' }}</div>
          <div class="small">SMTP: {{ $t['smtp_ok'] ? 'OK' : 'Fail' }} ({{ $t['smtp_ms'] }} ms) {{ $t['smtp_error'] ? '— '.$t['smtp_error'] : '' }}</div>
        </div>
      </div>
    @endif
  </div>
@endsection
