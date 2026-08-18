@extends('layouts.default_tech')

@section('title', 'Canonical correlation run #'.$run->id)

@section('pageHeader')
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
    <h1>Canonical correlation run #{{ $run->id }}</h1>
    <a class="btn btn-outline-secondary" href="{{ route('tech.admin.settings.email.correlation.index') }}">All runs</a>
</div>
@endsection

@section('sidebar')
<x-nav.admin-menu group="email" />
@endsection

@section('content')
<div class="container-fluid">
    {{-- Run summary --}}
    <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">
        <div>
            <p class="text-body-secondary mb-0">Metadata-only shadow report · algorithm {{ $run->algorithm_version }}</p>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success" role="status">{{ session('success') }}</div>
    @endif

    <section class="card shadow-sm mb-4" aria-labelledby="canonical-run-summary-heading">
        <div class="card-body">
            <h2 class="h5" id="canonical-run-summary-heading">Frozen scope</h2>
            <dl class="row mb-0">
                <dt class="col-sm-3">Status</dt><dd class="col-sm-9">{{ $run->status }}</dd>
                <dt class="col-sm-3">Accounts</dt>
                <dd class="col-sm-9">
                    @foreach($accounts as $account)
                        <span class="badge text-bg-light border text-body">{{ $account->address }}</span>
                    @endforeach
                </dd>
                <dt class="col-sm-3">Frozen message-ID window</dt><dd class="col-sm-9">{{ $run->frozen_min_message_id }}–{{ $run->frozen_max_message_id }}</dd>
                <dt class="col-sm-3">Scoped messages</dt><dd class="col-sm-9">{{ $run->scoped_message_count }}</dd>
                <dt class="col-sm-3">Groups / pairs</dt><dd class="col-sm-9">{{ $run->groups_processed }} / {{ $run->pairs_processed }}</dd>
                <dt class="col-sm-3">Classes</dt>
                <dd class="col-sm-9">
                    Strong {{ $run->strong_count }}, possible {{ $run->possible_count }}, ambiguous {{ $run->ambiguous_count }}, different {{ $run->different_count }}
                </dd>
            </dl>

            @if($run->error_code)
                <div class="alert alert-warning mt-3 mb-0">
                    <strong>{{ $run->error_code }}</strong>: {{ $run->error_message }}
                </div>
            @endif

            <div class="d-flex flex-wrap gap-2 mt-3">
                @if($run->status === \App\Modules\Email\Models\EmailCanonicalCorrelationRun::STATUS_FAILED)
                    <form method="post" action="{{ route('tech.admin.settings.email.correlation.resume', $run) }}">
                        @csrf
                        <button class="btn btn-outline-primary" type="submit">Resume from durable cursor</button>
                    </form>
                @endif
                @if(in_array($run->status, [
                    \App\Modules\Email\Models\EmailCanonicalCorrelationRun::STATUS_QUEUED,
                    \App\Modules\Email\Models\EmailCanonicalCorrelationRun::STATUS_RUNNING,
                    \App\Modules\Email\Models\EmailCanonicalCorrelationRun::STATUS_FAILED,
                ], true))
                    <form method="post" action="{{ route('tech.admin.settings.email.correlation.cancel', $run) }}">
                        @csrf
                        <button class="btn btn-outline-danger" type="submit">Cancel run</button>
                    </form>
                @endif
            </div>
        </div>
    </section>

    {{-- Candidate rows intentionally expose no subject, participant, filename, body, header, or path. --}}
    <section class="card shadow-sm" aria-labelledby="canonical-candidates-heading">
        <div class="card-body">
            <h2 class="h5" id="canonical-candidates-heading">Shadow candidates</h2>
            <p class="small text-body-secondary">
                Opaque IDs and reason codes are evidence for review; no current Mail read path uses these decisions.
            </p>

            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead>
                        <tr>
                            <th scope="col">Candidate</th>
                            <th scope="col">Account pair</th>
                            <th scope="col">Class</th>
                            <th scope="col">Reasons</th>
                            <th scope="col">Review</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($candidates as $candidate)
                            <tr>
                                <td>#{{ $candidate->id }}</td>
                                <td>{{ $candidate->left_email_account_id }} → {{ $candidate->right_email_account_id }}</td>
                                <td><span class="badge text-bg-secondary">{{ $candidate->candidate_class }}</span></td>
                                <td>
                                    @foreach($candidate->reason_codes_json ?? [] as $reason)
                                        <code class="d-block">{{ $reason }}</code>
                                    @endforeach
                                </td>
                                <td style="min-width: 18rem">
                                    <a class="btn btn-sm btn-outline-secondary mb-2"
                                       href="{{ route('tech.admin.settings.email.correlation.candidates.inspect', $candidate) }}">
                                        Inspect exact messages
                                    </a>
                                    @if($candidate->review_state !== \App\Modules\Email\Models\EmailCanonicalCorrelationCandidate::REVIEW_UNREVIEWED)
                                        <strong>{{ $candidate->review_state }}</strong>
                                        <div class="small text-body-secondary">{{ $candidate->review_reason_code }}</div>
                                    @else
                                        <form method="post" action="{{ route('tech.admin.settings.email.correlation.candidates.review', $candidate) }}" class="vstack gap-2">
                                            @csrf
                                            <label class="visually-hidden" for="candidate-state-{{ $candidate->id }}">Review state</label>
                                            <select class="form-select form-select-sm" id="candidate-state-{{ $candidate->id }}" name="review_state" required>
                                                <option value="needs_more_evidence">Needs more evidence</option>
                                                @if($candidate->inspected_by_current_user)
                                                    <option value="keep_separate">Keep separate</option>
                                                    @if($candidate->candidate_class !== \App\Modules\Email\Models\EmailCanonicalCorrelationCandidate::CLASS_OVERSIZED)
                                                        <option value="confirmed_candidate">Confirmed candidate</option>
                                                    @endif
                                                @endif
                                            </select>
                                            <label class="visually-hidden" for="candidate-reason-{{ $candidate->id }}">Stable reason code</label>
                                            <input class="form-control form-control-sm" id="candidate-reason-{{ $candidate->id }}" name="reason_code" pattern="[a-z0-9_:-]{1,80}" maxlength="80" placeholder="stable_reason_code" required>
                                            <button class="btn btn-sm btn-outline-primary" type="submit">Record shadow review</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-body-secondary">No candidate rows have been recorded.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $candidates->links() }}
        </div>
    </section>
</div>
@endsection
