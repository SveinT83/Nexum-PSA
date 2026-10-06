@extends('layouts.default_tech')
@section('title', 'Confirm workday')
@section('pageHeader')
    <div class="col"><h1 class="h4 mb-0">Review {{ $day['work_date'] }}</h1></div>
@endsection
@section('content')
    {{-- Exact saved revision shown immediately before employee confirmation --}}
    <div class="card"><div class="card-body">
        <p>Review version {{ $day['version'] }}. This preview expires at {{ \Carbon\CarbonImmutable::parse($preview['expires_at'])->setTimezone($day['timezone'])->format('Y-m-d H:i P') }}.</p>
        @include('workday::Tech.snapshot', ['snapshot' => $day['current']['snapshot'], 'timezone' => $day['timezone']])
        @if($day['confirmed'])<p class="alert alert-info">Confirming this correction replaces the previous confirmed version. The earlier version remains in history.</p>@endif
        @can('workday.confirm_own')
            <form method="POST" action="{{ route('tech.workdays.confirm', $day['id']) }}">
                @csrf
                <input type="hidden" name="request_key" value="{{ \Illuminate\Support\Str::uuid() }}">
                <input type="hidden" name="version" value="{{ $day['version'] }}">
                <input type="hidden" name="preview_token" value="{{ $preview['token'] }}">
                @if($day['absence_warnings'])
                    <div class="alert alert-warning">Actual work overlaps registered absence:
                        <ul>@foreach($day['absence_warnings'] as $warning)<li>{{ $warning['overlap_minutes'] }} minutes. {{ $warning['message'] }}</li>@endforeach</ul>
                        @can('workday.absence_view_own')<a href="{{ route('tech.absences.index') }}">Review my absences</a>@endcan
                        <div class="form-check mt-2"><input class="form-check-input" id="accept_absence_conflicts" name="accept_absence_conflicts" type="checkbox" value="1" required>
                            <label for="accept_absence_conflicts" class="form-check-label">I reviewed the overlap and confirm that the saved work intervals are still correct.</label></div>
                    </div>
                @endif
                <div class="form-check mb-3"><input class="form-check-input" id="confirmed" name="confirmed" type="checkbox" value="1" required>
                    <label class="form-check-label" for="confirmed">I confirm that these intervals and breaks describe my actual work.</label></div>
                <button class="btn btn-primary" type="submit">Confirm my workday</button>
                <x-buttons.back :url="route('tech.workdays.show', $day['id'])" class="mb-0">Back to workday</x-buttons.back>
            </form>
        @endcan
    </div></div>
@endsection
