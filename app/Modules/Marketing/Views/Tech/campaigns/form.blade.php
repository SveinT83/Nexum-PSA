@extends('layouts.default_tech')

@section('title', 'New Marketing Campaign')

@section('pageHeader')
    <div class="d-flex align-items-center justify-content-between gap-3">
        <h1 class="h4 mb-0">New Marketing Campaign</h1>
        <a href="{{ route('tech.marketing.campaigns.index') }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left" aria-hidden="true"></i>
            Campaigns
        </a>
    </div>
@endsection

@section('content')
    @php
        $scheduleFrequency = old('schedule_frequency', $campaign->scheduleFrequency());
        $firstSendDate = old('first_send_date', $campaign->starts_at?->format('Y-m-d'));
        $sendTime = old('send_time', $campaign->scheduleTime());
        $sendWeekday = (int) old('send_weekday', $campaign->scheduleWeekday());
        $monthDay = old('month_day', $campaign->scheduleMonthDay());
        $customIntervalValue = old('custom_interval_value', $campaign->sequence_interval_value ?: 1);
        $customIntervalUnit = old('custom_interval_unit', $campaign->sequence_interval_unit ?: 'days');
    @endphp

    <!-- ------------------------------------------------- -->
    <!-- Marketing campaign form -->
    <!-- ------------------------------------------------- -->
    <form method="POST" action="{{ route('tech.marketing.campaigns.store') }}" class="d-grid gap-3" data-campaign-schedule-form>
        @csrf

        <div class="card">
            <div class="card-header">
                <span class="fw-semibold">Campaign</span>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-lg-8">
                        <label for="name" class="form-label">Name</label>
                        <input type="text" id="name" name="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name') }}" required maxlength="255" autofocus>
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-lg-4">
                        <label for="marketing_list_id" class="form-label">List</label>
                        <select id="marketing_list_id" name="marketing_list_id" class="form-select @error('marketing_list_id') is-invalid @enderror" required>
                            <option value="">Select list</option>
                            @foreach($lists as $list)
                                <option value="{{ $list->id }}" @selected((int) old('marketing_list_id') === $list->id)>{{ $list->name }} ({{ $list->members_count }})</option>
                            @endforeach
                        </select>
                        @error('marketing_list_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-lg-8">
                        <label for="description" class="form-label">Description</label>
                        <textarea id="description" name="description" rows="3" class="form-control @error('description') is-invalid @enderror" maxlength="2000">{{ old('description') }}</textarea>
                        @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-lg-3">
                        <label for="schedule_frequency" class="form-label">Send Rhythm</label>
                        <select id="schedule_frequency" name="schedule_frequency" class="form-select @error('schedule_frequency') is-invalid @enderror" data-schedule-frequency>
                            @foreach($scheduleFrequencies as $value => $label)
                                <option value="{{ $value }}" @selected($scheduleFrequency === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <div class="form-text">Controls the spacing between campaign emails.</div>
                        @error('schedule_frequency')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-lg-3">
                        <label for="first_send_date" class="form-label">First Send Date</label>
                        <input type="date" id="first_send_date" name="first_send_date" class="form-control @error('first_send_date') is-invalid @enderror" value="{{ $firstSendDate }}">
                        <div class="form-text">Blank means when the campaign is approved.</div>
                        @error('first_send_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-lg-2">
                        <label for="send_time" class="form-label">Send Time</label>
                        <input type="time" id="send_time" name="send_time" class="form-control @error('send_time') is-invalid @enderror" value="{{ $sendTime }}">
                        @error('send_time')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-lg-2" data-schedule-weekly>
                        <label for="send_weekday" class="form-label">Weekday</label>
                        <select id="send_weekday" name="send_weekday" class="form-select @error('send_weekday') is-invalid @enderror">
                            @foreach($weekdays as $value => $label)
                                <option value="{{ $value }}" @selected($sendWeekday === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('send_weekday')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-lg-2" data-schedule-monthly>
                        <label for="month_day" class="form-label">Month Day</label>
                        <input type="number" min="1" max="31" id="month_day" name="month_day" class="form-control @error('month_day') is-invalid @enderror" value="{{ $monthDay }}">
                        @error('month_day')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-lg-2" data-schedule-custom>
                        <label for="custom_interval_value" class="form-label">Every</label>
                        <input type="number" min="1" max="999" id="custom_interval_value" name="custom_interval_value" class="form-control @error('custom_interval_value') is-invalid @enderror" value="{{ $customIntervalValue }}">
                        @error('custom_interval_value')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-lg-2" data-schedule-custom>
                        <label for="custom_interval_unit" class="form-label">Interval Unit</label>
                        <select id="custom_interval_unit" name="custom_interval_unit" class="form-select @error('custom_interval_unit') is-invalid @enderror">
                            @foreach($sequenceIntervalUnits as $value => $label)
                                <option value="{{ $value }}" @selected($customIntervalUnit === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('custom_interval_unit')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-lg-6">
                        <label for="new_recipient_policy" class="form-label">New Contacts Added Later</label>
                        <select id="new_recipient_policy" name="new_recipient_policy" class="form-select @error('new_recipient_policy') is-invalid @enderror">
                            @foreach($newRecipientPolicies as $value => $label)
                                <option value="{{ $value }}" @selected(old('new_recipient_policy', 'start_at_first_email') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <div class="form-text">Use “join current schedule” for newsletters so new contacts do not get old emails.</div>
                        @error('new_recipient_policy')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <span class="fw-semibold">Sending Preferences</span>
            </div>
            <div class="card-body">
                <div class="row g-3 align-items-start">
                    <div class="col-lg-6">
                        <label for="email_account_id" class="form-label">Sender Account</label>
                        <select id="email_account_id" name="email_account_id" class="form-select @error('email_account_id') is-invalid @enderror">
                            <option value="">Marketing default</option>
                            @foreach($accounts as $account)
                                <option value="{{ $account->id }}" @selected((int) old('email_account_id') === $account->id)>{{ $account->address }}</option>
                            @endforeach
                        </select>
                        @error('email_account_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-lg-3">
                        <label for="batch_size" class="form-label">Recipients Per Batch</label>
                        <input type="number" min="1" max="1000" id="batch_size" name="batch_size" class="form-control @error('batch_size') is-invalid @enderror" value="{{ old('batch_size', $settings['default_batch_size']) }}">
                        @error('batch_size')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-lg-3">
                        <label for="send_interval_minutes" class="form-label">Minutes Between Batches</label>
                        <input type="number" min="1" max="1440" id="send_interval_minutes" name="send_interval_minutes" class="form-control @error('send_interval_minutes') is-invalid @enderror" value="{{ old('send_interval_minutes', $settings['default_send_interval_minutes']) }}">
                        <div class="form-text">This throttles recipients inside one email. It does not schedule the next email.</div>
                        @error('send_interval_minutes')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-lg-3">
                        <input type="hidden" name="track_opens" value="0">
                        <div class="form-check">
                            <input type="checkbox" id="track_opens" name="track_opens" value="1" class="form-check-input" @checked(old('track_opens', $campaign->track_opens))>
                            <label for="track_opens" class="form-check-label">Track opens</label>
                        </div>
                    </div>
                    <div class="col-lg-3">
                        <input type="hidden" name="track_clicks" value="0">
                        <div class="form-check">
                            <input type="checkbox" id="track_clicks" name="track_clicks" value="1" class="form-check-input" @checked(old('track_clicks', $campaign->track_clicks))>
                            <label for="track_clicks" class="form-check-label">Track clicks</label>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex justify-content-end gap-2">
            <a href="{{ route('tech.marketing.campaigns.index') }}" class="btn btn-sm btn-outline-secondary">Cancel</a>
            <button type="submit" class="btn btn-sm btn-primary">
                <i class="bi bi-check2" aria-hidden="true"></i>
                Create Draft
            </button>
        </div>
    </form>
@endsection

@section('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('[data-campaign-schedule-form]').forEach(function (form) {
                const frequency = form.querySelector('[data-schedule-frequency]');
                const weeklyFields = form.querySelectorAll('[data-schedule-weekly]');
                const monthlyFields = form.querySelectorAll('[data-schedule-monthly]');
                const customFields = form.querySelectorAll('[data-schedule-custom]');

                const syncScheduleFields = function () {
                    const value = frequency ? frequency.value : 'daily';

                    weeklyFields.forEach(function (field) {
                        field.classList.toggle('d-none', value !== 'weekly');
                    });
                    monthlyFields.forEach(function (field) {
                        field.classList.toggle('d-none', value !== 'monthly');
                    });
                    customFields.forEach(function (field) {
                        field.classList.toggle('d-none', value !== 'custom');
                    });
                };

                frequency?.addEventListener('change', syncScheduleFields);
                syncScheduleFields();
            });
        });
    </script>
@endsection

@section('sidebar')
    <x-nav.sales-menu />
@endsection

@section('rightbar')
    <x-card.default title="Approval">
        <div class="small text-muted">
            Campaigns are saved as drafts. A technician with campaign approval permission must approve before the queue sends due recipients.
        </div>
    </x-card.default>
@endsection
