@props(['record', 'showState' => true])

@php
    $integration = $record->sourceIntegration;
    $managed = $record->isIntegrationManaged();
    $source = (string) ($record->source ?? 'nexum');
    $sourceLabel = $integration?->name ?: match ($source) {
        'cloudfactory' => 'Cloud Factory',
        'nexum', '' => 'Nexum',
        default => ucfirst($source),
    };
    $integrationUrl = $managed && $integration?->type === 'cloudfactory'
        && \Illuminate\Support\Facades\Route::has('tech.admin.system.integrations.cloudfactory.index')
            ? route('tech.admin.system.integrations.cloudfactory.index')
            : null;
@endphp

<span class="d-inline-flex flex-wrap align-items-center gap-1">
    @if($integrationUrl)
        <a
            href="{{ $integrationUrl }}"
            class="badge text-bg-primary text-decoration-none"
            onclick="event.stopPropagation()"
            title="Open {{ $sourceLabel }} Integration">
            {{ $sourceLabel }}
        </a>
    @else
        <span class="badge text-bg-{{ $source === 'nexum' || $source === '' ? 'light' : 'secondary' }} border">
            {{ $sourceLabel }}
        </span>
    @endif

    @if($showState)
        @if($managed)
            <span class="badge text-bg-light border">Managed</span>
        @elseif($record->managed_externally && $source !== 'nexum')
            <span class="badge text-bg-light border">Released to Nexum</span>
        @endif
    @endif
</span>
