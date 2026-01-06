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
              <th scope="col" class="text-center" style="width: 140px;">Status</th>
              <th scope="col" style="width: 320px;">Defaults</th>
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
                      <div class="text-muted small">IMAP: {{ $account->imap_host }} • SMTP: {{ $account->smtp_host }}</div>
                    </div>
                  </div>
                </td>
                <td class="text-center">
                  @if($account->is_active)
                    <span class="badge rounded-pill text-bg-success" aria-label="Status Active">Active</span>
                  @else
                    <span class="badge rounded-pill text-bg-secondary" aria-label="Status Disabled">Disabled</span>
                  @endif
                </td>
                <td>
                  <div class="d-flex flex-wrap gap-2">
                    @if($account->is_global_default)
                      <span class="badge text-bg-primary" aria-label="Default Global">Default (Global)</span>
                    @endif
                    @if(in_array('tickets', $defaults))
                      <span class="badge text-bg-info" aria-label="Default Tickets">Default (Tickets)</span>
                    @endif
                    @if(in_array('sales', $defaults))
                      <span class="badge text-bg-info" aria-label="Default Sales">Default (Sales)</span>
                    @endif
                    @if(in_array('alerts', $defaults))
                      <span class="badge text-bg-warning" aria-label="Default Alerts">&#9888; Default (Alerts)</span>
                    @endif
                  </div>
                </td>
                <td class="text-end">
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
  <h3 class="h5 mt-3">Tech Sidebar</h3>
  <ul class="list-unstyled small">
    <li><a href="#">System Status</a></li>
    <li><a href="#">Task Management</a></li>
    <li><a href="#">Reports</a></li>
  </ul>
@endsection

@section('rightbar')
  <div class="mt-3">
    <h3 class="h6">Notifications</h3>
    <ul class="list-unstyled small">
      <li>No new notifications.</li>
    </ul>
  </div>
@endsection