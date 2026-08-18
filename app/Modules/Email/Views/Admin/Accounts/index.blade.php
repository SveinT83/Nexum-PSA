@extends('layouts.default_tech')

@section('title', 'Email accounts')

@section('pageHeader')
  <div class="d-flex align-items-center justify-content-between">
    <h1>Email Accounts</h1>
    <a href="{{ route('tech.admin.settings.email.accounts.create') }}" class="btn btn-primary" data-telemetry="click_add_account">Add account</a>
  </div>
@endsection

@section('content')
  <div class="col-12">
    @if(isset($missingTable) && $missingTable)
      <div class="alert alert-warning" role="alert">
        Email accounts table not found. Run migrations to continue.
      </div>
    @endif

    @if(!isset($accounts) || $accounts->isEmpty())
      <div class="text-center py-5" data-telemetry="email_accounts_index_empty">
        <h2 class="h5 text-muted mb-3">No email accounts configured</h2>
        <a href="{{ route('tech.admin.settings.email.accounts.create') }}" class="btn btn-outline-primary">Add account</a>
      </div>
    @else
      <div class="table-responsive" data-telemetry="email_accounts_index_opened">
        <table class="table table-sm align-middle">
          <thead class="table-light">
            <tr>
              <th scope="col">Account / Address</th>
              <th scope="col" style="width: 180px;">Kind</th>
              <th scope="col" class="text-center" style="width: 140px;">Status</th>
              <th scope="col" style="width: 180px;">Folders</th>
              <th scope="col" style="width: 320px;">Defaults</th>
              <th scope="col" style="width: 180px;">Access</th>
              <th scope="col" class="text-end" style="width: 160px;">Actions</th>
            </tr>
          </thead>
          <tbody>
            @foreach($accounts as $account)
              @php
                $hasError = !empty($account->last_error_code) || !empty($account->last_error_message);
                $defaults = (array)($account->defaults_for ?? []);
              @endphp
              <tr data-telemetry="email_account_row" data-account-id="{{ $account->id }}">
                <td>
                  <div class="d-flex align-items-start gap-2">
                    @if($hasError)
                      <span class="text-warning" aria-hidden="true" title="Connection error">&#9888;</span>
                    @endif
                    <div>
                      <div class="fw-semibold">{{ $account->address }}</div>
                      @if($account->usesIntegrationProvider())
                        <div class="text-muted small">
                          Provider: {{ $account->providerConnection?->integration?->name ?? 'Unavailable' }} ·
                          {{ $account->providerConnection?->status === 'active' ? 'Ready' : 'Unavailable' }}
                        </div>
                      @else
                        <div class="text-muted small">Legacy credential evidence · migration required</div>
                      @endif
                    </div>
                  </div>
                </td>
                <td>
                  <span class="badge text-bg-light">{{ \App\Modules\Email\Models\EmailAccount::KINDS[$account->account_kind] ?? ucfirst((string) $account->account_kind) }}</span>
                  @if($account->owner)
                    <div class="text-muted small mt-1">{{ $account->owner->name }}</div>
                  @endif
                  @if($account->ticket_ingress_enabled)
                    <div class="small mt-1"><span class="badge text-bg-info">Ticket ingress</span></div>
                  @endif
                </td>
                <td class="text-center">
                  @if($account->is_active)
                    <span class="badge rounded-pill text-bg-success" aria-label="Status Active">Active</span>
                  @else
                    <span class="badge rounded-pill text-bg-secondary" aria-label="Status Disabled">Disabled</span>
                  @endif
                </td>
                <td>
                  @php
                    $folders = $account->folders ?? collect();
                    $folderErrors = $folders->where('sync_status', \App\Modules\Email\Models\EmailFolder::SYNC_ERROR)->count();
                    $inboxFolder = $folders->firstWhere('role', \App\Modules\Email\Models\EmailFolder::ROLE_INBOX);
                  @endphp
                  <div class="small">
                    <span class="fw-semibold">{{ $folders->count() }}</span>
                    <span class="text-muted">discovered</span>
                  </div>
                  @if($inboxFolder)
                    <div class="small text-muted">INBOX UIDVALIDITY: {{ $inboxFolder->uid_validity ?: 'Pending' }}</div>
                  @endif
                  @if($folderErrors > 0)
                    <span class="badge text-bg-warning">{{ $folderErrors }} sync issue{{ $folderErrors === 1 ? '' : 's' }}</span>
                  @endif
                </td>
                <td>
                  <div class="d-flex flex-wrap gap-2">
                    @if($account->is_global_default)
                      <span class="badge text-bg-primary" aria-label="Default Global">Default (Global)</span>
                    @endif
                    @foreach(\App\Modules\Email\Models\EmailAccount::DEFAULT_SCOPES as $scope => $label)
                      @continue(! in_array($scope, $defaults))
                      <span class="badge {{ $scope === 'alerts' ? 'text-bg-warning' : 'text-bg-info' }}" aria-label="Default {{ $label }}">Default ({{ $label }})</span>
                    @endforeach
                  </div>
                </td>
                <td>
                  @if($account->isPersonal())
                    <span class="text-muted small">Owner only</span>
                  @else
                    @php
                      $viewCount = $account->userGrants->where('can_view', true)->count();
                      $organizeCount = $account->userGrants->filter(fn ($grant) => $grant->can_view && $grant->can_organize)->count();
                      $sendCount = $account->userGrants->where('can_send', true)->count();
                    @endphp
                    <div class="small">View: {{ $viewCount }}</div>
                    <div class="small text-muted">Organize: {{ $organizeCount }} · Send: {{ $sendCount }}</div>
                  @endif
                </td>
                <td class="text-end">
                  @if($account->is_active && (!$account->isPersonal() || (int)$account->owner_id === (int)auth()->id()))
                    <a href="{{ route('tech.mail.unread-handover.index', $account) }}" class="btn btn-outline-primary btn-sm">Unread handover</a>
                  @endif
                  @can('email.mailbox_sync_manage')
                    <a href="{{ route('tech.admin.settings.email.accounts.mailbox-maintenance', $account) }}" class="btn btn-outline-primary btn-sm" data-telemetry="email_mailbox_maintenance">Maintenance</a>
                  @endcan
                  <a href="{{ route('tech.admin.settings.email.accounts.edit', $account) }}" class="btn btn-outline-secondary btn-sm" data-telemetry="click_edit">Edit</a>
                  <form action="{{ route('tech.admin.settings.email.accounts.toggle', $account) }}" method="POST" class="d-inline" data-telemetry="toggle_status">
                    @csrf
                    <button type="submit" class="btn btn-sm {{ $account->is_active ? 'btn-outline-warning' : 'btn-outline-success' }}">
                      {{ $account->is_active ? 'Deactivate' : 'Activate' }}
                    </button>
                  </form>
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    @endif
  </div>
@endsection

@section('sidebar')
  <x-nav.admin-menu group="email" />
@endsection

@section('rightbar')
  <div class="mt-3">
    <!-- -------------------------------------------------------------------------------------------------- -->
    <!-- Email template shortcut -->
    <!-- Keeps outbound template management reachable from Email Settings as well as the Templates hub. -->
    <!-- -------------------------------------------------------------------------------------------------- -->
    <x-card.default title="Templates">
      <p class="small text-muted">Manage outbound email templates for tickets, system notifications, and future workflows.</p>
      <a href="{{ route('tech.admin.system.templatesManagement.email.index') }}" class="btn btn-sm btn-outline-primary">Email Templates</a>
    </x-card.default>
  </div>
@endsection
