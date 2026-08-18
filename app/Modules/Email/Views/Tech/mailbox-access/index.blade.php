@extends('layouts.default_tech')

@section('title', 'Mailbox Access')

@section('pageHeader')
    <h1>Mailbox access</h1>
@endsection

@section('sidebar')
    @include('email::Tech.mailbox-access.partials.sidebar')
@endsection

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Mailbox Access Status -->
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

    @if($activeBreakGlass->isNotEmpty())
        <div class="alert alert-danger" role="alert">
            <div class="fw-semibold mb-2">
                <i class="bi bi-shield-exclamation me-1" aria-hidden="true"></i>
                Emergency access is active
            </div>
            @foreach($activeBreakGlass as $access)
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 {{ $loop->last ? '' : 'mb-2 pb-2 border-bottom' }}">
                    <div class="small">
                        <strong>{{ $access->actor?->name ?? 'Unavailable actor' }}</strong>
                        has emergency access to {{ $access->account?->address ?? 'this mailbox' }}
                        until {{ $access->expires_at?->format('Y-m-d H:i') }}.
                    </div>
                    <form method="post" action="{{ route('tech.mail.access.emergency.revoke', $access->id) }}" class="d-flex gap-2">
                        @csrf
                        <input type="text" name="reason" class="form-control form-control-sm" maxlength="2000" required placeholder="Revocation reason" aria-label="Emergency access revocation reason">
                        <button type="submit" class="btn btn-sm btn-danger">Revoke</button>
                    </form>
                </div>
            @endforeach
        </div>
    @endif

    <!-- ------------------------------------------------- -->
    <!-- Personal Mailbox Delegation Forms -->
    <!-- ------------------------------------------------- -->
    @forelse($accounts as $account)
        <section class="card mb-3">
            <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2 py-2">
                <div>
                    <span class="fw-semibold">{{ $account->address }}</span>
                    <span class="badge {{ $account->is_active ? 'text-bg-success' : 'text-bg-secondary' }} ms-1">
                        {{ $account->is_active ? 'Active' : 'Inactive' }}
                    </span>
                </div>
                <div class="d-flex align-items-center gap-2">
                    @if($account->is_active)
                        <a href="{{ route('tech.mail.unread-handover.index', $account) }}" class="btn btn-sm btn-outline-primary">
                            Unread handover
                        </a>
                    @endif
                    <span class="small text-muted">Maximum 31 days</span>
                </div>
            </div>
            <div class="card-body py-3">
                @if(!$account->is_active)
                    <p class="text-muted mb-0">This mailbox is inactive. Existing history remains visible, but new delegation is unavailable.</p>
                @elseif($delegates->isEmpty())
                    <p class="text-muted mb-0">No other active human users are available for delegation.</p>
                @else
                    <form method="post" action="{{ route('tech.mail.access.store', $account->id) }}">
                        @csrf
                        <div class="row g-2">
                            <div class="col-12 col-lg-4">
                                <label for="delegate-{{ $account->id }}" class="form-label small fw-semibold mb-1">Delegate</label>
                                <select id="delegate-{{ $account->id }}" name="delegate_id" class="form-select form-select-sm" required>
                                    <option value="">Choose user</option>
                                    @foreach($delegates as $delegate)
                                        <option value="{{ $delegate->id }}">{{ $delegate->name }} — {{ $delegate->email }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-6 col-lg-2">
                                <label for="starts-{{ $account->id }}" class="form-label small fw-semibold mb-1">Starts</label>
                                <input id="starts-{{ $account->id }}" type="datetime-local" name="starts_at" class="form-control form-control-sm" value="{{ now()->format('Y-m-d\TH:i') }}" required>
                            </div>
                            <div class="col-6 col-lg-2">
                                <label for="expires-{{ $account->id }}" class="form-label small fw-semibold mb-1">Expires</label>
                                <input id="expires-{{ $account->id }}" type="datetime-local" name="expires_at" class="form-control form-control-sm" value="{{ now()->addDays(7)->format('Y-m-d\TH:i') }}" required>
                            </div>
                            <div class="col-12 col-lg-4">
                                <label for="reason-{{ $account->id }}" class="form-label small fw-semibold mb-1">Reason</label>
                                <input id="reason-{{ $account->id }}" type="text" name="reason" class="form-control form-control-sm" maxlength="2000" required placeholder="Why this access is needed">
                            </div>
                        </div>

                        <fieldset class="mt-3">
                            <legend class="small fw-semibold mb-2">Exact operations</legend>
                            <div class="d-flex flex-wrap gap-3">
                                @foreach([
                                    'can_view' => 'View',
                                    'can_organize' => 'Organize',
                                    'can_send' => 'Send',
                                ] as $field => $label)
                                    <div class="form-check">
                                        <input id="{{ $field }}-{{ $account->id }}" class="form-check-input" type="checkbox" name="{{ $field }}" value="1" @checked($field === 'can_view')>
                                        <label class="form-check-label small" for="{{ $field }}-{{ $account->id }}">{{ $label }}</label>
                                    </div>
                                @endforeach
                                @can('email.raw_source_view')
                                    <div class="form-check">
                                        <input id="can_view_raw_source-{{ $account->id }}" class="form-check-input" type="checkbox" name="can_view_raw_source" value="1" @checked(old('can_view_raw_source'))>
                                        <label class="form-check-label small" for="can_view_raw_source-{{ $account->id }}">Raw source</label>
                                    </div>
                                @endcan
                            </div>
                        </fieldset>

                        <div class="d-flex justify-content-end mt-3">
                            <button type="submit" class="btn btn-sm btn-primary">
                                <i class="bi bi-person-plus me-1" aria-hidden="true"></i>
                                Create delegation
                            </button>
                        </div>
                    </form>
                @endif
            </div>
        </section>
    @empty
        <div class="alert alert-info">You do not currently own a personal mailbox.</div>
    @endforelse

    <!-- ------------------------------------------------- -->
    <!-- Recent Delegations -->
    <!-- ------------------------------------------------- -->
    <section class="card">
        <div class="card-header d-flex align-items-center justify-content-between gap-2 py-2">
            <span class="fw-semibold">Current and recent delegations</span>
            <span class="badge text-bg-light border">{{ $delegations->count() }}</span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>Mailbox</th>
                        <th>Delegate</th>
                        <th>Operations</th>
                        <th>Window</th>
                        <th>Reason</th>
                        <th class="text-end">State</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($delegations as $delegation)
                        @php
                            $operations = array_keys(array_filter([
                                'View' => $delegation->can_view,
                                'Organize' => $delegation->can_organize,
                                'Send' => $delegation->can_send,
                                'Raw source' => $delegation->can_view_raw_source,
                            ]));
                            $isCurrent = $delegation->isEffectiveAt();
                        @endphp
                        <tr>
                            <td>{{ $delegation->account?->address ?? 'Unavailable' }}</td>
                            <td>{{ $delegation->delegate?->name ?? 'Unavailable user' }}</td>
                            <td class="small">{{ implode(', ', $operations) }}</td>
                            <td class="small text-nowrap">
                                {{ $delegation->starts_at?->format('Y-m-d H:i') }}<br>
                                <span class="text-muted">to {{ $delegation->expires_at?->format('Y-m-d H:i') }}</span>
                            </td>
                            <td class="small">{{ $delegation->reason }}</td>
                            <td class="text-end">
                                @if($delegation->revoked_at)
                                    <span class="badge text-bg-secondary">Revoked</span>
                                @elseif($delegation->expires_at?->isPast())
                                    <span class="badge text-bg-light border">Expired</span>
                                @else
                                    <span class="badge {{ $isCurrent ? 'text-bg-success' : 'text-bg-info' }}">{{ $isCurrent ? 'Active' : 'Scheduled' }}</span>
                                    <form method="post" action="{{ route('tech.mail.access.delegations.revoke', [$delegation->email_account_id, $delegation->id]) }}" class="d-flex justify-content-end gap-1 mt-1">
                                        @csrf
                                        <input type="text" name="reason" class="form-control form-control-sm" style="max-width: 12rem" maxlength="2000" required placeholder="Revocation reason" aria-label="Delegation revocation reason">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Revoke</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-muted py-3 text-center">No delegation history yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection

@section('rightbar')
    <div class="accordion" id="mailbox-access-help">
        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#mailbox-access-privacy" aria-expanded="false" aria-controls="mailbox-access-privacy">
                    Privacy and expiry
                </button>
            </h2>
            <div id="mailbox-access-privacy" class="accordion-collapse collapse" data-bs-parent="#mailbox-access-help">
                <div class="accordion-body small text-muted">
                    Delegation never gives account configuration rights. Global Email permissions still apply, and access stops immediately when revoked, expired, or disabled.
                </div>
            </div>
        </div>
    </div>
@endsection
