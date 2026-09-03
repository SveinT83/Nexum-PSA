@extends('layouts.default_tech')

@section('title', 'Email rules')

@section('pageHeader')
  <div class="d-flex align-items-center justify-content-between">
    <div>
      <h1>Email Rules</h1>
      <p class="text-muted mb-0">Inbound filters and routing rules for monitored mailboxes.</p>
    </div>
    <a href="{{ route('tech.admin.settings.email.rules.create') }}" class="btn btn-primary">Add rule</a>
  </div>
@endsection

@section('sidebar')
  <x-nav.admin-menu group="email" />
@endsection

@section('content')
  <div class="col-12">
    @if($missingTable)
      <div class="alert alert-warning">Email rules table not found. Run migrations before creating rules.</div>
    @endif

    <div class="mb-4">
      <h2 class="h5">System rules</h2>
      <div class="table-responsive">
        <table class="table table-sm align-middle">
          <thead class="table-light">
            <tr>
              <th>Name</th>
              <th>Trigger</th>
              <th>Condition</th>
              <th>Action</th>
              <th class="text-center">Status</th>
            </tr>
          </thead>
          <tbody>
            @foreach($systemRules as $rule)
              <tr>
                <td class="fw-semibold">{{ $rule['name'] }}</td>
                <td><span class="badge text-bg-light">{{ $rule['trigger'] }}</span></td>
                <td>{{ $rule['condition'] }}</td>
                <td>{{ $rule['action'] }}</td>
                <td class="text-center"><span class="badge text-bg-success">{{ $rule['status'] }}</span></td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </div>

    @if($reprocessPreview)
      <div class="alert alert-warning d-flex justify-content-between align-items-center gap-3">
        <div>
          <div class="fw-semibold">Reprocess preview — no actions have run</div>
          <div class="small">Rule: {{ $reprocessPreview->rule->name }}. Selected: {{ $reprocessPreview->requested_count }}. Matched: {{ $reprocessPreview->matched_count }}. Expires {{ $reprocessPreview->expires_at?->diffForHumans() }}.</div>
        </div>
        @can('email.rule_reprocess')
          <form method="POST" action="{{ route('tech.admin.settings.email.rules.reprocess-runs.apply', $reprocessPreview) }}">
            @csrf
            <button type="submit" class="btn btn-warning">Apply verified preview</button>
          </form>
        @endcan
      </div>
    @endif

    <div>
      <h2 class="h5">Custom inbound rules</h2>

      @if($rules->isEmpty())
        <div class="text-center py-5">
          <h3 class="h6 text-muted mb-3">No custom rules yet</h3>
          <a href="{{ route('tech.admin.settings.email.rules.create') }}" class="btn btn-outline-primary">Add first rule</a>
        </div>
      @else
        <div class="table-responsive">
          <table class="table table-sm align-middle">
            <thead class="table-light">
              <tr>
                <th style="width: 90px;">Weight</th>
                <th>Rule</th>
                <th>Mailboxes</th>
                <th>Conditions</th>
                <th>Actions</th>
                <th class="text-center" style="width: 110px;">Flow</th>
                <th class="text-center" style="width: 110px;">Status</th>
                <th class="text-end" style="width: 220px;">Actions</th>
              </tr>
            </thead>
            <tbody>
              @foreach($rules as $rule)
                <tr>
                  <td>{{ $rule->weight }}</td>
                  <td>
                    <div class="fw-semibold">{{ $rule->name }}</div>
                    <div class="small mt-1">
                      <span class="badge text-bg-light">{{ $rule->routing_phase === 'preclassification' ? 'Preclassification' : 'Normal phase' }}</span>
                      @if($rule->publishedVersion)
                        <span class="badge text-bg-info">v{{ $rule->publishedVersion->version_number }}</span>
                      @else
                        <span class="badge text-bg-warning">Unpublished legacy</span>
                      @endif
                      @if($rule->draft)
                        <span class="badge text-bg-warning">Unpublished draft</span>
                      @endif
                    </div>
                    @if($rule->description)
                      <div class="small text-muted">{{ $rule->description }}</div>
                    @endif
                    @if($rule->last_hit_at)
                      <div class="small text-muted">Hits: {{ $rule->hit_count }} · Last: {{ $rule->last_hit_at->diffForHumans() }}</div>
                    @endif
                  </td>
                  <td>
                    @forelse($rule->accounts as $account)
                      <div class="small">
                        <span class="badge text-bg-light">{{ $account->address }}</span>
                      </div>
                    @empty
                      <span class="badge text-bg-warning">Legacy scope</span>
                    @endforelse
                  </td>
                  <td>
                    @php($conditionGroups = data_get($rule->conditions_json, 'groups'))
                    @if($conditionGroups)
                      <div class="small text-muted mb-1">{{ (data_get($rule->conditions_json, 'match') === 'any') ? 'Any group' : 'All groups' }}</div>
                      @foreach($conditionGroups as $group)
                        <div class="small fw-semibold">{{ $group['name'] ?? 'Default' }} <span class="text-muted">({{ ($group['match'] ?? 'all') === 'any' ? 'any' : 'all' }})</span></div>
                        @foreach((array) ($group['conditions'] ?? []) as $condition)
                          <div class="small ms-2">
                            <code>{{ $condition['field'] ?? '' }}</code>
                            {{ str_replace('_', ' ', $condition['operator'] ?? '') }}
                            @if(($condition['value'] ?? '') !== '')
                              <code>{{ $condition['value'] }}</code>
                            @endif
                          </div>
                        @endforeach
                      @endforeach
                    @else
                      @foreach((array) $rule->conditions_json as $condition)
                        <div class="small">
                          <code>{{ $condition['field'] ?? '' }}</code>
                          {{ str_replace('_', ' ', $condition['operator'] ?? '') }}
                          @if(($condition['value'] ?? '') !== '')
                            <code>{{ $condition['value'] }}</code>
                          @endif
                        </div>
                      @endforeach
                    @endif
                  </td>
                  <td>
                    @foreach((array) $rule->actions_json as $action)
                      <div class="small">
                        <code>{{ str_replace('_', ' ', $action['type'] ?? '') }}</code>
                        @if(($action['value'] ?? $action['signal_type'] ?? '') !== '')
                          <code>{{ $action['value'] ?? $action['signal_type'] }}</code>
                        @endif
                      </div>
                    @endforeach
                  </td>
                  <td class="text-center">
                    <span class="badge {{ $rule->stop_processing ? 'text-bg-warning' : 'text-bg-light' }}">
                      {{ $rule->stop_processing ? 'Stop' : 'Continue' }}
                    </span>
                  </td>
                  <td class="text-center">
                    <span class="badge {{ $rule->is_active ? 'text-bg-success' : 'text-bg-secondary' }}">
                      {{ $rule->is_active ? 'Active' : 'Disabled' }}
                    </span>
                  </td>
                  <td class="text-end">
                    <div class="d-flex justify-content-end gap-1 flex-wrap">
                      <a href="{{ route('tech.admin.settings.email.rules.edit', $rule) }}" class="btn btn-sm btn-outline-secondary">Edit</a>
                      @can('email.rule_publish')
                        <form action="{{ route('tech.admin.settings.email.rules.toggle', $rule) }}" method="POST" class="d-inline">
                          @csrf
                          <button type="submit" class="btn btn-sm {{ $rule->is_active ? 'btn-outline-warning' : 'btn-outline-success' }}">
                            {{ $rule->is_active ? 'Disable' : 'Enable' }}
                          </button>
                        </form>
                        <form action="{{ route('tech.admin.settings.email.rules.destroy', $rule) }}" method="POST" class="d-inline" onsubmit="return confirm('Delete this email rule?');">
                          @csrf
                          @method('DELETE')
                          <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                        </form>
                      @endcan
                    </div>
                    @if($rule->publishedVersion && auth()->user()?->can('email.rule_reprocess'))
                      <form action="{{ route('tech.admin.settings.email.rules.reprocess-preview', $rule) }}" method="POST" class="d-flex gap-1 justify-content-end mt-2">
                        @csrf
                        <input name="email_message_id" type="number" min="1" class="form-control form-control-sm" placeholder="Message ID" aria-label="Email message ID" required style="max-width: 125px;">
                        <button type="submit" class="btn btn-sm btn-outline-primary">Preview rerun</button>
                      </form>
                    @endif
                  </td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      @endif
    </div>
  </div>
@endsection

@section('rightbar')
  <div class="mt-3">
    <h3 class="h6">Rule Order</h3>
    <p class="small text-muted">Opt-in preclassification rules run first by weight. The existing machine/AI classifier then runs before normal rules. A stop rule prevents the later phases and built-in ticket fallback.</p>
  </div>
@endsection
