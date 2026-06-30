@extends('layouts.default_tech')

@section('title', 'Telephony')

@section('pageHeader')
    <div class="d-flex align-items-center justify-content-between gap-3">
        <h1 class="h4 mb-0">Telephony</h1>
    </div>
@endsection

@section('content')
    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    <div class="card mb-3">
        <div class="card-header">
            <span class="fw-semibold">Personal Intake URL</span>
        </div>
        <div class="card-body">
            <label for="telephony_intake_url" class="form-label">Provider URL</label>
            <input id="telephony_intake_url" type="text" class="form-control font-monospace" value="{{ $token->intakeUrl() }}?caller=%no" readonly>
            <div class="d-flex flex-wrap gap-2 mt-3">
                <a href="{{ $token->intakeUrl() }}?caller=99999999&test=1" class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener">
                    <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>
                    Test URL
                </a>
                <form method="POST" action="{{ route('tech.telephony.profile.token.rotate') }}">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Rotate your telephony intake URL? Existing provider URLs must be updated afterwards.')">
                        <i class="bi bi-arrow-repeat" aria-hidden="true"></i>
                        Rotate token
                    </button>
                </form>
            </div>
            <dl class="row small text-muted mt-3 mb-0">
                <dt class="col-md-3">Last used</dt>
                <dd class="col-md-9">{{ $token->last_used_at?->format('Y-m-d H:i') ?? 'Never' }}</dd>
                <dt class="col-md-3">Last rotated</dt>
                <dd class="col-md-9">{{ $token->rotated_at?->format('Y-m-d H:i') ?? 'Never' }}</dd>
            </dl>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <span class="fw-semibold">Recent Calls</span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Caller</th>
                        <th>Client</th>
                        <th>Ticket</th>
                        <th>Status</th>
                        <th>Answered</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($recentCalls as $call)
                        <tr>
                            <td>
                                <div class="fw-semibold">{{ $call->caller_number_normalized ?: $call->caller_number_raw ?: 'Unknown' }}</div>
                                <div class="small text-muted">{{ $call->contact?->display_name ?? 'No contact match' }}</div>
                            </td>
                            <td>{{ $call->client?->name ?? '—' }}</td>
                            <td>
                                @if($call->linkedTicket)
                                    <a href="{{ route('tech.tickets.show', $call->linkedTicket) }}">{{ $call->linkedTicket->ticket_key }}</a>
                                @else
                                    —
                                @endif
                            </td>
                            <td><span class="badge text-bg-light border">{{ ucfirst($call->status) }}</span></td>
                            <td>{{ $call->answered_at?->format('Y-m-d H:i') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">No calls recorded yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
