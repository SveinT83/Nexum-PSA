@extends('layouts.default_tech')

@section('title', 'Ticket Rule execution #'.$run->id)

@section('pageHeader')
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
        <div>
            <div class="d-flex align-items-center gap-2">
                <h1 class="mb-0">Ticket Rule execution #{{ $run->id }}</h1>
                <span class="badge text-bg-{{ $evidence['summary']['status_class'] }}">{{ $evidence['summary']['status_label'] }}</span>
            </div>
            <p class="text-muted mb-0">{{ $evidence['summary']['root_event_label'] }} for {{ $evidence['summary']['ticket_key'] }}</p>
        </div>
        <div class="d-flex gap-2">
            <x-buttons.back url="{{ route('tech.admin.settings.tickets.rules.executions.index') }}">Executions</x-buttons.back>
            @if($evidence['summary']['ticket_available'])
                <a href="{{ route('tech.tickets.show', ['ticket' => $evidence['summary']['ticket_id']]) }}" class="btn btn-sm btn-outline-primary">
                    Open Ticket
                </a>
            @endif
        </div>
    </div>
@endsection

@section('content')
    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if(session('warning'))
        <div class="alert alert-warning">{{ session('warning') }}</div>
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
    @if($evidence['restricted_evidence'])
        <div class="alert alert-secondary">
            {{ $evidence['restricted_message'] }}
        </div>
    @else

    <!-- ------------------------------------------------- -->
    <!-- Immutable Run Overview -->
    <!-- ------------------------------------------------- -->
    <div class="card mb-3">
        <div class="card-header">
            <h2 class="h6 mb-0">Run overview</h2>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-12 col-md-6 col-xl-3">
                    <div class="small text-muted">Started</div>
                    <div>{{ $evidence['summary']['started_at']?->format('Y-m-d H:i:s.u') ?? '—' }}</div>
                </div>
                <div class="col-12 col-md-6 col-xl-3">
                    <div class="small text-muted">Completed</div>
                    <div>{{ $evidence['summary']['completed_at']?->format('Y-m-d H:i:s.u') ?? '—' }}</div>
                </div>
                <div class="col-12 col-md-6 col-xl-3">
                    <div class="small text-muted">Mode and attempt</div>
                    <div>{{ \Illuminate\Support\Str::headline($evidence['summary']['mode']) }} · {{ $evidence['summary']['attempt_number'] }}</div>
                </div>
                <div class="col-12 col-md-6 col-xl-3">
                    <div class="small text-muted">Duration</div>
                    <div>{{ $evidence['summary']['duration_ms'] === null ? '—' : number_format($evidence['summary']['duration_ms']).' ms' }}</div>
                </div>
                <div class="col-12 col-md-6">
                    <div class="small text-muted">Initiator</div>
                    <div>{{ $evidence['initiator'] }}</div>
                </div>
                <div class="col-12 col-md-6">
                    <div class="small text-muted">Automation actor</div>
                    <div>{{ $evidence['automation_actor'] }}</div>
                </div>
                <div class="col-12 col-md-6">
                    <div class="small text-muted">Source</div>
                    <div>{{ $evidence['source_channel'] ?: '—' }} · {{ $evidence['source_action'] }}</div>
                </div>
                <div class="col-12 col-md-6">
                    <div class="small text-muted">Authority generation</div>
                    <div>{{ $evidence['authority_generation'] }}</div>
                </div>
                <div class="col-12">
                    <div class="small text-muted">Correlation</div>
                    <code>{{ $evidence['correlation_uuid'] }}</code>
                    @if($evidence['causation_uuid'])
                        <span class="text-muted mx-1">caused by</span>
                        <code>{{ $evidence['causation_uuid'] }}</code>
                    @endif
                </div>
                @if($evidence['summary']['retry_of_run_id'])
                    <div class="col-12">
                        <div class="small text-muted">Source run</div>
                        <a href="{{ route('tech.admin.settings.tickets.rules.executions.show', ['run' => $evidence['summary']['retry_of_run_id']]) }}">
                            Execution #{{ $evidence['summary']['retry_of_run_id'] }}
                        </a>
                    </div>
                @endif
                @if($evidence['termination_reason'])
                    <div class="col-12">
                        <div class="small text-muted">Termination reason</div>
                        <code>{{ $evidence['termination_reason'] }}</code>
                    </div>
                @endif
            </div>
        </div>
        <div class="card-footer small text-muted">
            Published versions:
            {{ $evidence['published_version_ids'] === [] ? 'none' : implode(', ', $evidence['published_version_ids']) }}
        </div>
    </div>

    <!-- ------------------------------------------------- -->
    <!-- Safe Summary And Counters -->
    <!-- ------------------------------------------------- -->
    <div class="row g-3 mb-3">
        <div class="col-12 col-xl-6">
            <div class="card h-100">
                <div class="card-header"><h2 class="h6 mb-0">Counters</h2></div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0">
                        <tbody>
                            @forelse($evidence['counters'] as $item)
                                <tr><th>{{ $item['key'] }}</th><td class="text-end">{{ $item['value'] }}</td></tr>
                            @empty
                                <tr><td class="text-muted">No counters were recorded.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-12 col-xl-6">
            <div class="card h-100">
                <div class="card-header"><h2 class="h6 mb-0">Safe summary</h2></div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0">
                        <tbody>
                            @forelse($evidence['safe_summary'] as $item)
                                <tr><th>{{ $item['key'] }}</th><td class="text-end">{{ $item['value'] }}</td></tr>
                            @empty
                                <tr><td class="text-muted">No safe summary was recorded.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- ------------------------------------------------- -->
    <!-- Event Evidence -->
    <!-- ------------------------------------------------- -->
    <div class="card mb-3">
        <div class="card-header">
            <h2 class="h6 mb-0">Events ({{ count($evidence['events']) }})</h2>
        </div>
        <div class="card-body">
            @forelse($evidence['events'] as $event)
                <div class="border rounded p-3 {{ $loop->last ? '' : 'mb-3' }}">
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                        <div>
                            <span class="fw-semibold">#{{ $event['sequence'] }} {{ $event['event_label'] }}</span>
                            <code class="ms-1">{{ $event['event_key'] }}</code>
                        </div>
                        <span class="badge text-bg-{{ $event['status_class'] }}">{{ $event['status_label'] }}</span>
                    </div>
                    <div class="small text-muted mb-2">
                        {{ $event['source_channel'] ?: '—' }} · {{ $event['source_action'] }}
                        · depth {{ $event['chain_depth'] }}
                    </div>
                    @if($event['changed_fields'] !== [])
                        <div class="mb-2">
                            <span class="small text-muted">Changed:</span>
                            @foreach($event['changed_fields'] as $field)
                                <span class="badge text-bg-light">{{ $field }}</span>
                            @endforeach
                        </div>
                    @endif
                    <div class="row g-3">
                        <div class="col-12 col-lg-6">
                            <div class="small fw-semibold mb-1">Before (safe projection)</div>
                            <dl class="row small mb-0">
                                @forelse($event['before'] as $item)
                                    <dt class="col-5">{{ $item['label'] }}</dt>
                                    <dd class="col-7">{{ $item['value'] }}</dd>
                                @empty
                                    <dd class="text-muted mb-0">No before evidence.</dd>
                                @endforelse
                            </dl>
                        </div>
                        <div class="col-12 col-lg-6">
                            <div class="small fw-semibold mb-1">After (safe projection)</div>
                            <dl class="row small mb-0">
                                @forelse($event['after'] as $item)
                                    <dt class="col-5">{{ $item['label'] }}</dt>
                                    <dd class="col-7">{{ $item['value'] }}</dd>
                                @empty
                                    <dd class="text-muted mb-0">No after evidence.</dd>
                                @endforelse
                            </dl>
                        </div>
                    </div>
                </div>
            @empty
                <p class="text-muted mb-0">No event evidence was recorded.</p>
            @endforelse
        </div>
    </div>

    <!-- ------------------------------------------------- -->
    <!-- Rule Execution And Action Evidence -->
    <!-- ------------------------------------------------- -->
    <div class="card mb-3">
        <div class="card-header">
            <h2 class="h6 mb-0">Rule evaluations ({{ count($evidence['executions']) }})</h2>
        </div>
        <div class="card-body">
            @forelse($evidence['executions'] as $execution)
                <div class="border rounded p-3 {{ $loop->last ? '' : 'mb-3' }}">
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                        <div>
                            <span class="text-muted me-1">#{{ $execution['order_position'] }}</span>
                            @if($execution['rule_available'] && $canViewRuleDetails)
                                <a href="{{ route('tech.admin.settings.tickets.rules.show', ['rule' => $execution['rule_id']]) }}" class="fw-semibold text-decoration-none">
                                    {{ $execution['rule_name'] }}
                                </a>
                            @else
                                <span class="fw-semibold">{{ $execution['rule_name'] }}</span>
                            @endif
                            <span class="small text-muted">version {{ $execution['version_number'] ?? $execution['rule_version_id'] }}</span>
                        </div>
                        <span class="badge text-bg-{{ $execution['status_class'] }}">{{ $execution['status_label'] }}</span>
                    </div>
                    <div class="row g-2 small mb-3">
                        <div class="col-6 col-lg-3"><span class="text-muted">Branch:</span> {{ $execution['selected_branch'] ?? '—' }}</div>
                        <div class="col-6 col-lg-3"><span class="text-muted">Matched:</span> {{ $execution['conditions_matched'] ? 'Yes' : 'No' }}</div>
                        <div class="col-6 col-lg-3"><span class="text-muted">Attempt:</span> {{ $execution['attempt_number'] }}</div>
                        <div class="col-6 col-lg-3"><span class="text-muted">Duration:</span> {{ $execution['duration_ms'] === null ? '—' : number_format($execution['duration_ms']).' ms' }}</div>
                    </div>

                    @if($execution['condition_evidence'])
                        <div class="mb-3">
                            <div class="small fw-semibold mb-2">Condition evidence</div>
                            @forelse($execution['condition_evidence']['groups'] as $group)
                                <div class="table-responsive mb-2">
                                    <table class="table table-sm table-bordered mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th colspan="5">Group {{ $group['position'] + 1 }} · {{ $group['match'] }} · {{ $group['passed'] ? 'passed' : 'failed' }}</th>
                                            </tr>
                                            <tr><th>Field</th><th>Operator</th><th>Expected</th><th>Actual</th><th>Result</th></tr>
                                        </thead>
                                        <tbody>
                                            @forelse($group['rows'] as $row)
                                                <tr>
                                                    <td>{{ $row['field_label'] }}</td>
                                                    <td><code>{{ $row['operator'] }}</code></td>
                                                    <td>{{ $row['expected'] }}</td>
                                                    <td>{{ $row['actual'] }}</td>
                                                    <td><span class="badge text-bg-{{ $row['passed'] ? 'success' : 'secondary' }}">{{ $row['passed'] ? 'Pass' : 'Fail' }}</span></td>
                                                </tr>
                                            @empty
                                                <tr><td colspan="5" class="text-muted">No condition rows.</td></tr>
                                            @endforelse
                                        </tbody>
                                    </table>
                                </div>
                            @empty
                                <p class="small text-muted">No grouped condition evidence.</p>
                            @endforelse
                        </div>
                    @endif

                    @if($execution['failure_code'] || $execution['failure_message'])
                        <div class="alert alert-danger py-2">
                            <code>{{ $execution['failure_code'] ?: 'execution_failed' }}</code>
                            @if($execution['failure_message'])
                                <span class="ms-1">{{ $execution['failure_message'] }}</span>
                            @endif
                        </div>
                    @endif

                    <div class="small fw-semibold mb-2">Actions</div>
                    @forelse($execution['actions'] as $action)
                        <div class="border rounded p-2 {{ $loop->last ? '' : 'mb-2' }}">
                            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                                <div>
                                    <span class="fw-semibold">{{ $action['position'] + 1 }}. {{ $action['action_label'] }}</span>
                                    <span class="small text-muted">attempt {{ $action['attempt_number'] }}</span>
                                </div>
                                <div class="d-flex align-items-center gap-2">
                                    <span class="badge text-bg-{{ $action['status_class'] }}">{{ $action['status_label'] }}</span>
                                    @if(in_array($action['id'], $retryableActionIds, true))
                                        <form
                                            method="POST"
                                            action="{{ route('tech.admin.settings.tickets.rules.executions.actions.retry', ['run' => $run->id, 'actionResult' => $action['id']]) }}"
                                        >
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-outline-warning">Retry position</button>
                                        </form>
                                    @endif
                                </div>
                            </div>
                            <div class="row g-3 mt-1">
                                <div class="col-12 col-lg-4">
                                    <div class="small text-muted">Safe input</div>
                                    <dl class="row small mb-0">
                                        @forelse($action['input'] as $item)
                                            <dt class="col-5">{{ $item['key'] }}</dt><dd class="col-7">{{ $item['value'] }}</dd>
                                        @empty
                                            <dd class="text-muted mb-0">No input evidence.</dd>
                                        @endforelse
                                    </dl>
                                </div>
                                <div class="col-12 col-lg-4">
                                    <div class="small text-muted">Safe changes</div>
                                    <dl class="row small mb-0">
                                        @forelse($action['changes'] as $item)
                                            <dt class="col-5">{{ $item['label'] }}</dt><dd class="col-7">{{ $item['value'] }}</dd>
                                        @empty
                                            <dd class="text-muted mb-0">No changes.</dd>
                                        @endforelse
                                    </dl>
                                </div>
                                <div class="col-12 col-lg-4">
                                    <div class="small text-muted">Authorization</div>
                                    <dl class="row small mb-0">
                                        @forelse($action['authorization'] as $item)
                                            <dt class="col-5">{{ $item['key'] }}</dt><dd class="col-7">{{ $item['value'] }}</dd>
                                        @empty
                                            <dd class="text-muted mb-0">No authorization evidence.</dd>
                                        @endforelse
                                    </dl>
                                </div>
                            </div>
                            @if($action['failure_code'] || $action['failure_message'])
                                <div class="small text-danger mt-2">
                                    <code>{{ $action['failure_code'] ?: 'action_failed' }}</code>
                                    {{ $action['failure_message'] }}
                                </div>
                            @endif
                        </div>
                    @empty
                        <p class="small text-muted mb-0">No action positions were recorded.</p>
                    @endforelse
                    @if($execution['action_attempts_omitted_count'] > 0)
                        <div class="alert alert-secondary py-2 small mt-2 mb-0">
                            {{ number_format($execution['action_attempts_omitted_count']) }} earlier action attempts were omitted by the per-position history bound.
                        </div>
                    @endif
                </div>
            @empty
                <p class="text-muted mb-0">No rule evaluations were recorded.</p>
            @endforelse
        </div>
    </div>

    <!-- ------------------------------------------------- -->
    <!-- After-commit Delivery Evidence -->
    <!-- ------------------------------------------------- -->
    @if($evidence['after_commit_results'] !== [])
        <div class="card">
            <div class="card-header"><h2 class="h6 mb-0">After-commit deliveries</h2></div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr><th>Type</th><th>Attempt</th><th>Status</th><th>External reference</th><th>Failure</th></tr>
                    </thead>
                    <tbody>
                        @foreach($evidence['after_commit_results'] as $delivery)
                            <tr>
                                <td>{{ \Illuminate\Support\Str::headline($delivery['delivery_type']) }}</td>
                                <td>{{ $delivery['attempt_number'] }} / {{ $delivery['attempt_count'] }}</td>
                                <td><span class="badge text-bg-{{ $delivery['status_class'] }}">{{ $delivery['status_label'] }}</span></td>
                                <td><code>{{ $delivery['external_reference_fingerprint'] ?: '—' }}</code></td>
                                <td>
                                    @if($delivery['failure_code'])
                                        <code>{{ $delivery['failure_code'] }}</code>
                                    @endif
                                    {{ $delivery['failure_message'] }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
    @endif
@endsection

@section('sidebar')
    <x-nav.admin-menu group="tickets" />
@endsection

@section('rightbar')
    <x-card.default title="Controlled action retry">
        <p class="small text-muted mb-0">Retry is shown only for failed or not-run idempotent synchronous positions whose immutable definition and current Ticket precondition still match. Successful sibling positions are never replayed.</p>
    </x-card.default>

    <div class="mt-3">
        <x-card.default title="Full rerun boundary">
            @if($fullRerunAvailable)
                <div class="alert alert-warning py-2 small">
                    <strong>Ticket created only.</strong> Full rerun evaluates the complete current published rule set and may repeat Ticket changes, internal notes, signals, or queued external deliveries.
                </div>
                @if(is_array($fullRerunPreview))
                    <dl class="row small mb-3">
                        <dt class="col-7">Planned rules</dt><dd class="col-5 text-end">{{ $fullRerunPreview['planned_rule_count'] }}</dd>
                        <dt class="col-7">Planned actions</dt><dd class="col-5 text-end">{{ $fullRerunPreview['planned_action_count'] }}</dd>
                        <dt class="col-7">Displayed action rows</dt><dd class="col-5 text-end">{{ $fullRerunPreview['planned_action_displayed_count'] }}</dd>
                        <dt class="col-7">Omitted action rows</dt><dd class="col-5 text-end">{{ $fullRerunPreview['planned_action_omitted_count'] }}</dd>
                        <dt class="col-7">Omitted rule rows</dt><dd class="col-5 text-end">{{ $fullRerunPreview['planned_rules_omitted_count'] }}</dd>
                        <dt class="col-7">Collisions</dt><dd class="col-5 text-end">{{ $fullRerunPreview['collision_count'] }}</dd>
                        <dt class="col-7">Loop blocks</dt><dd class="col-5 text-end">{{ $fullRerunPreview['loop_block_count'] }}</dd>
                        <dt class="col-7">Loop risk</dt><dd class="col-5 text-end">{{ \Illuminate\Support\Str::headline($fullRerunPreview['loop_risk_status']) }}</dd>
                    </dl>

                    <!-- The confirmation uses only a bounded allowlisted projection of the exact signed plan. -->
                    <div class="small mb-3">
                        <h3 class="h6 mb-2">Planned rules and actions</h3>
                        @forelse($fullRerunPreview['planned_rules'] as $plannedRule)
                            <div class="border rounded p-2 mb-2">
                                <div class="d-flex flex-wrap justify-content-between gap-1">
                                    <strong>Rule #{{ $plannedRule['ticket_rule_id'] }} / version #{{ $plannedRule['rule_version_id'] }}</strong>
                                    <span class="badge text-bg-secondary">{{ \Illuminate\Support\Str::headline($plannedRule['status']) }}</span>
                                </div>
                                <div class="text-muted">
                                    Event {{ $plannedRule['event_sequence'] }}:
                                    <code>{{ $plannedRule['event_key'] }}</code>
                                    - order {{ $plannedRule['order_position'] }}
                                    @if($plannedRule['selected_branch'])
                                        - {{ $plannedRule['selected_branch'] }} branch
                                    @endif
                                </div>
                                @if($plannedRule['reason_code'])
                                    <div>Reason: <code>{{ $plannedRule['reason_code'] }}</code></div>
                                @endif
                                @foreach($plannedRule['actions'] as $plannedAction)
                                    <div class="border-top mt-2 pt-2">
                                        <div class="d-flex flex-wrap justify-content-between gap-1">
                                            <span>
                                                <code>#{{ $plannedAction['position'] + 1 }}</code>
                                                {{ \Illuminate\Support\Str::headline($plannedAction['type']) }}
                                            </span>
                                            <span class="badge text-bg-secondary">{{ \Illuminate\Support\Str::headline($plannedAction['status']) }}</span>
                                        </div>
                                        <div><strong>Safe target:</strong> {{ $plannedAction['target'] }}</div>
                                        <ul class="mb-1 ps-3">
                                            @foreach($plannedAction['change_summary'] as $summary)
                                                <li>{{ $summary }}</li>
                                            @endforeach
                                        </ul>
                                        @if($plannedAction['change_summary_omitted_count'] > 0)
                                            <div class="text-warning-emphasis">{{ $plannedAction['change_summary_omitted_count'] }} change summaries omitted.</div>
                                        @endif
                                        @if($plannedAction['reason_code'])
                                            <div>Reason: <code>{{ $plannedAction['reason_code'] }}</code></div>
                                        @endif
                                    </div>
                                @endforeach
                                @if($plannedRule['actions_omitted_count'] > 0)
                                    <div class="text-warning-emphasis mt-2">{{ $plannedRule['actions_omitted_count'] }} action rows omitted for this rule.</div>
                                @endif
                            </div>
                        @empty
                            <p class="text-muted">No rule actions are planned.</p>
                        @endforelse

                        @if($fullRerunPreview['planned_rules_omitted_count'] > 0
                            || $fullRerunPreview['planned_action_omitted_count'] > 0)
                            <div class="alert alert-warning py-2">
                                {{ $fullRerunPreview['planned_rules_omitted_count'] }} rule rows and
                                {{ $fullRerunPreview['planned_action_omitted_count'] }} action rows are explicitly omitted by the display bound.
                            </div>
                        @endif

                        <h3 class="h6 mt-3 mb-2">Collision review</h3>
                        @forelse($fullRerunPreview['planned_collisions'] as $collision)
                            <div class="border rounded p-2 mb-2">
                                <strong>{{ $collision['target'] }}</strong>:
                                rule #{{ $collision['previous_writer']['ticket_rule_id'] }}
                                action #{{ $collision['previous_writer']['action_position'] + 1 }}
                                is followed by rule #{{ $collision['new_writer']['ticket_rule_id'] }}
                                action #{{ $collision['new_writer']['action_position'] + 1 }}
                                ({{ \Illuminate\Support\Str::headline($collision['resolution']) }}).
                            </div>
                        @empty
                            <p class="text-muted">No collisions are present in the displayed plan.</p>
                        @endforelse
                        <p class="text-muted">{{ $fullRerunPreview['planned_collisions_omitted_count'] }} collision rows omitted.</p>

                        <h3 class="h6 mt-3 mb-2">Loop-block review</h3>
                        @forelse($fullRerunPreview['planned_loop_blocks'] as $loopBlock)
                            <div class="border rounded p-2 mb-2">
                                <code>{{ $loopBlock['reason_code'] }}</code>
                                on <code>{{ $loopBlock['event_key'] }}</code>,
                                depth {{ $loopBlock['chain_depth'] }},
                                rule order {{ $loopBlock['rule_order_position'] }}
                                @if($loopBlock['action_position'] !== null)
                                    , action #{{ $loopBlock['action_position'] + 1 }}
                                @endif
                            </div>
                        @empty
                            <p class="text-muted">No loop blocks are present in the displayed plan.</p>
                        @endforelse
                        <p class="text-muted mb-0">{{ $fullRerunPreview['planned_loop_blocks_omitted_count'] }} loop-block rows omitted.</p>
                    </div>
                    <form method="POST" action="{{ route('tech.admin.settings.tickets.rules.executions.rerun.store', ['run' => $run->id]) }}">
                        @csrf
                        <input type="hidden" name="preview_receipt" value="{{ $fullRerunPreview['receipt'] }}">
                        <div class="form-check mb-3">
                            <input id="confirm_full_rerun" name="confirm_full_rerun" value="1" type="checkbox" class="form-check-input" required>
                            <label for="confirm_full_rerun" class="form-check-label small">I reviewed every displayed planned action and all explicit omission, collision, and loop-block warnings. I understand a new immutable run may repeat changes or deliveries.</label>
                        </div>
                        <button type="submit" class="btn btn-sm btn-danger">Run reviewed full rerun</button>
                    </form>
                @else
                    <form method="POST" action="{{ route('tech.admin.settings.tickets.rules.executions.rerun.preview', ['run' => $run->id]) }}">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-outline-warning">Generate full-rerun preview</button>
                    </form>
                @endif
            @else
                <p class="small text-muted mb-0">Unavailable. This control requires a terminal <code>ticket.created</code> run, active v2 authority, the default-off capability switch, and both preview and full-rerun permissions.</p>
            @endif
        </x-card.default>
    </div>

    <div class="mt-3">
        <x-card.default title="Evidence boundary">
            <p class="small text-muted mb-0">Only allowlisted operational evidence is rendered. Raw payloads and Custom Field values remain redacted.</p>
        </x-card.default>
    </div>
@endsection
