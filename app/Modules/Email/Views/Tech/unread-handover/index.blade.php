@extends('layouts.default_tech')

@section('title', 'Unread handover')

@section('pageHeader')
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
        <h1 class="mb-0">Unread handover</h1>
        <x-buttons.back
            :url="$account->isPersonal()
                ? route('tech.mail.access.index')
                : route('tech.admin.settings.email.accounts')"
            class="mb-0"
        >Back</x-buttons.back>
    </div>
@endsection

@section('content')
    <div class="col-12">
        <!-- -------------------------------------------------------------------------------------------------- -->
        <!-- Metadata-only status feedback -->
        <!-- No message content is loaded or rendered on this management surface. -->
        <!-- -------------------------------------------------------------------------------------------------- -->
        @if(session('status'))
            <div class="alert alert-success py-2" role="status">{{ session('status') }}</div>
        @endif
        @if(session('warning'))
            <div class="alert alert-warning py-2" role="status">{{ session('warning') }}</div>
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

        <!-- -------------------------------------------------------------------------------------------------- -->
        <!-- Exact unread handover preview -->
        <!-- Preview records only IDs, scope, counts, authorization evidence, and the operator reason. -->
        <!-- -------------------------------------------------------------------------------------------------- -->
        <section class="card mb-3">
            <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2 py-2">
                <div>
                    <span class="fw-semibold">{{ $account->address }}</span>
                    <span class="badge text-bg-light border ms-1">{{ ucfirst($account->account_kind) }}</span>
                </div>
                <span class="small text-muted">15-minute preview · maximum 500</span>
            </div>
            <div class="card-body py-3">
                <div class="alert alert-info py-2 small">
                    This changes only <strong>Unread for me</strong> for the selected user and exact snapshot.
                    Provider Seen, other users, folders, rules, Tickets, notifications, and later arrivals stay unchanged.
                </div>

                @if($targets->isEmpty())
                    <p class="text-muted mb-0">No active human user currently has ordinary View access to this mailbox.</p>
                @elseif($folders->isEmpty())
                    <p class="text-muted mb-0">No current selectable and synchronized folder is available for a handover scope.</p>
                @else
                    <form method="POST" action="{{ route('tech.mail.unread-handover.preview', $account) }}" class="row g-3">
                        @csrf
                        <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">

                        <div class="col-12 col-lg-4">
                            <label for="handover-target" class="form-label">Exact user</label>
                            <select id="handover-target" name="target_user_id" class="form-select" required>
                                <option value="">Choose current viewer</option>
                                @foreach($targets as $target)
                                    <option value="{{ $target->id }}" @selected((int)old('target_user_id') === (int)$target->id)>
                                        {{ $target->name }} — {{ $target->email }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-6 col-lg-3">
                            <label for="handover-date-from" class="form-label">Received from</label>
                            <input id="handover-date-from" type="datetime-local" name="date_from" class="form-control" value="{{ old('date_from', now()->subDays(7)->format('Y-m-d\TH:i')) }}" required>
                        </div>
                        <div class="col-6 col-lg-3">
                            <label for="handover-date-to" class="form-label">Received to</label>
                            <input id="handover-date-to" type="datetime-local" name="date_to" class="form-control" value="{{ old('date_to', now()->format('Y-m-d\TH:i')) }}" required>
                        </div>
                        <div class="col-12 col-lg-2">
                            <label for="handover-maximum" class="form-label">Maximum</label>
                            <input id="handover-maximum" type="number" min="1" max="500" name="maximum" class="form-control" value="{{ old('maximum', 100) }}" required>
                        </div>

                        <fieldset class="col-12">
                            <legend class="form-label mb-2">Exact folders</legend>
                            <div class="row g-2">
                                @foreach($folders as $folder)
                                    <div class="col-12 col-md-6 col-xl-4">
                                        <div class="form-check border rounded p-2 ps-5 h-100">
                                            <input
                                                id="handover-folder-{{ $folder->id }}"
                                                class="form-check-input"
                                                type="checkbox"
                                                name="folder_ids[]"
                                                value="{{ $folder->id }}"
                                                @checked(in_array($folder->id, (array)old('folder_ids', [])))
                                            >
                                            <label class="form-check-label" for="handover-folder-{{ $folder->id }}">
                                                <span class="d-block fw-semibold">{{ $folder->name }}</span>
                                                <span class="d-block small text-muted">{{ $folder->path }}</span>
                                            </label>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </fieldset>

                        <div class="col-12">
                            <label for="handover-reason" class="form-label">Handover reason</label>
                            <textarea id="handover-reason" name="reason" class="form-control" rows="2" minlength="10" maxlength="2000" required>{{ old('reason') }}</textarea>
                        </div>
                        <div class="col-12 d-flex justify-content-end">
                            <button type="submit" class="btn btn-primary">Preview exact message-ID snapshot</button>
                        </div>
                    </form>
                @endif
            </div>
        </section>

        <!-- -------------------------------------------------------------------------------------------------- -->
        <!-- Durable handover metadata and confirmation -->
        <!-- Folder labels and counts are shown, but subject, participants, snippets, filenames, and bodies are not. -->
        <!-- -------------------------------------------------------------------------------------------------- -->
        <section class="card">
            <div class="card-header d-flex align-items-center justify-content-between gap-2 py-2">
                <span class="fw-semibold">Recent previews and results</span>
                <span class="badge text-bg-light border">{{ $runs->count() }}</span>
            </div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Run / state</th>
                            <th>Exact target</th>
                            <th>Folders / received window</th>
                            <th>Results</th>
                            <th>Reason / safe code</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($runs as $run)
                            @php
                                $scopeLabels = collect((array)$run->folder_scope_json)
                                    ->map(fn($id) => $folderLabels->get((int)$id, 'Removed folder #'.(int)$id))
                                    ->implode(', ');
                                $statusClass = match($run->status) {
                                    'applied' => 'text-bg-success',
                                    'stale', 'expired' => 'text-bg-warning',
                                    default => 'text-bg-secondary',
                                };
                            @endphp
                            <tr>
                                <td>
                                    <span class="fw-semibold">#{{ $run->id }}</span>
                                    <span class="badge {{ $statusClass }} ms-1">{{ $run->status }}</span>
                                    <div class="small text-muted">epoch {{ $run->access_epoch }} · {{ $run->requestedBy?->name ?? 'Removed actor' }}</div>
                                </td>
                                <td>{{ $run->targetUser?->name ?? 'Removed user' }}</td>
                                <td class="small">
                                    {{ $scopeLabels ?: 'No folders' }}<br>
                                    <span class="text-muted">{{ $run->date_from?->format('Y-m-d H:i') }} – {{ $run->date_to?->format('Y-m-d H:i') }}</span>
                                </td>
                                <td class="small">
                                    {{ $run->selected_count }} selected · {{ $run->applied_count }} changed<br>
                                    <span class="text-muted">{{ $run->already_unread_count }} already unread · {{ $run->failed_count }} stale/failed</span>
                                </td>
                                <td class="small">
                                    {{ $run->reason }}
                                    @if($run->error_code)
                                        <code class="d-block">{{ $run->error_code }}</code>
                                    @endif
                                </td>
                                <td class="text-end">
                                    @if($run->status === 'previewed'
                                        && $run->preview_expires_at?->isFuture()
                                        && (int)$run->requested_by === (int)$actor->id)
                                        <form method="POST" action="{{ route('tech.mail.unread-handover.apply', [$account, $run]) }}">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-warning">Apply exact snapshot</button>
                                        </form>
                                        <div class="small text-muted mt-1">expires {{ $run->preview_expires_at->format('H:i') }}</div>
                                    @elseif($run->status === 'previewed' && $run->preview_expires_at?->isFuture())
                                        <span class="small text-muted">Awaiting previewing actor</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-muted text-center py-3">No unread handover previews yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
@endsection

@section('sidebar')
    @if($account->isPersonal())
        @include('email::Tech.mailbox-access.partials.sidebar')
    @else
        <x-nav.admin-menu group="email" />
    @endif
@endsection

@section('rightbar')
    <div class="accordion mt-3" id="unread-handover-help">
        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#unread-handover-boundaries" aria-expanded="false" aria-controls="unread-handover-boundaries">
                    Safety boundaries
                </button>
            </h2>
            <div id="unread-handover-boundaries" class="accordion-collapse collapse" data-bs-parent="#unread-handover-help">
                <div class="accordion-body small text-muted">
                    Preview and result rows contain metadata only. Apply rechecks the exact target, mailbox access epoch, folders, placements, and message-ID snapshot before writing one user's Nexum unread state.
                </div>
            </div>
        </div>
    </div>
@endsection
