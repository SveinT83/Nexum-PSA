@if($repairHistory !== [])
    {{-- Presenter output exposes bounded canonical facts, never raw source or model payloads. --}}
    <div class="card mt-3">
        <div class="card-header d-flex align-items-center justify-content-between gap-2">
            <h2 class="h6 mb-0">Repair Audit History</h2>
            <span class="badge text-bg-light border">{{ count($repairHistory) }} repairs</span>
        </div>
        <div class="card-body">
            <div class="vstack gap-3">
                @foreach($repairHistory as $repair)
                    <div class="card" id="repair-{{ $repair['id'] }}">
                        <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                            <div class="d-flex flex-wrap align-items-center gap-2">
                                <h3 class="h6 mb-0">Repair #{{ $repair['sequence'] }}</h3>
                                <span class="badge {{ $repair['outcome_badge'] }}">{{ $repair['outcome_label'] }}</span>
                                <span class="badge text-bg-light border">{{ str($repair['method'])->replace('_', ' ')->title() }}</span>
                            </div>
                            <div class="small text-muted">{{ $repair['actor_name'] }} / {{ $repair['recorded_at']?->format('d.m.Y H:i:s') }}</div>
                        </div>
                        <div class="card-body">
                            <div class="alert {{ $repair['outcome'] === 'blocked' ? 'alert-warning' : ($repair['outcome'] === 'applied' ? 'alert-success' : 'alert-secondary') }} py-2">
                                {{ $repair['outcome_reason'] }}
                                @if($repair['blocked_reason'])<div class="small font-monospace mt-1">{{ $repair['blocked_reason'] }}</div>@endif
                            </div>

                            @if($repair['reason'] || $repair['diagnosis'] || $repair['change_summary'] !== [])
                                <div class="mb-3">
                                    @if($repair['reason'])<div><strong>Reason:</strong> {{ $repair['reason'] }}</div>@endif
                                    @if($repair['diagnosis'])<div><strong>Diagnosis:</strong> {{ $repair['diagnosis'] }}</div>@endif
                                    @if($repair['change_summary'] !== [])
                                        <ul class="mb-0 mt-1">@foreach($repair['change_summary'] as $change)<li>{{ $change }}</li>@endforeach</ul>
                                    @endif
                                </div>
                            @endif

                            {{-- Persisted facts let operators reconcile derived outcome with immutable storage. --}}
                            <div class="row g-2 small mb-3">
                                <div class="col-md-6 col-xl-3"><div class="text-muted">Persisted state</div><div>{{ str($repair['persisted_status'])->replace('_', ' ')->title() }}</div></div>
                                <div class="col-md-6 col-xl-3"><div class="text-muted">Original checksum</div><div class="font-monospace" title="{{ $repair['original_document_checksum'] }}">{{ str($repair['original_document_checksum'] ?: '—')->limit(20) }}</div></div>
                                <div class="col-md-6 col-xl-3"><div class="text-muted">Corrected checksum</div><div class="font-monospace" title="{{ $repair['corrected_document_checksum'] }}">{{ str($repair['corrected_document_checksum'] ?: '—')->limit(20) }}</div></div>
                                <div class="col-md-6 col-xl-3"><div class="text-muted">AI execution</div><div class="font-monospace" title="{{ $repair['ai_execution_uuid'] }}">{{ str($repair['ai_execution_uuid'] ?: '—')->limit(20) }}</div></div>
                            </div>

                            {{-- Only existing domain actions are offered; immutable proposals are not mutated here. --}}
                            @if($repair['can_retry'] || $repair['can_open_purchase_order'] || data_get($repair, 'profile_candidate.can_open'))
                                <div class="d-flex flex-wrap gap-2 mb-3">
                                    @if($repair['can_retry'])
                                        <form method="POST" action="{{ route('tech.storage.purchase-order-imports.retry', $import) }}">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-primary">Reprocess Current Applied Repair</button>
                                        </form>
                                    @endif
                                    @if($repair['can_open_purchase_order'])
                                        <a class="btn btn-sm btn-outline-primary" href="{{ route('tech.storage.purchase-orders.show', $repair['purchase_order_id']) }}">Open Purchase Order</a>
                                    @endif
                                    @if(data_get($repair, 'profile_candidate.can_open'))
                                        <a class="btn btn-sm btn-outline-secondary"
                                           href="{{ route('tech.admin.settings.storage.supplier-order-profiles.show', data_get($repair, 'profile_candidate.profile_id')) }}#profile-version-{{ data_get($repair, 'profile_candidate.id') }}">Open Candidate Version</a>
                                    @endif
                                </div>
                            @endif
                            @if($repair['outcome'] === 'blocked')
                                <div class="small text-muted mb-3">There is no direct apply or reject command for an immutable blocked proposal. Correct the guard condition and run a new governed repair where appropriate.</div>
                            @endif

                            {{-- Diff uses the exact bounded before projection for new repairs and marks legacy gaps. --}}
                            <details open class="mb-3">
                                <summary class="fw-semibold">Before / After Diff</summary>
                                <div class="small text-muted mt-1">{{ $repair['before_source'] }}</div>
                                @if(! $repair['before_available'])
                                    <div class="alert alert-light border py-2 mt-2 mb-0">Detailed before values were not stored for this legacy record; the original checksum remains available above.</div>
                                @elseif($repair['diff'] === [])
                                    <div class="alert alert-light border py-2 mt-2 mb-0">No changes are visible in the bounded canonical projection.</div>
                                @else
                                    <div class="table-responsive mt-2">
                                        <table class="table table-sm table-bordered align-middle mb-0">
                                            <thead class="table-light"><tr><th>Field</th><th>Before</th><th>After</th></tr></thead>
                                            <tbody>@foreach($repair['diff'] as $change)<tr><td>{{ $change['field'] }}</td><td>{{ $change['before'] }}</td><td>{{ $change['after'] }}</td></tr>@endforeach</tbody>
                                        </table>
                                    </div>
                                    @if($repair['diff_truncated'])<div class="alert alert-warning py-2 mt-2 mb-0">The bounded diff reached its display limit.</div>@endif
                                @endif
                            </details>

                            <details class="mb-3">
                                <summary class="fw-semibold">Verified Evidence ({{ count($repair['evidence']) }})</summary>
                                @if($repair['evidence'] === [])
                                    <div class="small text-muted mt-2">No display-safe evidence anchors were stored.</div>
                                @else
                                    <div class="table-responsive mt-2">
                                        <table class="table table-sm align-middle mb-0">
                                            <thead class="table-light"><tr><th>Field</th><th>Source / locator</th><th>Bounded quote</th><th>Fingerprint</th></tr></thead>
                                            <tbody>
                                            @foreach($repair['evidence'] as $evidence)
                                                <tr>
                                                    <td>{{ $evidence['field'] }}</td>
                                                    <td>{{ str($evidence['source'] ?: 'source evidence')->replace('_', ' ')->title() }}@if($evidence['locator'])<div class="small font-monospace">{{ $evidence['locator'] }}</div>@endif</td>
                                                    <td>{{ $evidence['quote'] ?: '—' }}</td>
                                                    <td class="small font-monospace" title="{{ $evidence['source_fingerprint'] }}">{{ str($evidence['source_fingerprint'] ?: '—')->limit(18) }}</td>
                                                </tr>
                                            @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                    @if($repair['evidence_truncated'])<div class="alert alert-warning py-2 mt-2 mb-0">Additional evidence anchors were omitted by the bounded display limit.</div>@endif
                                @endif
                            </details>

                            @if($repair['ai_governance'])
                                @php($governance = $repair['ai_governance'])
                                <details class="mb-3">
                                    <summary class="fw-semibold">AI Governance, Budget, and Consensus</summary>
                                    <div class="row g-2 small mt-2">
                                        <div class="col-md-6 col-xl-3">
                                            <div class="text-muted">Budget</div>
                                            <div>{{ $governance['budget_limit'] ?: '—' }} {{ $governance['budget_currency'] }}</div>
                                        </div>
                                        <div class="col-md-6 col-xl-3">
                                            <div class="text-muted">Spent / remaining</div>
                                            <div>{{ $governance['budget_spent'] ?: '—' }} / {{ $governance['budget_remaining'] ?: '—' }} {{ $governance['budget_currency'] }}</div>
                                        </div>
                                        <div class="col-md-6 col-xl-3">
                                            <div class="text-muted">Primary workload / cost</div>
                                            <div>{{ $governance['primary_workload'] ?: '—' }} / {{ $governance['primary_cost'] ?: '—' }} {{ $governance['primary_cost_currency'] }}</div>
                                        </div>
                                        <div class="col-md-6 col-xl-3">
                                            <div class="text-muted">Consensus / secondary cost</div>
                                            <div>{{ str($governance['consensus_status'] ?: 'not_recorded')->replace('_', ' ')->title() }} / {{ $governance['consensus_cost'] ?: '—' }} {{ $governance['consensus_cost_currency'] }}</div>
                                        </div>
                                    </div>
                                    @if($governance['budget_reason_code'])
                                        <div class="small mt-1">Budget decision: <span class="font-monospace">{{ $governance['budget_reason_code'] }}</span></div>
                                    @endif
                                    <details class="small mt-2">
                                        <summary>Technical execution facts</summary>
                                        <div class="font-monospace mt-1">
                                            Primary execution {{ $governance['primary_execution_id'] ?: '—' }};
                                            provider {{ $governance['primary_provider_id'] ?: '—' }};
                                            agent {{ $governance['primary_agent_id'] ?: '—' }};
                                            access event {{ $governance['primary_access_event_id'] ?: '—' }}.
                                        </div>
                                        @if($governance['consensus_execution_id'] || $governance['consensus_workload'])
                                            <div class="font-monospace">Consensus workload {{ $governance['consensus_workload'] ?: '—' }}; execution {{ $governance['consensus_execution_id'] ?: '—' }}; provider {{ $governance['consensus_provider_id'] ?: '—' }}; agent {{ $governance['consensus_agent_id'] ?: '—' }}; access event {{ $governance['consensus_access_event_id'] ?: '—' }}.</div>
                                        @endif
                                        @if($governance['primary_checksum'] || $governance['secondary_checksum'])
                                            <div class="font-monospace">Primary checksum {{ $governance['primary_checksum'] ?: '—' }}; secondary checksum {{ $governance['secondary_checksum'] ?: '—' }}.</div>
                                        @endif
                                    </details>
                                </details>
                            @endif

                            <details>
                                <summary class="fw-semibold">Validation and Candidate Reproduction</summary>
                                <div class="mt-2">
                                    @if($repair['validation_recorded'])
                                        <span class="badge {{ $repair['validation_valid'] ? 'text-bg-success' : 'text-bg-danger' }}">Validation {{ $repair['validation_valid'] ? 'passed' : 'failed' }}</span>
                                    @else
                                        <span class="badge text-bg-secondary">Validation not recorded</span>
                                    @endif
                                    @foreach($repair['confidence_dimensions'] as $dimension => $value)
                                        <span class="badge text-bg-light border">{{ str($dimension)->replace('_', ' ')->title() }}: {{ $value }}</span>
                                    @endforeach
                                </div>
                                @foreach(['validation_errors' => 'Errors', 'validation_warnings' => 'Warnings'] as $key => $label)
                                    @if($repair[$key] !== [])
                                        <div class="small fw-semibold mt-2">{{ $label }}</div>
                                        <ul class="small mb-0">@foreach($repair[$key] as $issue)<li><span class="font-monospace">{{ $issue['code'] }}</span> / {{ $issue['path'] }}@if($issue['message']): {{ $issue['message'] }}@endif</li>@endforeach</ul>
                                    @endif
                                @endforeach
                                @if($repair['candidate_reproduction'])
                                    <div class="small mt-2">
                                        Candidate reproduction: current {{ $repair['candidate_reproduction']['current_samples'] }},
                                        protected fixtures {{ $repair['candidate_reproduction']['protected_fixture_samples'] }},
                                        historical {{ $repair['candidate_reproduction']['historical_samples'] }}.
                                    </div>
                                @endif
                                @if($repair['profile_candidate'])
                                    <div class="small mt-1">Candidate {{ $repair['profile_candidate']['profile_name'] }} v{{ $repair['profile_candidate']['version_number'] }} / {{ str($repair['profile_candidate']['status'])->replace('_', ' ')->title() }}.</div>
                                @endif
                            </details>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
@endif
