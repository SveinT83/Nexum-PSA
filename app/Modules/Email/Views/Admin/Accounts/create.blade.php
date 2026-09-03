@extends('layouts.default_tech')

@php
    $isEdit = isset($account);
    $title = $isEdit ? 'Edit email account' : 'Add email account';
    $defaultsFor = old('defaults_for', $isEdit ? ($account->defaults_for ?? []) : []);
    $selectedKind = old('account_kind', $isEdit ? ($account->account_kind ?? \App\Modules\Email\Models\EmailAccount::KIND_SHARED) : \App\Modules\Email\Models\EmailAccount::KIND_SHARED);
    $selectedOwnerId = (int) old('owner_id', $isEdit ? ($account->owner_id ?? 0) : 0);
    $hasSavedCredentials = $isEdit && filled($account->imap_secret) && filled($account->smtp_secret);
    $imapTransport = old('imap_encryption', $isEdit ? ($account->imap_encryption ?? 'implicit_tls') : 'implicit_tls');
    $smtpTransport = old('smtp_encryption', $isEdit ? ($account->smtp_encryption ?? 'starttls') : 'starttls');
    $imapTransport = in_array($imapTransport, ['ssl', 'tls'], true) && (int) old('imap_port', $isEdit ? $account->imap_port : 993) === 993 ? 'implicit_tls' : $imapTransport;
    $smtpTransport = in_array($smtpTransport, ['ssl', 'tls'], true) && (int) old('smtp_port', $isEdit ? $account->smtp_port : 587) === 465 ? 'implicit_tls' : $smtpTransport;
    $imapTransport = $imapTransport === 'tls' ? 'starttls' : $imapTransport;
    $smtpTransport = $smtpTransport === 'tls' ? 'starttls' : $smtpTransport;
    $grantRows = collect(old('grants', []))->keyBy('user_id');
    if ($grantRows->isEmpty() && $isEdit) {
        $grantRows = $account->userGrants->mapWithKeys(fn ($grant) => [
            $grant->user_id => [
                'user_id' => $grant->user_id,
                'can_view' => $grant->can_view,
                'can_organize' => $grant->can_organize,
                'can_send' => $grant->can_send,
            ],
        ]);
    }
@endphp

@section('title', $title)

@section('pageHeader')
  <div class="d-flex align-items-center justify-content-between">
    <h1>{{ $title }}</h1>
    <a href="{{ route('tech.admin.settings.email.accounts') }}" class="btn btn-outline-secondary btn-sm">Close</a>
  </div>
@endsection

@section('sidebar')
  <x-nav.admin-menu group="email" />
@endsection

@section('content')
  <div class="col-12">
    @if(session('status'))
      <div class="alert alert-info">{{ session('status') }}</div>
    @endif
    @if(session('error'))
      <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    @if($isEdit)
      @if($account->last_test_result === 'Testing')
        <div class="alert alert-info" data-email-connection-testing>
          <div class="fw-semibold">Testing incoming and outgoing mail…</div>
          <div class="small">This page refreshes automatically when the Email worker has finished.</div>
        </div>
        <script>window.setTimeout(() => window.location.reload(), 3000);</script>
      @elseif($account->last_test_result === 'OK')
        <div class="alert alert-success">
          <div class="fw-semibold">Incoming and outgoing mail login passed.</div>
          <div class="small">IMAP and SMTP were authenticated successfully{{ $account->last_test_at ? ' '.$account->last_test_at->diffForHumans() : '' }}.</div>
        </div>
      @elseif($account->last_test_result)
        <div class="alert alert-danger">
          <div class="fw-semibold">The connection needs attention.</div>
          <div class="small mb-2">{{ $account->last_error_message ?: 'Check the server, port, security, username and password, then save and test again.' }}</div>
          <div class="d-flex flex-wrap gap-3 small">
            <span>Incoming mail: {{ $account->last_successful_fetch_at ? 'Passed' : 'Failed' }}</span>
            <span>Outgoing mail: {{ $account->last_successful_send_at ? 'Passed' : 'Failed' }}</span>
          </div>
        </div>
      @endif

    @endif

    <form method="POST" action="{{ $isEdit ? route('tech.admin.settings.email.accounts.update', $account) : route('tech.admin.settings.email.accounts.store') }}" class="row g-3" autocomplete="off">
      @csrf
      @if($isEdit)
        @method('PUT')
      @endif

      <!-- Mailbox identity and behavior -->
      <div class="col-12">
        <div class="card">
          <div class="card-header"><h2 class="h6 mb-0">Mailbox</h2></div>
          <div class="card-body">
            <div class="row g-3">
              <div class="col-md-6">
                <label for="address" class="form-label">Email address</label>
                <input type="email" class="form-control @error('address') is-invalid @enderror" id="address" name="address" required value="{{ old('address', $isEdit ? $account->address : '') }}">
                @error('address')<div class="invalid-feedback">{{ $message }}</div>@enderror
              </div>
              <div class="col-md-6">
                <label for="from_name" class="form-label">Display name</label>
                <input type="text" class="form-control @error('from_name') is-invalid @enderror" id="from_name" name="from_name" value="{{ old('from_name', $isEdit ? $account->from_name : '') }}">
                @error('from_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
              </div>
              <div class="col-12">
                <label for="description" class="form-label">Description</label>
                <input type="text" class="form-control @error('description') is-invalid @enderror" id="description" name="description" value="{{ old('description', $isEdit ? $account->description : '') }}">
                @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
              </div>
              <div class="col-md-4">
                <label for="account_kind" class="form-label">Mailbox kind</label>
                <select id="account_kind" name="account_kind" class="form-select @error('account_kind') is-invalid @enderror" required>
                  @foreach(\App\Modules\Email\Models\EmailAccount::KINDS as $kind => $label)
                    <option value="{{ $kind }}" @selected($selectedKind === $kind)>{{ $label }}</option>
                  @endforeach
                </select>
                @error('account_kind')<div class="invalid-feedback">{{ $message }}</div>@enderror
              </div>
              <div class="col-md-4">
                <label for="owner_id" class="form-label">Personal owner</label>
                <select id="owner_id" name="owner_id" class="form-select @error('owner_id') is-invalid @enderror">
                  <option value="">No owner</option>
                  @foreach($users as $user)
                    <option value="{{ $user->id }}" @selected($selectedOwnerId === (int) $user->id)>{{ $user->name }} · {{ $user->email }}</option>
                  @endforeach
                </select>
                @error('owner_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
              </div>
              <div class="col-md-4">
                <input type="hidden" name="is_active" value="0">
                <div class="form-check form-switch mt-md-4 pt-md-2">
                  <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1" @checked((bool) old('is_active', $isEdit ? $account->is_active : true))>
                  <label class="form-check-label" for="is_active">Activate after a successful test</label>
                </div>
              </div>
              <div class="col-md-4">
                <input type="hidden" name="ticket_ingress_enabled" value="0">
                <div class="form-check form-switch">
                  <input class="form-check-input" type="checkbox" role="switch" id="ticket_ingress_enabled" name="ticket_ingress_enabled" value="1" @checked((bool) old('ticket_ingress_enabled', $isEdit ? $account->ticket_ingress_enabled : false))>
                  <label class="form-check-label" for="ticket_ingress_enabled">Create or update Tickets from incoming mail</label>
                </div>
              </div>
              <div class="col-md-4">
                <input type="hidden" name="is_global_default" value="0">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" id="is_global_default" name="is_global_default" value="1" @checked((bool) old('is_global_default', $isEdit ? $account->is_global_default : false))>
                  <label class="form-check-label" for="is_global_default">Global default sender</label>
                </div>
              </div>
              <div class="col-12">
                <label class="form-label">Default sender for</label>
                <div class="d-flex gap-3 flex-wrap">
                  @foreach(\App\Modules\Email\Models\EmailAccount::DEFAULT_SCOPES as $scope => $label)
                    <div class="form-check">
                      <input class="form-check-input" type="checkbox" id="def_{{ $scope }}" name="defaults_for[]" value="{{ $scope }}" @checked(in_array($scope, (array) $defaultsFor, true))>
                      <label class="form-check-label" for="def_{{ $scope }}">{{ $label }}</label>
                    </div>
                  @endforeach
                </div>
              </div>
              <div class="col-md-6">
                <label for="delete_policy" class="form-label">When mail is deleted in Nexum</label>
                @php($policy = old('delete_policy', $isEdit ? $account->delete_policy : 'local_only'))
                <select id="delete_policy" name="delete_policy" class="form-select" required>
                  <option value="local_only" @selected($policy === 'local_only')>Keep the original on the mail server</option>
                  <option value="sync_delete" @selected($policy === 'sync_delete')>Also move or delete it on the mail server</option>
                  <option value="auto_delete" @selected($policy === 'auto_delete')>Automatically remove it from the server after import</option>
                  <option value="legacy_default" @selected($policy === 'legacy_default')>Use the installation default</option>
                </select>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Incoming and outgoing connection settings -->
      <div class="col-lg-6">
        <div class="card h-100">
          <div class="card-header"><h2 class="h6 mb-0">Incoming mail (IMAP)</h2></div>
          <div class="card-body">
            <div class="row g-3">
              <div class="col-sm-8">
                <label for="imap_host" class="form-label">Server</label>
                <input type="text" class="form-control @error('imap_host') is-invalid @enderror" id="imap_host" name="imap_host" required value="{{ old('imap_host', $isEdit ? $account->imap_host : '') }}" placeholder="mail.example.com">
                @error('imap_host')<div class="invalid-feedback">{{ $message }}</div>@enderror
              </div>
              <div class="col-sm-4">
                <label for="imap_port" class="form-label">Port</label>
                <input type="number" class="form-control @error('imap_port') is-invalid @enderror" id="imap_port" name="imap_port" min="1" max="65535" required value="{{ old('imap_port', $isEdit ? $account->imap_port : 993) }}">
                @error('imap_port')<div class="invalid-feedback">{{ $message }}</div>@enderror
              </div>
              <div class="col-12">
                <label for="imap_encryption" class="form-label">Connection security</label>
                <select id="imap_encryption" name="imap_encryption" class="form-select @error('imap_encryption') is-invalid @enderror" required>
                  <option value="implicit_tls" @selected($imapTransport === 'implicit_tls')>SSL/TLS (normally port 993)</option>
                  <option value="starttls" @selected($imapTransport === 'starttls')>STARTTLS (normally port 143)</option>
                </select>
                @error('imap_encryption')<div class="invalid-feedback">{{ $message }}</div>@enderror
              </div>
              <div class="col-12">
                <label for="imap_username" class="form-label">Username</label>
                <input type="text" class="form-control @error('imap_username') is-invalid @enderror" id="imap_username" name="imap_username" required value="{{ old('imap_username', $isEdit ? $account->imap_username : '') }}" autocomplete="username">
                @error('imap_username')<div class="invalid-feedback">{{ $message }}</div>@enderror
              </div>
              <div class="col-12">
                <label for="imap_secret" class="form-label">Password</label>
                <input type="password" class="form-control @error('imap_secret') is-invalid @enderror" id="imap_secret" name="imap_secret" {{ $hasSavedCredentials ? '' : 'required' }} autocomplete="new-password">
                @error('imap_secret')<div class="invalid-feedback">{{ $message }}</div>@enderror
                @if($hasSavedCredentials)<div class="form-text">Leave blank to keep the saved password.</div>@endif
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="col-lg-6">
        <div class="card h-100">
          <div class="card-header"><h2 class="h6 mb-0">Outgoing mail (SMTP)</h2></div>
          <div class="card-body">
            <div class="row g-3">
              <div class="col-sm-8">
                <label for="smtp_host" class="form-label">Server</label>
                <input type="text" class="form-control @error('smtp_host') is-invalid @enderror" id="smtp_host" name="smtp_host" required value="{{ old('smtp_host', $isEdit ? $account->smtp_host : '') }}" placeholder="mail.example.com">
                @error('smtp_host')<div class="invalid-feedback">{{ $message }}</div>@enderror
              </div>
              <div class="col-sm-4">
                <label for="smtp_port" class="form-label">Port</label>
                <input type="number" class="form-control @error('smtp_port') is-invalid @enderror" id="smtp_port" name="smtp_port" min="1" max="65535" required value="{{ old('smtp_port', $isEdit ? $account->smtp_port : 587) }}">
                @error('smtp_port')<div class="invalid-feedback">{{ $message }}</div>@enderror
              </div>
              <div class="col-12">
                <label for="smtp_encryption" class="form-label">Connection security</label>
                <select id="smtp_encryption" name="smtp_encryption" class="form-select @error('smtp_encryption') is-invalid @enderror" required>
                  <option value="starttls" @selected($smtpTransport === 'starttls')>STARTTLS (normally port 587)</option>
                  <option value="implicit_tls" @selected($smtpTransport === 'implicit_tls')>SSL/TLS (normally port 465)</option>
                </select>
                @error('smtp_encryption')<div class="invalid-feedback">{{ $message }}</div>@enderror
              </div>
              <div class="col-12">
                <label for="smtp_username" class="form-label">Username</label>
                <input type="text" class="form-control @error('smtp_username') is-invalid @enderror" id="smtp_username" name="smtp_username" required value="{{ old('smtp_username', $isEdit ? $account->smtp_username : '') }}" autocomplete="username">
                @error('smtp_username')<div class="invalid-feedback">{{ $message }}</div>@enderror
              </div>
              <div class="col-12">
                <label for="smtp_secret" class="form-label">Password</label>
                <input type="password" class="form-control @error('smtp_secret') is-invalid @enderror" id="smtp_secret" name="smtp_secret" {{ $hasSavedCredentials ? '' : 'required' }} autocomplete="new-password">
                @error('smtp_secret')<div class="invalid-feedback">{{ $message }}</div>@enderror
                @if($hasSavedCredentials)<div class="form-text">Leave blank to keep the saved password.</div>@endif
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Mailbox grants -->
      <div class="col-12">
        <div class="card">
          <div class="card-header"><h2 class="h6 mb-0">Mailbox access</h2></div>
          <div class="card-body">
            <div class="table-responsive">
              <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                  <tr>
                    <th>User</th>
                    <th class="text-center" style="width: 120px;">View</th>
                    <th class="text-center" style="width: 120px;">Organize</th>
                    <th class="text-center" style="width: 120px;">Send</th>
                  </tr>
                </thead>
                <tbody>
                  @forelse($users as $user)
                    @php($grant = $grantRows->get($user->id, ['user_id' => $user->id]))
                    <tr>
                      <td>
                        <input type="hidden" name="grants[{{ $user->id }}][user_id]" value="{{ $user->id }}">
                        <div class="fw-semibold">{{ $user->name }}</div>
                        <div class="text-muted small">{{ $user->email }}</div>
                      </td>
                      <td class="text-center"><input class="form-check-input" type="checkbox" name="grants[{{ $user->id }}][can_view]" value="1" @checked((bool) ($grant['can_view'] ?? false)) aria-label="View {{ $user->name }}"></td>
                      <td class="text-center"><input class="form-check-input" type="checkbox" name="grants[{{ $user->id }}][can_organize]" value="1" @checked((bool) ($grant['can_organize'] ?? false)) aria-label="Organize {{ $user->name }}"></td>
                      <td class="text-center"><input class="form-check-input" type="checkbox" name="grants[{{ $user->id }}][can_send]" value="1" @checked((bool) ($grant['can_send'] ?? false)) aria-label="Send {{ $user->name }}"></td>
                    </tr>
                  @empty
                    <tr><td colspan="4" class="text-muted text-center py-4">No active users available.</td></tr>
                  @endforelse
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

      <div class="col-12 d-flex justify-content-end gap-2">
        <a href="{{ route('tech.admin.settings.email.accounts') }}" class="btn btn-outline-secondary">Cancel</a>
        <button type="submit" class="btn btn-primary">Save and test connection</button>
      </div>
    </form>
  </div>
@endsection
