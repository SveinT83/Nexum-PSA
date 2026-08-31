@extends('layouts.default_tech')

@section('title', 'Email Ticket correlation conflicts')

@section('pageHeader')
  <div class="d-flex align-items-center justify-content-between gap-3">
    <div>
      <h1>Email Ticket Correlation Conflicts</h1>
      <p class="text-muted mb-0">Resolve messages where independent evidence identifies different Tickets.</p>
    </div>
    <a href="{{ route('tech.admin.settings.email.accounts') }}" class="btn btn-outline-secondary">Email accounts</a>
  </div>
@endsection

@section('content')
  <div class="col-12">
    @if(session('status'))
      <div class="alert alert-success" role="status">{{ session('status') }}</div>
    @endif

    @if($conflicts->isEmpty())
      <div class="alert alert-success" role="status">
        No unresolved Email-to-Ticket correlation conflicts.
      </div>
    @else
      @foreach($conflicts as $conflict)
        <section class="card mb-3" data-conflict-id="{{ $conflict->id }}">
          <div class="card-header d-flex justify-content-between gap-3">
            <div>
              <strong>Email #{{ $conflict->email_message_id }}</strong>
              <span class="text-muted">{{ $conflict->message?->displaySubject() ?: '(No subject)' }}</span>
            </div>
            <span class="badge text-bg-warning">Needs decision</span>
          </div>
          <div class="card-body">
            <p class="small text-muted">
              Mailbox: {{ $conflict->message?->account?->address ?? 'Unavailable' }} ·
              Detected: {{ $conflict->detected_at?->format('Y-m-d H:i') }}
            </p>
            <p>Select the correct Ticket. Nexum will not attach this customer reply until the conflict is resolved.</p>

            <form method="POST" action="{{ route('tech.admin.settings.email.ticket-correlation-conflicts.resolve', $conflict) }}">
              @csrf
              <div class="row g-3">
                <div class="col-lg-7">
                  <label for="ticket-{{ $conflict->id }}" class="form-label">Ticket</label>
                  <select id="ticket-{{ $conflict->id }}" name="ticket_id" class="form-select @error('ticket_id') is-invalid @enderror" required>
                    <option value="">Select the evidence-backed Ticket</option>
                    @foreach($conflict->candidate_ticket_ids as $ticketId)
                      @php($ticket = $tickets->get((int) $ticketId))
                      @if($ticket)
                        <option value="{{ $ticket->id }}" @selected((int) old('ticket_id') === (int) $ticket->id)>
                          {{ $ticket->ticket_key }} — {{ $ticket->subject }}
                        </option>
                      @endif
                    @endforeach
                  </select>
                  @error('ticket_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                  <div class="form-text">
                    Evidence:
                    @foreach($conflict->evidence as $source => $ticketIds)
                      @continue(empty($ticketIds))
                      <span class="badge text-bg-light">{{ str_replace('_', ' ', $source) }} → {{ collect($ticketIds)->map(fn ($id) => $tickets->get((int) $id)?->ticket_key ?? '#'.$id)->implode(', ') }}</span>
                    @endforeach
                  </div>
                </div>
                <div class="col-lg-5">
                  <label for="reason-{{ $conflict->id }}" class="form-label">Decision reason</label>
                  <textarea id="reason-{{ $conflict->id }}" name="reason" class="form-control @error('reason') is-invalid @enderror" rows="2" required>{{ old('reason') }}</textarea>
                  @error('reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
              </div>
              <button type="submit" class="btn btn-primary mt-3">Link Email to selected Ticket</button>
            </form>
          </div>
        </section>
      @endforeach

      {{ $conflicts->links() }}
    @endif
  </div>
@endsection

@section('sidebar')
  <x-nav.admin-menu group="email" />
@endsection
