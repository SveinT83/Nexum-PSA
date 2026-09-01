@extends('layouts.default_tech')

@section('title', 'Email accounts')

@section('pageHeader')
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
    <h1>Email accounts</h1>
    @if($canCreateAccounts)
      <a href="{{ route('tech.admin.settings.email.accounts.create') }}" class="btn btn-primary" data-telemetry="click_add_account">
        <i class="bi bi-plus-lg" aria-hidden="true"></i> Add account
      </a>
    @endif
  </div>
@endsection

@section('content')
  <div class="col-12">
    @if(session('status'))
      <div class="alert alert-info">{{ session('status') }}</div>
    @endif

    @if(!isset($accounts) || $accounts->isEmpty())
      <div class="text-center py-5" data-telemetry="email_accounts_index_empty">
        <h2 class="h5 text-muted mb-3">No email accounts configured</h2>
        @if($canCreateAccounts)
          <a href="{{ route('tech.admin.settings.email.accounts.create') }}" class="btn btn-outline-primary">Add account</a>
        @endif
      </div>
    @else
      <div class="table-responsive" data-telemetry="email_accounts_index_opened">
        <table class="table table-sm align-middle">
          <thead class="table-light">
            <tr>
              <th scope="col">Account</th>
              <th scope="col" style="width: 180px;">Kind</th>
              <th scope="col" style="width: 220px;">Connection</th>
              <th scope="col" style="width: 180px;">Folders</th>
              <th scope="col" style="width: 280px;">Defaults</th>
              <th scope="col" style="width: 170px;">Access</th>
              <th scope="col" class="text-end" style="width: 210px;">Actions</th>
            </tr>
          </thead>
          <tbody>
            @foreach($accounts as $account)
              @php
                $defaults = (array) ($account->defaults_for ?? []);
                $isTesting = $account->last_test_result === 'Testing';
                $isVerified = $account->last_test_result === 'OK';
                $hasError = ! $isTesting && ! $isVerified && filled($account->last_test_result);
              @endphp
              <tr data-telemetry="email_account_row" data-account-id="{{ $account->id }}">
                <td>
                  <a href="{{ route('tech.admin.settings.email.accounts.edit', $account) }}" class="fw-semibold text-decoration-none">{{ $account->address }}</a>
                  @if($account->description)<div class="text-muted small">{{ $account->description }}</div>@endif
                </td>
                <td>
                  <span class="badge text-bg-light">{{ \App\Modules\Email\Models\EmailAccount::KINDS[$account->account_kind] ?? ucfirst((string) $account->account_kind) }}</span>
                  @if($account->owner)<div class="text-muted small mt-1">{{ $account->owner->name }}</div>@endif
                  @if($account->ticket_ingress_enabled)<div class="small mt-1"><span class="badge text-bg-info">Ticket ingress</span></div>@endif
                </td>
                <td>
                  @if($isTesting)
                    <span class="badge text-bg-info">Testing</span>
                    <div class="small text-muted mt-1">Incoming and outgoing login</div>
                  @elseif($isVerified && $account->is_active)
                    <span class="badge text-bg-success">Active</span>
                    <div class="small text-muted mt-1">IMAP and SMTP passed</div>
                  @elseif($isVerified)
                    <span class="badge text-bg-secondary">Verified · inactive</span>
                  @elseif($hasError)
                    <span class="badge text-bg-danger">Connection failed</span>
                    <div class="small text-danger mt-1">{{ $account->last_error_message ?: 'Edit the settings and test again.' }}</div>
                  @else
                    <span class="badge text-bg-secondary">Not tested</span>
                  @endif
                </td>
                <td>
                  @php
                    $folders = $account->folders ?? collect();
                    $folderErrors = $folders->where('sync_status', \App\Modules\Email\Models\EmailFolder::SYNC_ERROR)->count();
                  @endphp
                  <div class="small"><span class="fw-semibold">{{ $folders->count() }}</span> <span class="text-muted">discovered</span></div>
                  @if($folderErrors > 0)<span class="badge text-bg-warning">{{ $folderErrors }} sync issue{{ $folderErrors === 1 ? '' : 's' }}</span>@endif
                </td>
                <td>
                  <div class="d-flex flex-wrap gap-2">
                    @if($account->is_global_default)<span class="badge text-bg-primary">Global</span>@endif
                    @foreach(\App\Modules\Email\Models\EmailAccount::DEFAULT_SCOPES as $scope => $label)
                      @continue(! in_array($scope, $defaults, true))
                      <span class="badge {{ $scope === 'alerts' ? 'text-bg-warning' : 'text-bg-info' }}">{{ $label }}</span>
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
                  @can('email.mailbox_sync_manage')
                    <a href="{{ route('tech.admin.settings.email.accounts.mailbox-maintenance', $account) }}" class="btn btn-outline-primary btn-sm">Maintenance</a>
                  @endcan
                  <a href="{{ route('tech.admin.settings.email.accounts.edit', $account) }}" class="btn btn-outline-secondary btn-sm">Edit</a>
                  @if($account->is_active || $isVerified)
                    <form action="{{ route('tech.admin.settings.email.accounts.toggle', $account) }}" method="POST" class="d-inline">
                      @csrf
                      <button type="submit" class="btn btn-sm {{ $account->is_active ? 'btn-outline-warning' : 'btn-outline-success' }}">
                        {{ $account->is_active ? 'Deactivate' : 'Activate' }}
                      </button>
                    </form>
                  @endif
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
    <x-card.default title="Connection checks">
      <p class="small text-muted mb-0">Saving an account securely tests authenticated IMAP and SMTP. A failed account remains inactive and can be edited and tested again.</p>
    </x-card.default>

    <x-card.default title="Ticket reply conflicts">
      <p class="small text-muted">Review inbound messages where RFC headers and Ticket keys identify different Tickets.</p>
      <a href="{{ route('tech.admin.settings.email.ticket-correlation-conflicts.index') }}" class="btn btn-sm btn-outline-warning">Review conflicts</a>
    </x-card.default>

    <x-card.default title="Templates">
      <p class="small text-muted">Manage outbound email templates for tickets, system notifications, and future workflows.</p>
      <a href="{{ route('tech.admin.system.templatesManagement.email.index') }}" class="btn btn-sm btn-outline-primary">Email templates</a>
    </x-card.default>
  </div>
@endsection
