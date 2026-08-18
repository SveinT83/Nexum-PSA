@extends('layouts.default_tech')

@section('title', 'Canonical mail cutover')

@section('pageHeader')
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
    <h1>Canonical mail cutover</h1>
    <a class="btn btn-outline-secondary" href="{{ route('tech.admin.settings.email.correlation.index') }}">Shadow correlation</a>
</div>
@endsection

@section('sidebar')
<x-nav.admin-menu group="email" />
@endsection

@section('content')
<div class="container-fluid">
    {{-- Safety contract and staged-deploy state --}}
    <div class="alert alert-info" role="note">
        Every operation starts as an immutable bounded preview. Apply and rollback are separate,
        confirmed local actions. Provider folders, read state, rules, Tickets, and remote mail are never changed here.
    </div>

    @if(session('success'))
        <div class="alert alert-success" role="status">{{ session('success') }}</div>
    @endif

    @if(!$schemaReady)
        <div class="alert alert-warning" role="alert">
            The additive canonical cutover migration is pending. Mail remains in legacy read mode and no maintenance action is available.
        </div>
    @else
        {{-- Bounded preview forms --}}
        <div class="row g-4 mb-4">
            <div class="col-12 col-xl-6">
                <section class="card shadow-sm h-100" aria-labelledby="cutover-backfill-heading">
                    <div class="card-body">
                        <h2 class="h5" id="cutover-backfill-heading">Preview self-map backfill</h2>
                        <p class="small text-body-secondary">Create missing one-source projections or repair placement pointers under exact file-backed evidence.</p>
                        <form method="post" action="{{ route('tech.admin.settings.email.canonical-cutover.backfill') }}" class="vstack gap-3">
                            @csrf
                            @include('email::Admin.Cutover.partials.source-scope', ['prefix' => 'backfill'])
                            <button class="btn btn-outline-primary align-self-start" type="submit" @disabled($accounts->isEmpty())>Create backfill preview</button>
                        </form>
                    </div>
                </section>
            </div>

            <div class="col-12 col-xl-6">
                <section class="card shadow-sm h-100" aria-labelledby="cutover-audit-heading">
                    <div class="card-body">
                        <h2 class="h5" id="cutover-audit-heading">Preview parity audit</h2>
                        <p class="small text-body-secondary">Expand complete mapped components, repair pointer-only drift, and dissolve content drift into independent projections.</p>
                        <form method="post" action="{{ route('tech.admin.settings.email.canonical-cutover.audit') }}" class="vstack gap-3">
                            @csrf
                            @include('email::Admin.Cutover.partials.source-scope', ['prefix' => 'audit'])
                            <button class="btn btn-outline-primary align-self-start" type="submit" @disabled($accounts->isEmpty())>Create audit preview</button>
                        </form>
                    </div>
                </section>
            </div>

            <div class="col-12 col-xl-6">
                <section class="card shadow-sm h-100" aria-labelledby="cutover-merge-heading">
                    <div class="card-body">
                        <h2 class="h5" id="cutover-merge-heading">Preview reviewed merge</h2>
                        <p class="small text-body-secondary">Only a complete strong, confirmed, inspected clique from one completed shadow run can qualify.</p>
                        <form method="post" action="{{ route('tech.admin.settings.email.canonical-cutover.merge') }}" class="vstack gap-3">
                            @csrf
                            <div>
                                <label class="form-label" for="cutover-correlation-run">Completed shadow run</label>
                                <select class="form-select" id="cutover-correlation-run" name="correlation_run_id" required>
                                    <option value="">Choose a run</option>
                                    @foreach($correlationRuns as $correlationRun)
                                        <option value="{{ $correlationRun->id }}" @selected((int) old('correlation_run_id') === $correlationRun->id)>
                                            #{{ $correlationRun->id }} · {{ $correlationRun->candidate_count }} candidates
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="form-label" for="cutover-candidate-ids">Exact candidate IDs</label>
                                <textarea class="form-control" id="cutover-candidate-ids" name="candidate_ids" rows="3" maxlength="8000" required>{{ old('candidate_ids') }}</textarea>
                                <div class="form-text">Comma- or whitespace-separated IDs. Supply every edge in the complete component.</div>
                            </div>
                            <button class="btn btn-outline-primary align-self-start" type="submit" @disabled($correlationRuns->isEmpty())>Create merge preview</button>
                        </form>
                    </div>
                </section>
            </div>

            <div class="col-12 col-xl-6">
                <section class="card shadow-sm h-100" aria-labelledby="cutover-mode-heading">
                    <div class="card-body">
                        <h2 class="h5" id="cutover-mode-heading">Preview read-mode change</h2>
                        <p class="small text-body-secondary">Legacy is the default. Verify observes parity without projection; canonical projects common content and falls back to the authorized source on drift.</p>
                        <form method="post" action="{{ route('tech.admin.settings.email.canonical-cutover.mode') }}" class="vstack gap-3 border rounded p-3 mb-3">
                            @csrf
                            <input type="hidden" name="intent" value="attest">
                            <div>
                                <label class="form-label" for="cutover-attestation-account">Whole-account parity account</label>
                                <select class="form-select" id="cutover-attestation-account" name="account_id" required>
                                    <option value="">Choose one account</option>
                                    @foreach($accounts as $account)
                                        <option value="{{ $account->id }}">{{ $account->address }}</option>
                                    @endforeach
                                </select>
                                <div class="form-text">Hashes at most 100 active placements per request. Required when an account exceeds one 500-item preview.</div>
                            </div>
                            <button class="btn btn-outline-secondary align-self-start" type="submit" @disabled($accounts->isEmpty())>Start parity attestation</button>
                        </form>
                        <form method="post" action="{{ route('tech.admin.settings.email.canonical-cutover.mode') }}" class="vstack gap-3">
                            @csrf
                            <input type="hidden" name="intent" value="mode">
                            <div>
                                <label class="form-label" for="cutover-mode-accounts">Exact mail accounts</label>
                                <select class="form-select" id="cutover-mode-accounts" name="account_ids[]" multiple required size="{{ min(8, max(3, $accounts->count())) }}">
                                    @foreach($accounts as $account)
                                        <option value="{{ $account->id }}">{{ $account->address }} · {{ ucfirst($account->account_kind) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="form-label" for="cutover-mode">Requested mode</label>
                                <select class="form-select" id="cutover-mode" name="mode" required>
                                    @foreach(\App\Modules\Email\Models\EmailCanonicalReadMode::MODES as $mode)
                                        <option value="{{ $mode }}" @selected(old('mode', 'legacy') === $mode)>{{ ucfirst($mode) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <button class="btn btn-outline-primary align-self-start" type="submit" @disabled($accounts->isEmpty())>Create mode preview</button>
                        </form>
                    </div>
                </section>
            </div>
        </div>

        <section class="card shadow-sm mb-4" aria-labelledby="cutover-attestations-heading">
            <div class="card-body">
                <h2 class="h5" id="cutover-attestations-heading">Whole-account parity attestations</h2>
                <p class="small text-body-secondary">Each request hashes one bounded page. Any account/mapping/content scope change invalidates the frozen attestation and requires a fresh start.</p>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr><th>Attestation</th><th>Account</th><th>Status</th><th>Progress</th><th>Evidence</th><th><span class="visually-hidden">Continue</span></th></tr></thead>
                        <tbody>
                            @forelse($attestations as $attestation)
                                <tr>
                                    <td>#{{ $attestation->id }}</td>
                                    <td>{{ $attestation->email_account_id }}</td>
                                    <td><span class="badge text-bg-secondary">{{ $attestation->status }}</span></td>
                                    <td>{{ $attestation->verified_placement_count }} / {{ $attestation->frozen_active_placement_count }}</td>
                                    <td>{{ $attestation->strict_evidence ? 'actual-file strict' : 'pointer only' }}</td>
                                    <td class="text-end">
                                        @if($attestation->status !== \App\Modules\Email\Models\EmailCanonicalParityAttestation::STATUS_COMPLETED)
                                            <form method="post" action="{{ route('tech.admin.settings.email.canonical-cutover.mode') }}">
                                                @csrf
                                                <input type="hidden" name="intent" value="attest">
                                                <input type="hidden" name="attestation_id" value="{{ $attestation->id }}">
                                                <button class="btn btn-sm btn-outline-primary" type="submit">Process next 100</button>
                                            </form>
                                        @else
                                            <span class="small text-body-secondary">Ready for 15 minutes</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="text-body-secondary">No whole-account parity attestation has been started.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        {{-- Metadata-only durable run history --}}
        <section class="card shadow-sm" aria-labelledby="cutover-runs-heading">
            <div class="card-body">
                <h2 class="h5" id="cutover-runs-heading">Recent durable previews</h2>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th scope="col">Run</th>
                                <th scope="col">Operation</th>
                                <th scope="col">Status</th>
                                <th scope="col">Accounts</th>
                                <th scope="col">Items</th>
                                <th scope="col">Created</th>
                                <th scope="col"><span class="visually-hidden">Open</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($runs as $run)
                                <tr>
                                    <td>#{{ $run->id }}</td>
                                    <td>{{ $run->operation }}</td>
                                    <td><span class="badge text-bg-secondary">{{ $run->status }}</span></td>
                                    <td>{{ count($run->account_scope_json ?? []) }}</td>
                                    <td>{{ $run->item_count }}</td>
                                    <td>{{ optional($run->created_at)->format('Y-m-d H:i') }}</td>
                                    <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="{{ route('tech.admin.settings.email.canonical-cutover.show', $run) }}">Review</a></td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="text-body-secondary">No canonical cutover preview has been created.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    @endif
</div>
@endsection
