<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Call Intake - Nexum PSA</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
</head>
<body class="bg-light">
<main class="container py-4">
    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    @if($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">Call Intake</h1>
            <div class="text-muted">
                {{ $call->caller_number_normalized ?: $call->caller_number_raw ?: 'Unknown caller' }}
                <span class="mx-1">•</span>
                {{ $call->answered_at?->format('Y-m-d H:i') }}
            </div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            @if($call->is_test)
                <span class="badge text-bg-warning border align-self-center">Test call</span>
            @endif
            @if($call->linkedTicket)
                <a href="{{ route('tech.tickets.show', $call->linkedTicket) }}" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-ticket-detailed" aria-hidden="true"></i>
                    {{ $call->linkedTicket->ticket_key }}
                </a>
            @endif
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-4">
            <div class="bg-white border rounded p-3 h-100">
                <h2 class="h5">Caller</h2>
                <dl class="row mb-0">
                    <dt class="col-sm-5">Number</dt>
                    <dd class="col-sm-7">{{ $call->caller_number_normalized ?: $call->caller_number_raw ?: '—' }}</dd>
                    <dt class="col-sm-5">Contact</dt>
                    <dd class="col-sm-7">{{ $call->contact?->display_name ?? 'No match' }}</dd>
                    <dt class="col-sm-5">Client</dt>
                    <dd class="col-sm-7">{{ $call->client?->name ?? '—' }}</dd>
                    <dt class="col-sm-5">Site</dt>
                    <dd class="col-sm-7">{{ $call->site?->name ?? '—' }}</dd>
                    <dt class="col-sm-5">Technician</dt>
                    <dd class="col-sm-7">{{ $call->answeredBy?->name ?? '—' }}</dd>
                </dl>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="bg-white border rounded p-3 h-100">
                <h2 class="h5">Call Note</h2>
                <form method="POST" action="{{ route('telephony.intake.calls.note', ['token' => $token, 'call' => $call]) }}">
                    @csrf
                    <textarea name="notes" rows="6" class="form-control mb-3" placeholder="Write the call note here...">{{ old('notes', $call->notes) }}</textarea>
                    <button type="submit" class="btn btn-outline-primary">
                        <i class="bi bi-save" aria-hidden="true"></i>
                        Save note
                    </button>
                </form>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="bg-white border rounded p-3 h-100">
                <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                    <h2 class="h5 mb-0">Open Tickets</h2>
                    <span class="badge text-bg-light border">{{ $openTickets->count() }}</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Ticket</th>
                                <th>Status</th>
                                <th>Updated</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($openTickets as $ticket)
                                <tr>
                                    <td>
                                        <a href="{{ route('tech.tickets.show', $ticket) }}" class="fw-semibold text-decoration-none">{{ $ticket->ticket_key }}</a>
                                        <div class="small text-muted">{{ $ticket->subject }}</div>
                                    </td>
                                    <td>{{ $ticket->status?->name ?? '—' }}</td>
                                    <td>{{ $ticket->updated_at?->diffForHumans() }}</td>
                                    <td class="text-end">
                                        <form method="POST" action="{{ route('telephony.intake.calls.link-ticket', ['token' => $token, 'call' => $call]) }}">
                                            @csrf
                                            <input type="hidden" name="ticket_key" value="{{ $ticket->ticket_key }}">
                                            <input type="hidden" name="note" value="{{ $call->notes }}">
                                            <button type="submit" class="btn btn-sm btn-outline-secondary">Link</button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-4">No related open tickets.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="bg-white border rounded p-3 h-100">
                <h2 class="h5">Create Ticket</h2>
                <form method="POST" action="{{ route('telephony.intake.calls.ticket', ['token' => $token, 'call' => $call]) }}">
                    @csrf
                    <label class="form-label">Subject</label>
                    <input type="text" name="subject" class="form-control mb-2" value="{{ old('subject', 'Phone call from '.($call->contact?->display_name ?: $call->caller_number_normalized ?: $call->caller_number_raw)) }}">
                    <label class="form-label">Description</label>
                    <textarea name="description" rows="6" class="form-control mb-3">{{ old('description', $call->notes) }}</textarea>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-plus-circle" aria-hidden="true"></i>
                        Create ticket
                    </button>
                </form>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="bg-white border rounded p-3">
                <h2 class="h5">Recent Closed Tickets</h2>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Ticket</th>
                                <th>Status</th>
                                <th>Updated</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($recentClosedTickets as $ticket)
                                <tr>
                                    <td>
                                        <a href="{{ route('tech.tickets.show', $ticket) }}" class="fw-semibold text-decoration-none">{{ $ticket->ticket_key }}</a>
                                        <div class="small text-muted">{{ $ticket->subject }}</div>
                                    </td>
                                    <td>{{ $ticket->status?->name ?? '—' }}</td>
                                    <td>{{ $ticket->updated_at?->format('Y-m-d H:i') }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="text-center text-muted py-4">No related closed tickets.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="bg-white border rounded p-3">
                <h2 class="h5">Link Ticket By Key</h2>
                <form method="POST" action="{{ route('telephony.intake.calls.link-ticket', ['token' => $token, 'call' => $call]) }}">
                    @csrf
                    <label class="form-label">Ticket key</label>
                    <input type="text" name="ticket_key" class="form-control mb-2" placeholder="TD-2026-000001" required>
                    <label class="form-label">Note to copy</label>
                    <textarea name="note" rows="4" class="form-control mb-3">{{ old('note', $call->notes) }}</textarea>
                    <button type="submit" class="btn btn-outline-primary">
                        <i class="bi bi-link-45deg" aria-hidden="true"></i>
                        Link ticket
                    </button>
                </form>
            </div>
        </div>
    </div>
</main>
</body>
</html>
