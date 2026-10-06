@php
    $key = $event ? 'block-'.$event->id.'-'.$row['starts_at']->timestamp : 'new-block';
    $timezone = $event?->timezone ?? $plan['timezone'];
@endphp
<!-- Plan activity inputs; no absence reasons or actual hours -->
<input type="hidden" name="request_id" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
@if($event)
    <input type="hidden" name="version" value="{{ data_get($event->metadata, 'version', 1) }}">
    <input type="hidden" name="scope" value="event">
    <input type="hidden" name="occurrence_starts_at" value="{{ $row['starts_at']->toIso8601String() }}">
@endif
<div class="row g-2 mb-3">
    <div class="col-md-8"><label for="{{ $key }}-title" class="form-label">Title</label><input id="{{ $key }}-title" name="title" class="form-control" value="{{ $event?->title }}" maxlength="120" required></div>
    <div class="col-md-4"><label for="{{ $key }}-activity" class="form-label">Activity</label><select id="{{ $key }}-activity" name="activity" class="form-select">@foreach(['education' => 'Education', 'work' => 'Work', 'other' => 'Other'] as $value => $label)<option value="{{ $value }}" @selected(data_get($event?->metadata, 'activity', 'education') === $value)>{{ $label }}</option>@endforeach</select></div>
    <div class="col-md-6"><label for="{{ $key }}-start" class="form-label">Start</label><input id="{{ $key }}-start" name="starts_at" type="datetime-local" class="form-control" value="{{ $row ? $row['starts_at']->copy()->timezone($timezone)->format('Y-m-d\TH:i') : '' }}" required></div>
    <div class="col-md-6"><label for="{{ $key }}-end" class="form-label">End</label><input id="{{ $key }}-end" name="ends_at" type="datetime-local" class="form-control" value="{{ $row ? $row['ends_at']->copy()->timezone($timezone)->format('Y-m-d\TH:i') : '' }}" required></div>
    <div class="col-md-6"><label for="{{ $key }}-zone" class="form-label">Timezone</label><input id="{{ $key }}-zone" name="timezone" class="form-control" value="{{ $timezone }}" required></div>
    @if(!$event)
        <div class="col-md-6"><label for="{{ $key }}-repeat" class="form-label">Repeat</label><select id="{{ $key }}-repeat" name="recurrence_frequency" class="form-select"><option value="none">Does not repeat</option><option value="weekly">Weekly</option></select></div>
        <div class="col-md-6"><label for="{{ $key }}-until" class="form-label">Repeat until</label><input id="{{ $key }}-until" name="recurrence_ends_at" type="date" class="form-control"><div class="form-text">Required for weekly blocks; up to one year.</div></div>
    @else
        <input type="hidden" name="recurrence_frequency" value="none">
    @endif
    <div class="col-12">
        <input type="hidden" name="phone_duty_available" value="0">
        <div class="form-check"><input type="checkbox" id="{{ $key }}-phone" name="phone_duty_available" value="1" class="form-check-input" @checked(data_get($event?->metadata, 'phone_duty_available', false))><label for="{{ $key }}-phone" class="form-check-label">Available for phone duty</label></div>
        <input type="hidden" name="blocks_booking" value="0">
        <div class="form-check"><input type="checkbox" id="{{ $key }}-booking" name="blocks_booking" value="1" class="form-check-input" @checked(!$event || $event->transparency === 'busy')><label for="{{ $key }}-booking" class="form-check-label">Block meeting bookings during this activity</label></div>
    </div>
</div>
