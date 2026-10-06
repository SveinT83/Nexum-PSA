@extends('layouts.default_tech')

@section('title', 'Workday reminder')

@section('content')
{{-- Personal reminder landing: navigation and snooze both require an explicit employee action. --}}
<div class="card">
    <div class="card-body">
        <h1 class="h4">Review your workday</h1>
        <p>Record and save your actual working time for {{ $reminder['work_date'] }}.</p>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-primary" href="{{ $workdayUrl }}">Open workday</a>
            <form method="POST" action="{{ route('tech.workday-reminders.snooze', $reminder['id']) }}">
                @csrf
                <input type="hidden" name="generation" value="{{ $reminder['generation'] }}">
                <button type="submit" class="btn btn-outline-secondary">Snooze 30 minutes</button>
            </form>
            <a class="btn btn-link" href="{{ route('tech.profile.notifications') }}">Notification preferences</a>
        </div>
    </div>
</div>
@endsection
