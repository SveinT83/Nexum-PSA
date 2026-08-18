@extends('layouts.default_tech')

@section('title', 'Canonical cutover run #'.$run->id)

@section('pageHeader')
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
    <h1>Canonical cutover run #{{ $run->id }}</h1>
    <a class="btn btn-outline-secondary" href="{{ route('tech.admin.settings.email.canonical-cutover.index') }}">All previews</a>
</div>
@endsection

@section('sidebar')
<x-nav.admin-menu group="email" />
@endsection

@section('content')
<div class="container-fluid">
    @if(session('success'))
        <div class="alert alert-success" role="status">{{ session('success') }}</div>
    @endif

    {{-- Frozen preview and explicit state transition controls --}}
    <section class="card shadow-sm mb-4" aria-labelledby="cutover-summary-heading">
        <div class="card-body">
            <h2 class="h5" id="cutover-summary-heading">Frozen local operation</h2>
            <dl class="row mb-0">
                <dt class="col-sm-3">Operation</dt><dd class="col-sm-9">{{ $run->operation }}</dd>
                <dt class="col-sm-3">Status</dt><dd class="col-sm-9"><span class="badge text-bg-secondary">{{ $run->status }}</span></dd>
                <dt class="col-sm-3">Algorithm</dt><dd class="col-sm-9">{{ $run->algorithm_version }}</dd>
                <dt class="col-sm-3">Accounts</dt>
                <dd class="col-sm-9">
                    @foreach($accounts as $account)
                        <span class="badge text-bg-light border text-body">{{ $account->address }}</span>
                    @endforeach
                </dd>
                <dt class="col-sm-3">Message-ID window</dt><dd class="col-sm-9">{{ $run->frozen_min_message_id ?? '—' }}–{{ $run->frozen_max_message_id ?? '—' }}</dd>
                <dt class="col-sm-3">Items</dt><dd class="col-sm-9">{{ $run->item_count }} / cap {{ $run->item_cap }}</dd>
                @if($run->requested_mode)
                    <dt class="col-sm-3">Requested mode</dt><dd class="col-sm-9">{{ $run->requested_mode }}</dd>
                @endif
                @if($run->source_correlation_run_id)
                    <dt class="col-sm-3">Shadow run</dt><dd class="col-sm-9">#{{ $run->source_correlation_run_id }}</dd>
                @endif
            </dl>

            @if($run->error_code)
                <div class="alert alert-warning mt-3 mb-0">The operation stopped with stable code <code>{{ $run->error_code }}</code>. Create a fresh preview after resolving drift.</div>
            @endif

            @if($run->status === \App\Modules\Email\Models\EmailCanonicalCutoverRun::STATUS_PREVIEWED)
                <form method="post" action="{{ route('tech.admin.settings.email.canonical-cutover.apply', $run) }}" class="row g-2 align-items-end mt-3">
                    @csrf
                    <div class="col-sm-8 col-lg-5">
                        <label class="form-label" for="cutover-apply-confirmation">Type <code>APPLY RUN #{{ $run->id }}</code></label>
                        <input class="form-control" id="cutover-apply-confirmation" name="confirmation" required autocomplete="off">
                    </div>
                    <div class="col-auto"><button class="btn btn-primary" type="submit">Apply exact preview</button></div>
                </form>
            @elseif($run->status === \App\Modules\Email\Models\EmailCanonicalCutoverRun::STATUS_APPLIED)
                <form method="post" action="{{ route('tech.admin.settings.email.canonical-cutover.rollback', $run) }}" class="row g-2 align-items-end mt-3">
                    @csrf
                    <div class="col-sm-8 col-lg-5">
                        <label class="form-label" for="cutover-rollback-confirmation">Type <code>ROLLBACK RUN #{{ $run->id }}</code></label>
                        <input class="form-control" id="cutover-rollback-confirmation" name="confirmation" required autocomplete="off">
                    </div>
                    <div class="col-auto"><button class="btn btn-outline-danger" type="submit">Rollback exact run</button></div>
                </form>
            @endif
        </div>
    </section>

    {{-- Metadata-only item audit: canonical IDs, content, headers, filenames, and paths stay internal. --}}
    <section class="card shadow-sm" aria-labelledby="cutover-items-heading">
        <div class="card-body">
            <h2 class="h5" id="cutover-items-heading">Durable items</h2>
            <p class="small text-body-secondary">Source occurrence identity is shown for audit. Canonical identifiers and private content are deliberately not exposed.</p>
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead>
                        <tr>
                            <th scope="col">Item</th>
                            <th scope="col">Kind</th>
                            <th scope="col">Account</th>
                            <th scope="col">Source</th>
                            <th scope="col">Component</th>
                            <th scope="col">Evidence</th>
                            <th scope="col">Mode</th>
                            <th scope="col">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($items as $item)
                            <tr>
                                <td>#{{ $item->id }}</td>
                                <td>{{ $item->item_kind }}</td>
                                <td>{{ $item->email_account_id }}</td>
                                <td>{{ $item->source_email_message_id ? '#'.$item->source_email_message_id : '—' }}</td>
                                <td><code>{{ $item->component_key ? substr($item->component_key, 0, 12).'…' : '—' }}</code></td>
                                <td>{{ $item->evidence_complete ? 'complete' : 'incomplete' }}</td>
                                <td>{{ $item->previous_read_mode && $item->proposed_read_mode ? $item->previous_read_mode.' → '.$item->proposed_read_mode : '—' }}</td>
                                <td>
                                    <span class="badge text-bg-secondary">{{ $item->status }}</span>
                                    @if($item->error_code)<code class="d-block">{{ $item->error_code }}</code>@endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="text-body-secondary">This valid preview has no changes to apply.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            {{ $items->links() }}
        </div>
    </section>
</div>
@endsection
