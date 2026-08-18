@extends('layouts.default_tech')

@section('title', 'Mailbox maintenance')

@section('pageHeader')
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
    <div>
      <h1 class="mb-1">Mailbox maintenance</h1>
      <div class="text-muted">{{ $account->address }}</div>
    </div>
    <a href="{{ route('tech.admin.settings.email.accounts') }}" class="btn btn-outline-secondary">Back to Email accounts</a>
  </div>
@endsection

@section('content')
  <div class="col-12">
    <!-- -------------------------------------------------------------------------------------------------- -->
    <!-- Operational feedback -->
    <!-- Maintenance errors expose bounded state codes only; mailbox content is never rendered here. -->
    <!-- -------------------------------------------------------------------------------------------------- -->
    @if(session('success'))
      <div class="alert alert-success" role="status">{{ session('success') }}</div>
    @endif
    @if(session('warning'))
      <div class="alert alert-warning" role="status">{{ session('warning') }}</div>
    @endif
    @if($errors->any())
      <div class="alert alert-danger" role="alert">
        <ul class="mb-0">
          @foreach($errors->all() as $error)
            <li>{{ $error }}</li>
          @endforeach
        </ul>
      </div>
    @endif

    <!-- -------------------------------------------------------------------------------------------------- -->
    <!-- Read-only provider reconciliation -->
    <!-- Only bounded operational metadata is rendered; message content and provider credentials stay absent. -->
    <!-- -------------------------------------------------------------------------------------------------- -->
    @php
      $activeProviderReconciliation = $reconciliationRuns->first(
        fn ($run) => $run->active_slot !== null
      );
    @endphp
    <x-card.default title="Provider reconciliation">
      <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
        <p class="text-muted small mb-0">
          The scheduled read-only cycle is the correctness path for provider folders, placements, and flags.
          A manual run uses the same bounded queue workflow and never changes provider state.
        </p>
        <div class="d-flex flex-wrap gap-2">
          <form method="POST" action="{{ route('tech.admin.settings.email.accounts.provider-reconciliation.start', $account) }}">
            @csrf
            <button type="submit" class="btn btn-primary" @disabled($activeProviderReconciliation)>
              Run reconciliation now
            </button>
          </form>
          @if($activeProviderReconciliation?->cancellable())
            <form method="POST" action="{{ route('tech.admin.settings.email.accounts.provider-reconciliation.cancel', [$account, $activeProviderReconciliation]) }}">
              @csrf
              <button type="submit" class="btn btn-outline-warning">Stop between batches</button>
            </form>
          @endif
        </div>
      </div>

      <div class="table-responsive mt-3">
        <table class="table table-sm align-middle mb-0">
          <thead>
            <tr>
              <th>Run</th>
              <th>Status / phase</th>
              <th>Folder progress</th>
              <th>Observed changes</th>
              <th>Safe code</th>
            </tr>
          </thead>
          <tbody>
            @forelse($reconciliationRuns as $run)
              <tr>
                <td>
                  #{{ $run->id }} · {{ $run->trigger }}
                  <div class="small text-muted">
                    {{ $run->requester?->name ?? 'Automated' }} · {{ ($run->last_progress_at ?? $run->queued_at)?->diffForHumans() ?? 'pending' }}
                  </div>
                </td>
                <td>
                  <span class="badge text-bg-secondary">{{ $run->status }}</span>
                  <div class="small text-muted">{{ $run->phase }}</div>
                </td>
                <td class="small">
                  {{ $run->complete_folder_count }}/{{ $run->folder_count }} complete<br>
                  {{ $run->batch_count }} bounded batches
                </td>
                <td class="small">
                  {{ $run->observed_count }} observed · {{ $run->import_count }} imports<br>
                  {{ $run->flag_change_count }} flags · {{ $run->missing_count }} missing · {{ $run->conflict_count }} conflicts
                </td>
                <td class="small"><code>{{ $run->failure_code ?? '—' }}</code></td>
              </tr>
            @empty
              <tr><td colspan="5" class="text-muted text-center py-3">No provider reconciliation cycles yet.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>

      @if($reconciliationDetailRun)
        <div class="mt-3">
          <h3 class="h6">Folder progress for run #{{ $reconciliationDetailRun->id }}</h3>
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
              <thead><tr><th>Provider folder</th><th>Status</th><th>UID cursor</th><th>Counts</th><th>Safe code</th></tr></thead>
              <tbody>
                @forelse($reconciliationDetailRun->folders as $folderRun)
                  <tr>
                    <td class="small text-break">{{ $folderRun->folder_path }}</td>
                    <td><span class="badge text-bg-secondary">{{ $folderRun->status }}</span></td>
                    <td class="small">next {{ $folderRun->next_uid }} / through {{ $folderRun->scan_through_uid }}</td>
                    <td class="small">
                      {{ $folderRun->observed_count }} observed · {{ $folderRun->import_count }} imports ·
                      {{ $folderRun->missing_count }} missing · {{ $folderRun->conflict_count }} conflicts
                    </td>
                    <td class="small"><code>{{ $folderRun->reason_code ?? '—' }}</code></td>
                  </tr>
                @empty
                  <tr><td colspan="5" class="text-muted text-center py-2">Folder discovery has not completed.</td></tr>
                @endforelse
              </tbody>
            </table>
          </div>
        </div>
      @endif
    </x-card.default>

    <!-- -------------------------------------------------------------------------------------------------- -->
    <!-- Bounded historical import preview -->
    <!-- Preview uses UID/date metadata only and cannot move the live forward cursor. -->
    <!-- -------------------------------------------------------------------------------------------------- -->
    <x-card.default title="Historical import preview">
      <p class="text-muted small">
        Select a maximum 31-day UTC window. The server rejects scopes above the confirmed cap and a combined selected-folder UID range wider than 50,000.
        Provider Seen/unread state is never used as a cursor.
      </p>
      <div class="small text-muted mb-2">
        Showing folders {{ $folders->firstItem() ?? 0 }}–{{ $folders->lastItem() ?? 0 }} of {{ $folders->total() }}.
        Folder history is paginated so large mailboxes remain bounded.
      </div>
      <form method="POST" action="{{ route('tech.admin.settings.email.accounts.historical-import.preview', $account) }}" class="row g-3">
        @csrf
        <div class="col-12">
          <div class="fw-semibold mb-2">Folders</div>
          <div class="row g-2">
            @forelse($folders as $folder)
              @php
                $eligible = $folder->is_selectable
                  && $folder->sync_enabled
                  && $folder->activeUidNamespace
                  && (int)$folder->activeUidNamespace->uid_validity > 0
                  && $folder->live_start_uid !== null;
              @endphp
              <div class="col-md-6">
                <div class="form-check border rounded p-2 ps-5 h-100">
                  <input
                    class="form-check-input"
                    type="checkbox"
                    name="folder_ids[]"
                    value="{{ $folder->id }}"
                    id="historical-folder-{{ $folder->id }}"
                    @checked(in_array($folder->id, (array)old('folder_ids', [])))
                    @disabled(!$eligible)
                  >
                  <label class="form-check-label" for="historical-folder-{{ $folder->id }}">
                    <span class="d-block fw-semibold">{{ $folder->name }}</span>
                    <span class="d-block small text-muted">{{ $folder->path }}</span>
                    <span class="d-block small">
                      UIDVALIDITY {{ $folder->activeUidNamespace?->uid_validity ?? 'unproven' }} · live high-water {{ $folder->live_start_uid ?? 'pending' }}
                    </span>
                  </label>
                </div>
              </div>
            @empty
              <div class="col-12 text-muted">No provider folders have been discovered.</div>
            @endforelse
          </div>
        </div>
        <div class="col-md-3">
          <label for="historical-date-from" class="form-label">UTC date from</label>
          <input id="historical-date-from" type="date" name="date_from" class="form-control" value="{{ old('date_from', now('UTC')->subDay()->toDateString()) }}" required>
        </div>
        <div class="col-md-3">
          <label for="historical-date-to" class="form-label">UTC date to</label>
          <input id="historical-date-to" type="date" name="date_to" class="form-control" value="{{ old('date_to', now('UTC')->subDay()->toDateString()) }}" required>
        </div>
        <div class="col-md-2">
          <label for="historical-uid-from" class="form-label">UID from</label>
          <input id="historical-uid-from" type="number" min="1" name="uid_from" class="form-control" value="{{ old('uid_from', 1) }}" required>
        </div>
        <div class="col-md-2">
          <label for="historical-uid-to" class="form-label">UID to</label>
          <input id="historical-uid-to" type="number" min="1" name="uid_to" class="form-control" value="{{ old('uid_to') }}" placeholder="Live boundary">
        </div>
        <div class="col-md-2">
          <label for="historical-cap" class="form-label">Message cap</label>
          <input id="historical-cap" type="number" min="1" max="500" name="cap" class="form-control" value="{{ old('cap', 100) }}" required>
        </div>
        <div class="col-12">
          <button type="submit" class="btn btn-primary">Preview metadata scope</button>
        </div>
      </form>
      @if($folders->hasPages())
        <div class="mt-3" aria-label="Provider folder pages">
          {{ $folders->onEachSide(1)->links() }}
        </div>
      @endif
    </x-card.default>

    <!-- -------------------------------------------------------------------------------------------------- -->
    <!-- Historical run audit and controls -->
    <!-- Counts and identity evidence remain visible across queue restarts without exposing mail content. -->
    <!-- -------------------------------------------------------------------------------------------------- -->
    <div class="mt-3">
      <x-card.default title="Historical import runs">
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead>
              <tr>
                <th>Run</th>
                <th>Status</th>
                <th>Window / exact scope</th>
                <th>Progress</th>
                <th>Safe code</th>
                <th class="text-end">Action</th>
              </tr>
            </thead>
            <tbody>
              @forelse($historicalRuns as $run)
                <tr>
                  <td>#{{ $run->id }}<div class="small text-muted">{{ $run->requester?->name ?? 'Removed user' }}</div></td>
                  <td><span class="badge text-bg-secondary">{{ $run->status }}</span></td>
                  <td class="small">
                    {{ $run->date_from?->toDateString() }} – {{ $run->date_to?->toDateString() }} · UID {{ $run->uid_from }}–{{ $run->uid_to ?? 'live boundary' }}<br>
                    requested/effective cap {{ $run->requested_cap }}/{{ $run->effective_cap }}
                    @foreach((array)$run->folder_scope_json as $scope)
                      <span class="d-block text-muted">
                        {{ $scope['path'] ?? 'Removed folder' }} · UIDVALIDITY {{ $scope['uid_validity'] ?? 'unknown' }} · high-water {{ $scope['live_start_uid'] ?? 'unknown' }}
                      </span>
                    @endforeach
                  </td>
                  <td class="small">
                    {{ $run->imported_count }} imported · {{ $run->already_present_count }} present<br>
                    {{ $run->pending_count }} remaining · {{ $run->skipped_count }} skipped · {{ $run->failed_count }} failed
                  </td>
                  <td class="small"><code>{{ $run->error_code ?? '—' }}</code></td>
                  <td class="text-end">
                    @if($run->status === 'previewed' && !$run->previewExpired())
                      <form method="POST" action="{{ route('tech.admin.settings.email.accounts.historical-import.start', $account) }}" class="d-inline">
                        @csrf
                        <input type="hidden" name="historical_import_run_id" value="{{ $run->id }}">
                        <input type="hidden" name="preview_fingerprint" value="{{ $run->preview_fingerprint }}">
                        <button type="submit" class="btn btn-sm btn-primary">Confirm import</button>
                      </form>
                    @elseif(in_array($run->status, [
                      'queued',
                      'running',
                      'cancelling',
                    ], true))
                      <form method="POST" action="{{ route('tech.admin.settings.email.accounts.historical-import.cancel', [$account, $run]) }}" class="d-inline">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-outline-warning" @disabled($run->status === 'cancelling')>Stop between batches</button>
                      </form>
                    @endif
                  </td>
                </tr>
              @empty
                <tr><td colspan="6" class="text-muted text-center py-3">No historical import previews yet.</td></tr>
              @endforelse
            </tbody>
          </table>
        </div>
      </x-card.default>
    </div>

    <!-- -------------------------------------------------------------------------------------------------- -->
    <!-- UID cursor recovery preview -->
    <!-- Applying is a separate explicit confirmation and never fetches or mutates provider messages. -->
    <!-- -------------------------------------------------------------------------------------------------- -->
    <div class="mt-3">
      <x-card.default title="UIDVALIDITY cursor recovery">
        <p class="text-muted small">Use only after a recorded folder identity/state failure. Preview reads stable UIDVALIDITY/UIDNEXT evidence and blockers before any local cursor change.</p>
        <div class="accordion" id="cursor-rebaseline-folders">
          @foreach($folders as $folder)
            <div class="accordion-item">
              <h2 class="accordion-header" id="cursor-heading-{{ $folder->id }}">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#cursor-folder-{{ $folder->id }}" aria-expanded="false" aria-controls="cursor-folder-{{ $folder->id }}">
                  {{ $folder->path }} · UIDVALIDITY {{ $folder->activeUidNamespace?->uid_validity ?? $folder->uid_validity ?: 'unknown' }}
                  @if($folder->sync_error_code)<span class="badge text-bg-warning ms-2">{{ $folder->sync_error_code }}</span>@endif
                </button>
              </h2>
              <div id="cursor-folder-{{ $folder->id }}" class="accordion-collapse collapse" aria-labelledby="cursor-heading-{{ $folder->id }}" data-bs-parent="#cursor-rebaseline-folders">
                <div class="accordion-body">
                  <form method="POST" action="{{ route('tech.admin.settings.email.accounts.cursor-rebaseline.preview', [$account, $folder]) }}">
                    @csrf
                    <label for="cursor-reason-{{ $folder->id }}" class="form-label">Operator recovery reason</label>
                    <textarea id="cursor-reason-{{ $folder->id }}" name="reason" class="form-control" rows="2" minlength="10" maxlength="1000" required></textarea>
                    <button type="submit" class="btn btn-sm btn-outline-primary mt-2" @disabled(!$folder->is_selectable || !$folder->sync_enabled)>Preview cursor recovery</button>
                  </form>
                </div>
              </div>
            </div>
          @endforeach
        </div>
        @if($folders->hasPages())
          <div class="mt-3" aria-label="Cursor re-baseline folder pages">
            {{ $folders->onEachSide(1)->links() }}
          </div>
        @endif
      </x-card.default>
    </div>

    <!-- -------------------------------------------------------------------------------------------------- -->
    <!-- Cursor recovery audit -->
    <!-- Exact old/new identity values must be posted back from the reviewed preview. -->
    <!-- -------------------------------------------------------------------------------------------------- -->
    <div class="mt-3">
      <x-card.default title="Cursor recovery runs">
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead><tr><th>Run / folder</th><th>Status</th><th>Observed identity</th><th>Blockers</th><th class="text-end">Action</th></tr></thead>
            <tbody>
              @forelse($rebaselineRuns as $run)
                <tr>
                  <td>
                    #{{ $run->id }}
                    <div class="small text-muted">{{ $run->folder?->path ?? 'Removed folder' }} · {{ $run->requester?->name ?? 'Removed user' }}</div>
                    <div class="small text-muted">{{ $run->reason }}</div>
                  </td>
                  <td><span class="badge text-bg-secondary">{{ $run->status }}</span></td>
                  <td class="small">
                    old {{ $run->old_uid_validity ?? 'unknown' }} · new {{ $run->observed_uid_validity ?? 'unavailable' }} · UIDNEXT {{ $run->observed_uid_next ?? 'unavailable' }}<br>
                    old/new high-water {{ $run->old_live_start_uid ?? 'unknown' }}/{{ $run->new_live_start_uid ?? 'unavailable' }} · active placements {{ $run->old_placement_count }}
                  </td>
                  <td class="small">
                    @forelse((array)$run->blocker_codes_json as $code)
                      <code class="d-block">{{ $code }}</code>
                    @empty
                      —
                    @endforelse
                  </td>
                  <td class="text-end">
                    @if($run->status === 'previewed' && !$run->previewExpired() && $run->folder)
                      <form method="POST" action="{{ route('tech.admin.settings.email.accounts.cursor-rebaseline.apply', [$account, $run->folder]) }}">
                        @csrf
                        <input type="hidden" name="cursor_rebaseline_run_id" value="{{ $run->id }}">
                        <input type="hidden" name="preview_fingerprint" value="{{ $run->preview_fingerprint }}">
                        <input type="hidden" name="old_uid_validity" value="{{ (int)$run->old_uid_validity }}">
                        <input type="hidden" name="observed_uid_validity" value="{{ (int)$run->observed_uid_validity }}">
                        <input type="hidden" name="observed_uid_next" value="{{ (int)$run->observed_uid_next }}">
                        <button type="submit" class="btn btn-sm btn-warning">Apply exact re-baseline</button>
                      </form>
                    @endif
                  </td>
                </tr>
              @empty
                <tr><td colspan="5" class="text-muted text-center py-3">No cursor recovery previews yet.</td></tr>
              @endforelse
            </tbody>
          </table>
        </div>
      </x-card.default>
    </div>
  </div>
@endsection

@section('sidebar')
  <x-nav.admin-menu group="email" />
@endsection

@section('rightbar')
  <div class="mt-3">
    <x-card.default title="Safety boundaries">
      <ul class="small text-muted mb-0 ps-3">
        <li>Preview contains operational counts and UIDs only.</li>
        <li>Import uses PEEK and never runs Inbox routing or provider cleanup.</li>
        <li>Provider reconciliation is read-only; IDLE is optional and scheduled cycles remain authoritative.</li>
        <li>Re-baseline starts future polling at current UIDNEXT and imports no history.</li>
        <li>Queue work remains serialized with normal provider polling.</li>
      </ul>
    </x-card.default>
  </div>
@endsection
