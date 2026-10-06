{{-- Saved work is displayed separately from the pending one-hour selection. --}}
@php
    $canEdit = $timeline['editable'] && auth()->user()->hasPermissionTo('workday.manage_own', 'web');
    $snapshot = $day['current']['snapshot'] ?? ['description' => 'Work - unspecified', 'intervals' => [], 'breaks' => []];
    foreach (['intervals', 'breaks'] as $kind) {
        foreach ($snapshot[$kind] as &$range) {
            if ($kind === 'breaks') $range['included'] = $range['included'] ? '1' : '0';
            foreach (['start', 'end'] as $edge) {
                $range[$edge] = \Carbon\CarbonImmutable::parse($range[$edge])->setTimezone($day['timezone'])->format('Y-m-d\TH:iP');
            }
        }
        unset($range);
    }
    $entries = old('intervals', $snapshot['intervals']);
    $selection = $canEdit && $timeline['selection'] ? $timeline['selection'] + ['description' => ''] : null;
    $editingIndex = null;
    if (old('intervals') !== null && count($entries)) {
        $editingIndex = min(max((int) old('editor_index', count($entries) - 1), 0), count($entries) - 1);
        $selection = $entries[$editingIndex];
    }
    $editor = ['entries' => $entries, 'selection' => $selection, 'editingIndex' => $editingIndex,
        'breaks' => old('breaks', $snapshot['breaks']), 'clockChanges' => $clockChanges,
        'reopenEditor' => old('editor_index') !== null,
        'offsetPeriods' => $timeline['offset_periods'], 'reserved' => $timeline['reserved_ranges'],
        'origin' => $timeline['origin'], 'visibleStart' => $timeline['visible_start'],
        'visibleEnd' => $timeline['visible_end'], 'fullMinutes' => $timeline['full_minutes']];
@endphp
@include('workday::Tech.timeline-script')
<section class="mb-3" aria-label="Hourly workday" x-data="workdayTimelineEditor(@js($editor))" @beforeunload.window="beforeLeave($event)">
    <div class="d-flex align-items-center justify-content-between gap-2 border-bottom pb-2 mb-2">
        <h2 class="h6 mb-0">My working day</h2>
        <div class="d-flex flex-wrap gap-2">
            @if($canEdit)
                <button type="button" class="btn btn-sm btn-primary" aria-haspopup="dialog" aria-controls="workday-entry-modal" @click="openEditor($event.currentTarget)">Time entry</button>
            @endif
            <button type="button" class="btn btn-sm btn-outline-secondary" @click="fullDay = !fullDay" :aria-pressed="fullDay" x-text="fullDay ? 'Working hours' : 'Show full day'">Show full day</button>
        </div>
    </div>
    <div class="row g-3">
        {{-- A continuous minute-scaled calendar: each recorded interval is one proportional block. --}}
        <div class="col-12">
            <div class="border rounded overflow-auto" style="max-height: 44rem;">
                <div class="position-relative overflow-hidden" :style="{height: calendarHeight() + 'px'}"
                     style="height: {{ ($timeline['visible_end'] - $timeline['visible_start']) * 1.6 }}px;">
                    @foreach($timeline['rows'] as $row)
                        <div class="position-absolute w-100 border-top" style="height: 96px;"
                             @if($row['outside']) x-show="fullDay" x-cloak @endif
                             :style="{top: position({{ $row['top'] }}) + 'px'}">
                            <div class="text-muted small border-end ps-2 h-100" style="width: 5.5rem;">
                                <span class="fw-semibold text-body">{{ $row['label'] }}</span>
                                @if($row['label'] === '00:00' || $row['top'] === $timeline['visible_start'])
                                    <span class="d-block" style="font-size: .7rem;">{{ $row['date'] }}</span>
                                @endif
                                @if(count($clockChanges))<span class="d-block" style="font-size: .7rem;">UTC{{ $row['offset'] }}</span>@endif
                            </div>
                            <div class="position-absolute border-top opacity-25" style="left: 5.5rem; right: 0; top: 48px;"></div>
                        </div>
                        @if($row['reserved'])
                            <div class="position-absolute bg-body-secondary small text-muted px-2"
                                @if($row['outside']) x-show="fullDay" x-cloak @endif
                                :style="{top: position({{ $row['top'] }}) + 'px'}" style="left: 5.5rem; right: 0; pointer-events: none;">
                                Registered on another work date
                            </div>
                        @endif
                        @foreach($row['choices'] as $choice)
                            @if($canEdit)
                                <button type="button" class="position-absolute btn btn-sm rounded-0 border-0 text-start text-muted overflow-hidden py-0"
                                    @if($row['outside']) x-show="fullDay" x-cloak @endif
                                    style="left: 5.5rem; right: 0; height: {{ $choice['minutes'] * 1.6 }}px;"
                                    :style="{top: position({{ $choice['top'] }}) + 'px'}"
                                    @click="choose(@js(['start' => $choice['start'], 'end' => $choice['end']]), null, $event.currentTarget)" aria-haspopup="dialog" aria-controls="workday-entry-modal" :disabled="entries.length >= 24"
                                    aria-label="Register {{ substr($choice['start'], 0, 16) }} to {{ substr($choice['end'], 0, 16) }}">
                                    @if($choice['minutes'] >= 20)<span class="small"><i class="bi bi-plus" aria-hidden="true"></i> {{ $row['planned'] ? 'Register time' : 'Outside plan' }}</span>@endif
                                </button>
                            @endif
                        @endforeach
                    @endforeach
                    {{-- Saved blocks retain their exact duration, including sub-hour and multi-hour work. --}}
                    @foreach($timeline['blocks'] as $block)
                        <button type="button" data-work-interval="{{ $block['index'] }}" data-duration-minutes="{{ $block['minutes'] }}"
                            class="position-absolute btn btn-sm text-start border border-primary border-start border-3 bg-primary-subtle rounded-1 overflow-hidden px-2 py-0 d-flex flex-column align-items-start justify-content-start"
                            style="left: 5.7rem; right: .25rem; height: {{ $block['minutes'] * 1.6 }}px; z-index: 2;"
                            :style="{top: position({{ $block['top'] }}) + 'px', opacity: removed.includes({{ $block['index'] }}) ? .35 : 1}"
                            aria-label="{{ $canEdit ? 'Edit' : 'Recorded' }} {{ substr($block['start'], 11, 5) }} to {{ substr($block['end'], 11, 5) }}, {{ $block['minutes'] }} minutes, {{ $block['description'] }}"
                            title="{{ substr($block['start'], 11, 5) }}–{{ substr($block['end'], 11, 5) }} · {{ $block['minutes'] }} min · {{ $block['description'] }}"
                            @if($canEdit) @click="choose(null, {{ $block['index'] }}, $event.currentTarget)" aria-haspopup="dialog" aria-controls="workday-entry-modal" :disabled="removed.includes({{ $block['index'] }})" :aria-pressed="editingIndex === {{ $block['index'] }}" @else disabled @endif>
                            <span class="d-block text-truncate fw-semibold">{{ substr($block['start'], 11, 5) }}–{{ substr($block['end'], 11, 5) }} · {{ $block['minutes'] }} min</span>
                            @if($block['minutes'] >= 25)<span class="d-block text-truncate">{{ $block['description'] ?: ($snapshot['description'] ?? 'Work') }}</span>@endif
                        </button>
                    @endforeach
                    {{-- The pending selection is an outline; it is never another recorded block. --}}
                    <div x-show="selectionGeometry()" x-cloak :style="selectionStyle()"
                        class="position-absolute border border-2 border-primary bg-primary bg-opacity-10 rounded-1"
                        style="left: 5.6rem; right: .125rem; z-index: 3; pointer-events: none; border-style: dashed !important;"></div>
                </div>
            </div>
            @if($timeline['blocks'])
                <details class="mt-2">
                    <summary class="small">Registered intervals ({{ count($timeline['blocks']) }})</summary>
                    <div class="list-group list-group-flush">
                        @foreach($timeline['blocks'] as $block)
                            <button type="button" class="list-group-item list-group-item-action small py-1"
                                @if($canEdit) @click="choose(null, {{ $block['index'] }}, $event.currentTarget)" aria-haspopup="dialog" aria-controls="workday-entry-modal" :disabled="removed.includes({{ $block['index'] }})" @else disabled @endif>
                                {{ substr($block['start'], 11, 5) }}–{{ substr($block['end'], 11, 5) }} · {{ $block['minutes'] }} min · {{ $block['description'] ?: $snapshot['description'] }}
                            </button>
                        @endforeach
                    </div>
                </details>
            @endif
        </div>
    </div>
    @if($canEdit)
        @include('workday::Tech.interval-modal')
    @endif
</section>
