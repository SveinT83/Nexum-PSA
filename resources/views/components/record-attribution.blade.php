@props(['record'])

@php
    $record->loadMissing(['creator', 'updater']);
    $dateTime = app(\App\Modules\UserManagement\Support\UserDateTime::class);
@endphp

{{-- Record attribution uses its own timestamp so technical touches cannot impersonate edits. --}}
<div class="small text-muted d-flex flex-wrap gap-1 mt-1" data-record-attribution>
    <span>Created by: {{ $record->creator?->name ?? 'Unknown' }}@if($record->created_at) &middot; {{ $dateTime->format($record->created_at) }}@endif</span>
    <span aria-hidden="true" class="mx-1">|</span>
    <span>Updated by: {{ $record->updater?->name ?? 'Unknown' }}@if($record->actor_updated_at) &middot; {{ $dateTime->format($record->actor_updated_at) }}@endif</span>
</div>
