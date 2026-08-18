@extends('layouts.default_tech')

@section('title', 'Canonical mail correlation')

@section('pageHeader')
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
    <h1>Canonical mail correlation</h1>
    <a class="btn btn-outline-secondary" href="{{ route('tech.admin.settings.email.accounts') }}">Mail accounts</a>
</div>
@endsection

@section('sidebar')
<x-nav.admin-menu group="email" />
@endsection

@section('content')
<div class="container-fluid">
    {{-- Page heading and safety contract --}}
    <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">
        <div>
            <p class="text-body-secondary mb-0">
                Bounded, local shadow evidence only. This page never merges, relinks, hides, deletes, or changes provider mail.
            </p>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success" role="status">{{ session('success') }}</div>
    @endif

    {{-- New bounded run --}}
    <section class="card shadow-sm mb-4" aria-labelledby="canonical-correlation-new-heading">
        <div class="card-body">
            <h2 class="h5" id="canonical-correlation-new-heading">Create shadow run</h2>
            <p class="small text-body-secondary">
                Only accounts you may currently view are listed. The frozen scope is capped before any queued work starts.
            </p>

            <form method="post" action="{{ route('tech.admin.settings.email.correlation.store') }}" class="row g-3">
                @csrf
                <div class="col-12">
                    <label class="form-label" for="canonical-correlation-account-ids">Exact mail accounts</label>
                    <select class="form-select @error('account_ids') is-invalid @enderror"
                            id="canonical-correlation-account-ids"
                            name="account_ids[]"
                            multiple
                            size="{{ min(8, max(3, $accounts->count())) }}"
                            required>
                        @foreach($accounts as $account)
                            <option value="{{ $account->id }}" @selected(in_array($account->id, old('account_ids', [])))>
                                {{ $account->address }} · {{ ucfirst($account->account_kind) }}
                            </option>
                        @endforeach
                    </select>
                    @error('account_ids')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-sm-6 col-xl-3">
                    <label class="form-label" for="canonical-min-message-id">Minimum message ID</label>
                    <input class="form-control" id="canonical-min-message-id" name="min_message_id" type="number" min="1" value="{{ old('min_message_id', 1) }}">
                    <div class="form-text">Use an exact ID window when one account exceeds the cap.</div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <label class="form-label" for="canonical-max-message-id">Maximum message ID</label>
                    <input class="form-control" id="canonical-max-message-id" name="max_message_id" type="number" min="1" value="{{ old('max_message_id') }}" placeholder="Current high-water">
                </div>
                <div class="col-sm-6 col-xl-3">
                    <label class="form-label" for="canonical-message-cap">Message cap</label>
                    <input class="form-control" id="canonical-message-cap" name="message_cap" type="number" min="1" max="5000" value="{{ old('message_cap', 2000) }}">
                </div>
                <div class="col-sm-6 col-xl-3">
                    <label class="form-label" for="canonical-group-cap">Group cap</label>
                    <input class="form-control" id="canonical-group-cap" name="group_cap" type="number" min="1" max="500" value="{{ old('group_cap', 250) }}">
                </div>
                <div class="col-sm-6 col-xl-3">
                    <label class="form-label" for="canonical-pair-cap">Pair cap</label>
                    <input class="form-control" id="canonical-pair-cap" name="pair_cap" type="number" min="1" max="5000" value="{{ old('pair_cap', 2500) }}">
                </div>
                <div class="col-sm-6 col-xl-3">
                    <label class="form-label" for="canonical-per-group-cap">Per-group cap</label>
                    <input class="form-control" id="canonical-per-group-cap" name="per_group_cap" type="number" min="1" max="50" value="{{ old('per_group_cap', 20) }}">
                </div>

                <div class="col-12">
                    <button class="btn btn-primary" type="submit" @disabled($accounts->isEmpty())>
                        Queue bounded shadow run
                    </button>
                </div>
            </form>
        </div>
    </section>

    {{-- Recent runs contain counts and opaque identifiers only. --}}
    <section class="card shadow-sm" aria-labelledby="canonical-correlation-runs-heading">
        <div class="card-body">
            <h2 class="h5" id="canonical-correlation-runs-heading">Recent runs</h2>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th scope="col">Run</th>
                            <th scope="col">Status</th>
                            <th scope="col">Accounts</th>
                            <th scope="col">Messages</th>
                            <th scope="col">Candidates</th>
                            <th scope="col">Created</th>
                            <th scope="col"><span class="visually-hidden">Open</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($runs as $run)
                            <tr>
                                <td>#{{ $run->id }}</td>
                                <td><span class="badge text-bg-secondary">{{ $run->status }}</span></td>
                                <td>{{ count($run->account_scope_json ?? []) }}</td>
                                <td>{{ $run->scoped_message_count }}</td>
                                <td>{{ $run->candidate_count }}</td>
                                <td>{{ optional($run->created_at)->format('Y-m-d H:i') }}</td>
                                <td class="text-end">
                                    <a class="btn btn-sm btn-outline-primary" href="{{ route('tech.admin.settings.email.correlation.show', $run) }}">Open report</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-body-secondary">No shadow run has been created.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</div>
@endsection
