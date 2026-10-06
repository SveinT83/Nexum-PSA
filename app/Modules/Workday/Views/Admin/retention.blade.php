@extends('layouts.default_tech')
@section('title', 'Workday retention preview')
@section('pageHeader')
    <div class="col"><h1 class="h4 mb-0">Workday retention preview</h1></div>
    <div class="col-auto"><x-buttons.back :url="route('tech.admin.settings.workday')" class="mb-0">Workday settings</x-buttons.back></div>
@endsection
@section('content')
    {{-- Counts only: this preview never exposes employee details or deletes records. --}}
    <section class="card"><div class="card-body">
        <p>Work and absence are retained for three calendar years. Corrections do not restart the period. Cleanup is <strong>{{ $data['cleanup_enabled'] ? 'enabled' : 'disabled' }}</strong>.</p>
        <p class="small text-muted">Evaluated at {{ $data['evaluated_at'] }}. This preview has made no changes.</p>
        <div class="table-responsive"><table class="table table-sm">
            <thead><tr><th scope="col">Eligible records</th><th scope="col" class="text-end">Count</th></tr></thead>
            <tbody>
                @foreach(['workdays' => 'Expired workdays and their history', 'absences' => 'Expired absences and their Calendar projections', 'reminders' => 'Expired reminders and all generations', 'detached_receipts' => 'Expired settings or detached mutation receipts', 'notification_copies' => 'Expired or orphaned reminder copies', 'diagnostic_copies' => 'Workday diagnostic copies'] as $key => $label)
                    <tr><th scope="row">{{ $label }}</th><td class="text-end">{{ $data['eligible'][$key] }}</td></tr>
                @endforeach
            </tbody>
        </table></div>
        <p class="small text-muted">Counts can overlap: an expired reminder can also have an expired notification copy.</p>
        @if($data['untracked_notification_copies'])
            <p class="alert alert-warning">{{ $data['untracked_notification_copies'] }} reminder copies need provenance review before restored access can reopen.</p>
        @endif
        <p>Original Tasks, Tickets, independent Calendar events and current work plans are preserved. Deletion runs through the separately approved server retention policy.</p>
        <form method="POST" action="{{ route('tech.admin.settings.workday.retention-preview') }}">@csrf<button class="btn btn-outline-primary" type="submit">Refresh preview</button></form>
    </div></section>
@endsection
