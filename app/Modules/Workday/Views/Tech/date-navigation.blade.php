{{-- Navigable month calendar and active day; links never create work records. --}}
<section class="mb-3" aria-label="Choose work date">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
        <div class="d-flex align-items-center gap-2">
            <a class="btn btn-sm btn-outline-secondary" aria-label="Previous month" href="{{ route('tech.workdays.index', ['work_date' => $day['work_date'], 'month' => $calendar['previous']]) }}"><i class="bi bi-chevron-left" aria-hidden="true"></i></a>
            <h2 class="h6 mb-0">{{ $calendar['label'] }}</h2>
            <a class="btn btn-sm btn-outline-secondary" aria-label="Next month" href="{{ route('tech.workdays.index', ['work_date' => $day['work_date'], 'month' => $calendar['next']]) }}"><i class="bi bi-chevron-right" aria-hidden="true"></i></a>
            <a class="btn btn-sm btn-outline-secondary" href="{{ route('tech.workdays.index', ['work_date' => $calendar['today']]) }}">Today</a>
        </div>
        @if(auth()->user()->roles()->exists())
            <a class="btn btn-sm btn-outline-secondary" href="{{ route('tech.profile.work-plan') }}">Work plan</a>
        @endif
    </div>
    <table class="table table-sm table-bordered text-center align-middle mb-2" style="table-layout: fixed;">
        <caption class="visually-hidden">{{ $calendar['label'] }} — choose a work date</caption>
        <thead><tr>@foreach(['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $label)<th scope="col" class="small">{{ $label }}</th>@endforeach</tr></thead>
        <tbody>
            @foreach($calendar['weeks'] as $week)
                <tr>@foreach($week as $cell)
                    <td class="p-0">
                        <a href="{{ route('tech.workdays.index', ['work_date' => $cell['date']]) }}"
                           class="d-block text-decoration-none py-1 {{ $cell['selected'] ? 'bg-primary text-white fw-bold' : ($cell['in_month'] ? 'text-body' : 'text-muted') }} {{ $cell['today'] ? 'border border-2 border-primary' : '' }}"
                           aria-label="{{ $cell['label'] }}{{ $cell['today'] ? ', today' : '' }}{{ $cell['selected'] ? ', selected' : '' }}"
                           @if($cell['selected']) aria-current="date" @endif>{{ $cell['number'] }}</a>
                    </td>
                @endforeach</tr>
            @endforeach
        </tbody>
    </table>
    <div class="d-flex align-items-center justify-content-between gap-2">
        <div class="d-flex align-items-center gap-2">
            <a class="btn btn-sm btn-outline-secondary" aria-label="Previous day" href="{{ route('tech.workdays.index', ['work_date' => $calendar['previous_day']]) }}"><i class="bi bi-chevron-left" aria-hidden="true"></i></a>
            <h2 class="h6 mb-0">{{ $calendar['selected_label'] }}</h2>
            <a class="btn btn-sm btn-outline-secondary" aria-label="Next day" href="{{ route('tech.workdays.index', ['work_date' => $calendar['next_day']]) }}"><i class="bi bi-chevron-right" aria-hidden="true"></i></a>
        </div>
        <span class="small text-muted">{{ $day['timezone'] }}</span>
    </div>
</section>
