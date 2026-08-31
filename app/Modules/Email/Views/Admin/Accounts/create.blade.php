@extends('layouts.default_tech')

@php
    $isEdit = isset($account);
    $title = $isEdit ? 'Edit email account' : 'Add email account';
    $defaultsFor = old('defaults_for', $isEdit ? ($account->defaults_for ?? []) : []);
    $selectedKind = old('account_kind', $isEdit ? ($account->account_kind ?? \App\Modules\Email\Models\EmailAccount::KIND_SHARED) : \App\Modules\Email\Models\EmailAccount::KIND_SHARED);
    $selectedOwnerId = (int) old('owner_id', $isEdit ? ($account->owner_id ?? 0) : 0);
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
    <a href="{{ route('tech.admin.settings.email.accounts') }}" class="btn btn-link">Close</a>
  </div>
@endsection

@section('sidebar')
  <x-nav.admin-menu group="email" />
@endsection

@section('content')
  <div class="col-12">
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
                      @foreach(\App\Modules\Email\Models\EmailAccount::DEFAULT_SCOPES as $scope => $label)
                        <div class="form-check">
                          <input class="form-check-input" type="checkbox" id="def_{{ $scope }}" name="defaults_for[]" value="{{ $scope }}" {{ in_array($scope, (array)$defaultsFor) ? 'checked' : '' }}>
                          <label class="form-check-label" for="def_{{ $scope }}">{{ $label }}</label>
                        </div>
                      @endforeach
                    </div>
                  </div>
                </div>
              </div>
              <div class="col-md-4">
                <input type="hidden" name="ticket_ingress_enabled" value="0">
                <div class="form-check form-switch mt-4">
                  <input class="form-check-input" type="checkbox" role="switch" id="ticket_ingress_enabled" name="ticket_ingress_enabled" value="1" {{ old('ticket_ingress_enabled', $isEdit ? (int) $account->ticket_ingress_enabled : 0) ? 'checked' : '' }}>
                  <label class="form-check-label" for="ticket_ingress_enabled">Ticket ingress</label>
                </div>
              </div>
              <div class="col-12 mt-3 pt-3 border-top">
                <div class="row">
                  <div class="col-md-6">
                    <label for="delete_policy" class="form-label fw-semibold">Provider cleanup policy</label>
                    <select id="delete_policy" name="delete_policy" class="form-select" required>
                      @php $policy = old('delete_policy', $isEdit ? $account->delete_policy : 'local_only'); @endphp
                      <option value="local_only" {{ $policy === 'local_only' ? 'selected' : '' }}>Keep provider mail on server</option>
                      <option value="sync_delete" {{ $policy === 'sync_delete' ? 'selected' : '' }}>Delete from provider when deleted in Nexum</option>
                      <option value="auto_delete" {{ $policy === 'auto_delete' ? 'selected' : '' }}>Auto-delete from provider after import</option>
                      <option value="legacy_default" {{ $policy === 'legacy_default' ? 'selected' : '' }}>Use legacy global cleanup switch</option>
                    </select>
                    <div class="form-text mt-2">
                      <strong>Keep provider mail on server:</strong> recommended for normal IMAP mailboxes.
                      Nexum hides local messages without removing the original provider message.
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Provider ownership and safe lifecycle status -->
      <div class="col-12">
        <div class="card">
          <div class="card-body">
            <h2 class="h5 mb-3">Email provider</h2>
            @if($isEdit)
              @if($account->usesIntegrationProvider())
                <div class="d-flex flex-wrap align-items-center gap-2">
                  <span class="fw-semibold">{{ $account->providerConnection?->integration?->name ?? 'Unavailable provider' }}</span>
                  <span class="badge text-bg-{{ $account->providerConnection?->status === 'active' ? 'success' : 'secondary' }}">{{ ucfirst($account->providerConnection?->status ?? 'unavailable') }}</span>
                  <span class="small text-body-secondary">IMAP {{ data_get($account->providerConnection?->capabilities, 'imap') ? 'ready' : 'pending' }} · SMTP {{ data_get($account->providerConnection?->capabilities, 'smtp') ? 'ready' : 'pending' }}</span>
                </div>
                <div class="form-text">
                  Provider identity changes require a new verified Integration connection, explicit rebind, and mailbox rebaseline.
                  @if($account->providerConnection)
                    <a href="{{ route('tech.admin.system.integrations.email-providers.show', $account->providerConnection->getKey()) }}">Manage provider lifecycle</a>.
                  @else
                    The bound provider is unavailable; keep this mailbox disabled until the binding is repaired.
                  @endif
                </div>
              @else
                <div class="alert alert-warning mb-0">
                  This account retains read-only legacy migration evidence. Endpoints, usernames, and credentials can no longer be edited here.
                  <a href="{{ route('tech.admin.system.integrations.email-providers.index') }}" class="alert-link">Open Email provider migration</a>.
                </div>
              @endif
            @else
              <label for="provider_integration_id" class="form-label">Verified provider</label>
              <select id="provider_integration_id" name="provider_integration_id" class="form-select @error('provider_integration_id') is-invalid @enderror" required>
                <option value="">Select an active provider</option>
                @foreach($providers as $provider)
                  <option value="{{ $provider->getKey() }}" @selected(old('provider_integration_id') === $provider->getKey())>{{ $provider->integration?->name ?? 'Email provider' }} · IMAP/SMTP ready</option>
                @endforeach
              </select>
              @error('provider_integration_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
              <div class="form-text">Create and verify credentials under Integrations before adding a mailbox account.</div>
            @endif
          </div>
        </div>
      </div>

      <div class="col-12">
        <div class="card">
          <div class="card-body">
            <h2 class="h5 mb-3">Mailbox access</h2>
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
                    @php
                      $grant = $grantRows->get($user->id, ['user_id' => $user->id]);
                    @endphp
                    <tr>
                      <td>
                        <input type="hidden" name="grants[{{ $user->id }}][user_id]" value="{{ $user->id }}">
                        <div class="fw-semibold">{{ $user->name }}</div>
                        <div class="text-muted small">{{ $user->email }}</div>
                      </td>
                      <td class="text-center">
                        <input class="form-check-input" type="checkbox" name="grants[{{ $user->id }}][can_view]" value="1" @checked((bool) ($grant['can_view'] ?? false)) aria-label="View {{ $user->name }}">
                      </td>
                      <td class="text-center">
                        <input class="form-check-input" type="checkbox" name="grants[{{ $user->id }}][can_organize]" value="1" @checked((bool) ($grant['can_organize'] ?? false)) aria-label="Organize {{ $user->name }}">
                      </td>
                      <td class="text-center">
                        <input class="form-check-input" type="checkbox" name="grants[{{ $user->id }}][can_send]" value="1" @checked((bool) ($grant['can_send'] ?? false)) aria-label="Send {{ $user->name }}">
                      </td>
                    </tr>
                  @empty
                    <tr>
                      <td colspan="4" class="text-muted text-center py-4">No active users available.</td>
                    </tr>
                  @endforelse
                </tbody>
              </table>
            </div>
            @error('grants')<div class="text-danger small mt-2">{{ $message }}</div>@enderror
          </div>
        </div>
      </div>

      <div class="col-12 d-flex justify-content-end gap-2">
        <a href="{{ route('tech.admin.settings.email.accounts') }}" class="btn btn-outline-secondary">Close</a>
        @if($isEdit)
          <button type="button" class="btn btn-outline-primary" onclick="document.getElementById('email-test-form')?.submit();">Check IMAP and SMTP login</button>
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
          <div class="fw-semibold">IMAP/SMTP login check: {{ $t['overall'] }}</div>
          <div class="small mb-1">This verifies trusted connections and authentication. It does not send a message or prove recipient delivery.</div>
          <div class="small">IMAP: {{ $t['imap_ok'] ? 'OK' : 'Fail' }} ({{ $t['imap_ms'] }} ms) {{ $t['imap_error'] ? '— '.$t['imap_error'] : '' }}</div>
          <div class="small">SMTP: {{ $t['smtp_ok'] ? 'OK' : 'Fail' }} ({{ $t['smtp_ms'] }} ms) {{ $t['smtp_error'] ? '— '.$t['smtp_error'] : '' }}</div>
        </div>
      </div>
    @endif
  </div>
@endsection
