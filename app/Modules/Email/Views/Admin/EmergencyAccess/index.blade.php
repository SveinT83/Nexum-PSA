@extends('layouts.default_tech')

@section('title', 'Emergency Mailbox Access')

@section('pageHeader')
    <h1>Emergency mailbox access</h1>
@endsection

@section('sidebar')
    <x-nav.admin-menu group="email" title="Admin areas" local-title="Email settings" />
@endsection

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Emergency Access Warning And Status -->
    <!-- ------------------------------------------------- -->
    @if(session('status'))
        <div class="alert alert-success py-2" role="status">{{ session('status') }}</div>
    @endif

    @if($errors->any())
        <div class="alert alert-danger py-2" role="alert">
            <ul class="mb-0 ps-3">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="alert alert-danger" role="alert">
        <div class="fw-semibold mb-1"><i class="bi bi-shield-exclamation me-1" aria-hidden="true"></i>Privacy-impacting emergency action</div>
        Use this only for a named incident. Activation is time-bounded, audited, and notifies the active mailbox owner and active security recipients. It never permits sending, organizing, exporting, deleting, account configuration, rules, AI, Smart Inbox, or Ticket actions.
    </div>

    <!-- ------------------------------------------------- -->
    <!-- Emergency Activation Form -->
    <!-- ------------------------------------------------- -->
    <section class="card mb-3">
        <div class="card-header py-2 fw-semibold">Activate exact emergency access</div>
        <div class="card-body py-3">
            @if($accounts->isEmpty())
                <p class="text-muted mb-0">No active personal mailbox is available.</p>
            @else
                <form method="post" action="{{ route('tech.admin.settings.email.emergency-access.store') }}">
                    @csrf
                    <div class="row g-2">
                        <div class="col-12 col-lg-4">
                            <label for="emergency-account" class="form-label small fw-semibold mb-1">Personal mailbox</label>
                            <select id="emergency-account" name="account_id" class="form-select form-select-sm" required>
                                <option value="">Choose mailbox</option>
                                @foreach($accounts as $account)
                                    <option value="{{ $account->id }}" @selected((int) old('account_id') === (int) $account->id)>
                                        {{ $account->address }} — {{ $account->owner?->name ?? 'Unavailable owner' }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-12 col-lg-4">
                            <label for="emergency-confirmation" class="form-label small fw-semibold mb-1">Type the exact mailbox address</label>
                            <input id="emergency-confirmation" type="text" name="account_confirmation" class="form-control form-control-sm" maxlength="255" value="{{ old('account_confirmation') }}" autocomplete="off" required>
                        </div>
                        <div class="col-12 col-lg-2">
                            <label for="emergency-duration" class="form-label small fw-semibold mb-1">Duration</label>
                            <select id="emergency-duration" name="duration_minutes" class="form-select form-select-sm" required>
                                @foreach([15, 30, 60, 120] as $minutes)
                                    <option value="{{ $minutes }}" @selected((int) old('duration_minutes', 30) === $minutes)>{{ $minutes }} minutes</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-12 col-lg-2">
                            <label class="form-label small fw-semibold mb-1" for="emergency-reason">Incident reason</label>
                            <input id="emergency-reason" type="text" name="reason" class="form-control form-control-sm" maxlength="2000" value="{{ old('reason') }}" required>
                        </div>
                    </div>

                    <fieldset class="mt-3">
                        <legend class="small fw-semibold mb-2">Exact content operations</legend>
                        <div class="d-flex flex-wrap gap-3">
                            @foreach([
                                'can_view_content' => 'Content view',
                                'can_search' => 'Search',
                                'can_download_attachments' => 'Attachment download',
                            ] as $field => $label)
                                <div class="form-check">
                                    <input id="{{ $field }}" class="form-check-input" type="checkbox" name="{{ $field }}" value="1" @checked(old($field))>
                                    <label for="{{ $field }}" class="form-check-label small">{{ $label }}</label>
                                </div>
                            @endforeach
                            @can('email.raw_source_view')
                                <div class="form-check">
                                    <input id="can_view_raw_source" class="form-check-input" type="checkbox" name="can_view_raw_source" value="1" @checked(old('can_view_raw_source'))>
                                    <label for="can_view_raw_source" class="form-check-label small">Raw source</label>
                                </div>
                            @endcan
                        </div>
                    </fieldset>

                    <div class="d-flex justify-content-end mt-3">
                        <button type="submit" class="btn btn-danger">
                            <i class="bi bi-shield-lock me-1" aria-hidden="true"></i>
                            Activate and notify
                        </button>
                    </div>
                </form>
            @endif
        </div>
    </section>

    <!-- ------------------------------------------------- -->
    <!-- Current Emergency Access -->
    <!-- ------------------------------------------------- -->
    <section class="card mb-3">
        <div class="card-header d-flex align-items-center justify-content-between gap-2 py-2">
            <span class="fw-semibold">Active emergency access</span>
            <span class="badge {{ $activeAccesses->isEmpty() ? 'text-bg-light border' : 'text-bg-danger' }}">{{ $activeAccesses->count() }}</span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th>Mailbox</th><th>Actor</th><th>Operations</th><th>Reason</th><th>Expires</th><th class="text-end">Action</th></tr></thead>
                <tbody>
                    @forelse($activeAccesses as $access)
                        @php($operations = array_keys(array_filter([
                            'Content view' => $access->can_view_content,
                            'Search' => $access->can_search,
                            'Attachment download' => $access->can_download_attachments,
                            'Raw source' => $access->can_view_raw_source,
                        ])))
                        <tr>
                            <td>{{ $access->account?->address ?? 'Unavailable' }}</td>
                            <td>{{ $access->actor?->name ?? 'Unavailable actor' }}</td>
                            <td class="small">{{ implode(', ', $operations) }}</td>
                            <td class="small">{{ $access->reason }}</td>
                            <td class="small text-nowrap">{{ $access->expires_at?->format('Y-m-d H:i') }}</td>
                            <td class="text-end">
                                <form method="post" action="{{ route('tech.admin.settings.email.emergency-access.revoke', $access->id) }}" class="d-flex justify-content-end gap-1">
                                    @csrf
                                    <input type="text" name="reason" class="form-control form-control-sm" style="max-width: 12rem" maxlength="2000" required placeholder="Revocation reason" aria-label="Emergency access revocation reason">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Revoke</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-muted py-3 text-center">No active emergency access.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <!-- ------------------------------------------------- -->
    <!-- Operator's Recent Access -->
    <!-- ------------------------------------------------- -->
    <section class="card">
        <div class="card-header d-flex align-items-center justify-content-between gap-2 py-2">
            <span class="fw-semibold">My recent emergency records</span>
            <span class="badge text-bg-light border">{{ $recentAccesses->count() }}</span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th>Mailbox</th><th>Started</th><th>Expires</th><th>State</th></tr></thead>
                <tbody>
                    @forelse($recentAccesses as $access)
                        <tr>
                            <td>{{ $access->account?->address ?? 'Unavailable' }}</td>
                            <td>{{ $access->starts_at?->format('Y-m-d H:i') }}</td>
                            <td>{{ $access->expires_at?->format('Y-m-d H:i') }}</td>
                            <td>
                                @if($access->revoked_at)
                                    <span class="badge text-bg-secondary">Revoked</span>
                                @elseif($access->expires_at?->isPast())
                                    <span class="badge text-bg-light border">Expired</span>
                                @else
                                    <span class="badge text-bg-danger">Active</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-muted py-3 text-center">No emergency access history for this operator.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection

@section('rightbar')
    <div class="d-grid gap-2">
        @can('email.break_glass_audit')
            <a href="{{ route('tech.mail.access.history') }}" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-clock-history me-1" aria-hidden="true"></i>
                Access history
            </a>
        @endcan
        <div class="alert alert-secondary small mb-0">
            Every activation and actual emergency content use is reauthorized. Audit failure blocks the content action.
        </div>
    </div>
@endsection
