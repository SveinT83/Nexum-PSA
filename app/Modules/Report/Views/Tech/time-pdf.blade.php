<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Ticket and Task time</title>
<style>
@page { margin: 20px; }
body { font-family: "DejaVu Sans", sans-serif; font-size: 8px; color: #222; }
h1 { font-size: 16px; } table { border-collapse: collapse; width: 100%; margin: 10px 0; table-layout: fixed; }
th, td { border: 1px solid #ccc; padding: 4px; text-align: left; vertical-align: top; overflow-wrap: break-word; }
thead { display: table-header-group; } tr { page-break-inside: avoid; } th { background: #eee; }
</style></head><body>
<h1>Ticket and Task time — Internal report</h1>
<p>Work dates: {{ $filters['date_from'] ?? 'All history' }} — {{ $filters['date_to'] ?? 'No end date' }}.
Generated {{ $generated_at }}. {{ $totals['minutes'] }} registered minutes / {{ number_format($totals['minutes']/60,2) }} hours; {{ $totals['entry_count'] }} entries.</p>
<p>Filters: {{ json_encode($filters, JSON_UNESCAPED_UNICODE) }}</p>
<p>Registered work only, including labelled estimate/completion entries. Task billing projections excluded. Not payroll or an invoice.</p>
<table><thead><tr><th>Group ({{ $filters['group_by'] }})</th><th>Minutes</th><th>Entries</th></tr></thead><tbody>
@forelse($groups as $group)<tr><td>{{ $group['label'] }}</td><td>{{ $group['minutes'] }}</td><td>{{ $group['entry_count'] }}</td></tr>@empty<tr><td colspan="3">No matching groups.</td></tr>@endforelse
</tbody></table>
<table><thead><tr><th>Work date</th><th>Employee</th><th>Client / Internal</th><th>Record / title</th><th>Minutes</th><th>Basis / status</th><th>Note</th></tr></thead><tbody>
@forelse($entries as $entry)<tr><td>{{ $entry['date'] }}</td><td>{{ $entry['employee'] }}</td><td>{{ $entry['client'] }}</td>
<td>{{ $entry['reference'] }} — {{ $entry['title'] }}@if($entry['archived']) (Archived)@endif @if($entry['parent_ticket_reference']) / {{ $entry['parent_ticket_reference'] }}@endif</td>
<td>{{ $entry['minutes'] }}</td><td>{{ $entry['provenance'] }} / {{ $entry['status'] }} / Billable {{ $entry['billable']?'Yes':'No' }}</td><td>{{ $entry['note'] }}</td></tr>
@empty<tr><td colspan="7">No matching entries.</td></tr>@endforelse
</tbody></table></body></html>
