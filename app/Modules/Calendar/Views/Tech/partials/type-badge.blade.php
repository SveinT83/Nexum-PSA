@php
    $calendarType = $event['calendar_type'];
    $typeBadges = [
        'shared' => 'SHR',
        'team' => 'TM',
        'company' => 'GLB',
        'absence' => 'ABS',
        'shift' => 'SHF',
        'resource' => 'RES',
        'system' => 'SYS',
        'external' => 'EXT',
        'other' => 'CAL',
    ];
    $typeLabel = $calendarType === 'company' ? 'Global' : $event['calendar_type_label'];
@endphp

@if($calendarType !== 'personal')
    <!-- Calendar type stays visible independently from owner and color -->
    <span class="calendar-type-badge badge rounded-pill border flex-shrink-0"
        aria-label="Calendar type: {{ $typeLabel }}" title="{{ $typeLabel }} calendar"
        data-calendar-type="{{ $calendarType }}">
        <span aria-hidden="true">{{ $typeBadges[$calendarType] ?? 'CAL' }}</span>
    </span>
@endif
