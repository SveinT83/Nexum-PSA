{{-- Registered effort and financial snapshots are separate measures, not additive totals --}}
@php($duration = fn ($minutes) => intdiv((int) $minutes, 60).'h '.((int) $minutes % 60).'m')
<div class="alert alert-light border small">
    <strong>{{ $client->name }}</strong> · Work dates: {{ $from ?? 'All history' }} to {{ $to ?? 'No end date' }}.
    Generated {{ $generated_at->format('Y-m-d H:i T') }}.<br>
    Registered work counts direct Ticket time, Task time and direct timebank registrations once.
    Task billing projections are included only in billing basis. Registered time can include estimate-completion entries and is not approved payroll.
    Covered and billable show stored processed allocations; unresolved billing basis has no allocation yet.
    Objects with no time in the period remain listed. Private Tasks are excluded. Archived and ordered history is included.
</div>
<div class="row g-2 mb-3">
    @foreach(['work_minutes' => 'Registered work', 'basis_minutes' => 'Customer billing basis', 'covered_minutes' => 'Covered (processed)', 'processed_billable_minutes' => 'Billable (processed)', 'unresolved_minutes' => 'Unresolved billing basis'] as $key => $label)
        <div class="col"><div class="border rounded p-2"><div class="small text-muted">{{ $label }}</div><strong>{{ $duration($totals[$key]) }}</strong></div></div>
    @endforeach
</div>

{{-- One row per object: Task work is never rolled up again into its parent Ticket --}}
<div class="card mb-3">
    <div class="card-header">Tickets, Tasks and direct timebank usage <span class="text-muted">({{ $objects->count() }})</span></div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead><tr><th>Type / reference</th><th>Title / status</th><th>Parent</th><th class="text-end">Registered work</th><th class="text-end">Billing basis</th><th class="text-end">Entries</th></tr></thead>
            <tbody>
            @forelse($objects as $object)
                <tr>
                    <td>{{ $object['type'] }}<br>{{ $object['reference'] }} @if($object['archived'])<span class="badge text-bg-secondary">Archived</span>@endif</td>
                    <td>{{ $object['title'] }}<br><span class="text-muted">{{ $object['status'] ?? '—' }}</span></td>
                    <td>{{ $object['parent'] ?? '—' }}</td>
                    <td class="text-end">{{ $duration($object['work_minutes']) }}</td>
                    <td class="text-end">{{ $duration($object['basis_minutes']) }}</td>
                    <td class="text-end">{{ $object['entry_count'] }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-muted">No customer work records.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- Source entries expose provenance and order state without editing financial history --}}
@if($pdfMode ?? false)
    <div class="card-header">Time entries ({{ $entries->count() }})</div>
    @forelse($entries as $entry)
        <div class="report-entry">
            <div class="entry-label"><strong>{{ $entry['date'] ?? 'Unknown date' }} · {{ $entry['reference'] }} · {{ $entry['source'] }} #{{ $entry['id'] }} · {{ $entry['employee'] }}</strong></div>
            <div>Registered work: {{ $duration($entry['work_minutes']) }} · Billing basis: {{ $duration($entry['basis_minutes']) }} · Covered: {{ $duration($entry['covered_minutes']) }} · Billable: {{ $duration($entry['processed_billable_minutes']) }} · Unresolved: {{ $duration($entry['unresolved_minutes']) }}</div>
            <div class="text-muted">{{ $entry['provenance'] }} · {{ $entry['billing_status'] }} {{ $entry['ordered'] ? '· On order' : '' }}</div>
            <p class="entry-description">{{ $entry['description'] ?: '—' }}</p>
            @if(filled($entry['invoice_text']))
                <p class="entry-description">Invoice: {{ $entry['invoice_text'] }}</p>
            @endif
        </div>
    @empty
        <p>No time registered in the selected period.</p>
    @endforelse
@else
<div class="card">
    <div class="card-header">Time entries <span class="text-muted">({{ $entries->count() }})</span></div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead><tr><th>Work date / source</th><th>Employee</th><th>Description / provenance</th><th class="text-end">Registered work</th><th class="text-end">Billing basis</th><th class="text-end">Covered</th><th class="text-end">Billable</th><th>Processing</th></tr></thead>
            <tbody>
            @forelse($entries as $entry)
                <tr>
                    <td>{{ $entry['date'] ?? 'Unknown date' }}<br>{{ $entry['reference'] }} · {{ $entry['source'] }} #{{ $entry['id'] }}</td>
                    <td>{{ $entry['employee'] }}</td>
                    <td style="white-space: pre-line; overflow-wrap: anywhere">{{ $entry['description'] ?: '—' }}@if(filled($entry['invoice_text']))<br>Invoice: {{ $entry['invoice_text'] }}@endif<br><span class="text-muted">{{ $entry['provenance'] }}</span></td>
                    <td class="text-end">{{ $duration($entry['work_minutes']) }}</td>
                    <td class="text-end">{{ $duration($entry['basis_minutes']) }}</td>
                    <td class="text-end">{{ $duration($entry['covered_minutes']) }}</td>
                    <td class="text-end">{{ $duration($entry['processed_billable_minutes']) }}</td>
                    <td>{{ $entry['billing_status'] }} @if($entry['ordered'])<br>On order
                    @endif @if($entry['unresolved_minutes'])<br>{{ $duration($entry['unresolved_minutes']) }} unresolved
                    @endif</td>
                </tr>
            @empty
                <tr><td colspan="8" class="text-muted">No time registered in the selected period.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

@endif
