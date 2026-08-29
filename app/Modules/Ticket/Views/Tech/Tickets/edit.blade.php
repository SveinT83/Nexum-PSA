@extends('layouts.default_tech')

@section('title', 'Edit ' . $ticket->ticket_key)

@section('pageName')
    <h3>Tickets</h3>
@endsection

<!-- -------------------------------------------------------------------------------------------------- -->
<!-- Page header -->
<!-- Keeps edit navigation close to the ticket key so technicians know exactly which ticket is being changed. -->
<!-- -------------------------------------------------------------------------------------------------- -->
@section('pageHeader')
    <div class="d-flex justify-content-between align-items-start">
        <div>
            <h1 class="mb-1">Edit {{ $ticket->ticket_key }}</h1>
            <p class="text-muted mb-0">{{ $ticket->subject }}</p>
        </div>
        <a href="{{ route('tech.tickets.show', $ticket) }}" class="btn btn-light">Back</a>
    </div>
@endsection

@section('content')
<div class="container-fluid px-0">
    <!-- -------------------------------------------------------------------------------------------------- -->
    <!-- Edit ticket form -->
    <!-- This is the full edit surface for ticket text and lifecycle fields; the show page only summarizes them. -->
    <!-- -------------------------------------------------------------------------------------------------- -->
    <form method="POST" action="{{ route('tech.tickets.update', $ticket) }}">
        @csrf
        @method('PATCH')

        <x-card.default title="Ticket text">
            <div class="mb-3">
                <label for="subject" class="form-label">Subject</label>
                <input id="subject" name="subject" type="text" class="form-control @error('subject') is-invalid @enderror" value="{{ old('subject', $ticket->subject) }}" required>
                @error('subject')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="mb-0">
                <label for="description" class="form-label">Description</label>
                <textarea id="description" name="description" rows="9" class="form-control @error('description') is-invalid @enderror">{{ old('description', $ticket->description) }}</textarea>
                @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
        </x-card.default>

        <x-card.default title="Client and contact">
            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label for="client_id" class="form-label">Client</label>
                    <select id="client_id" name="client_id" class="form-select @error('client_id') is-invalid @enderror">
                        <option value="">Internal work</option>
                        @foreach ($clients as $client)
                            <option value="{{ $client->id }}" @selected(old('client_id', $ticket->client_id) == $client->id)>
                                {{ $client->name }}@if($client->client_number) ({{ $client->client_number }})@endif
                            </option>
                        @endforeach
                    </select>
                    @error('client_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <div class="form-text">Saving after changing client uses the client's default site when no site is selected.</div>
                </div>

                <div class="col-md-6">
                    <label for="contact_id" class="form-label">Contact</label>
                    <select id="contact_id" name="contact_id" class="form-select @error('contact_id') is-invalid @enderror">
                        <option value="">No contact</option>
                        @foreach ($contacts as $contact)
                            <option
                                value="{{ $contact->id }}"
                                data-client-id="{{ $contact->site?->client_id }}"
                                @selected(old('contact_id', $ticket->contact_id) == $contact->id)>
                                {{ $contact->name }}
                                @if($contact->email)
                                    - {{ $contact->email }}
                                @endif
                                @if($contact->site?->client)
                                    ({{ $contact->site->client->name }})
                                @endif
                            </option>
                        @endforeach
                    </select>
                    @error('contact_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <div class="form-text">The selected contact must belong to the selected client.</div>
                </div>
            </div>

            <div class="mb-3">
                <label for="site_id" class="form-label">Site</label>
                <select id="site_id" name="site_id" class="form-select @error('site_id') is-invalid @enderror" @disabled($ticket->contact || $sites->isEmpty())>
                    <option value="">No site</option>
                    @foreach ($sites as $site)
                        <option value="{{ $site->id }}" @selected(old('site_id', $ticket->site_id) == $site->id)>{{ $site->name }}</option>
                    @endforeach
                </select>
                @error('site_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                @if ($ticket->contact)
                    <div class="form-text">Site is set from the selected contact.</div>
                    <input type="hidden" name="site_id" value="{{ $ticket->site_id }}">
                @endif
            </div>

            <div class="mb-0">
                <label for="asset_id" class="form-label">Asset</label>
                <select id="asset_id" name="asset_id" class="form-select @error('asset_id') is-invalid @enderror" @disabled($assetOptions->isEmpty())>
                    <option value="">No asset</option>
                    @foreach ($assetOptions->groupBy('group') as $group => $assets)
                        <optgroup label="{{ $group }}">
                            @foreach ($assets as $asset)
                                <option value="{{ $asset['id'] }}" @selected(old('asset_id', $ticket->asset_id) == $asset['id'])>{{ $asset['label'] }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
                @error('asset_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                @if ($assetOptions->isEmpty())
                    <div class="form-text">No assets found for the current client/contact selection.</div>
                @endif
            </div>
        </x-card.default>

        <x-card.default title="Lifecycle">
            <div class="row g-3">
                <div class="col-md-3">
                    <label for="status_id" class="form-label">Status</label>
                    <select id="status_id" name="status_id" class="form-select @error('status_id') is-invalid @enderror">
                        @foreach ($statuses as $status)
                            <option value="{{ $status->id }}" @selected(old('status_id', $ticket->status_id) == $status->id)>{{ $status->name }}</option>
                        @endforeach
                    </select>
                    @error('status_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-md-3">
                    <label for="queue_id" class="form-label">Queue</label>
                    <select id="queue_id" name="queue_id" class="form-select @error('queue_id') is-invalid @enderror">
                        @foreach ($queues as $queue)
                            <option value="{{ $queue->id }}" @selected(old('queue_id', $ticket->queue_id) == $queue->id)>{{ $queue->name }}</option>
                        @endforeach
                    </select>
                    @error('queue_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-md-3">
                    <label for="priority_id" class="form-label">Priority</label>
                    <select id="priority_id" name="priority_id" class="form-select @error('priority_id') is-invalid @enderror">
                        @foreach ($priorities as $priority)
                            <option value="{{ $priority->id }}" @selected(old('priority_id', $ticket->priority_id) == $priority->id)>P{{ $priority->level }} {{ $priority->name }}</option>
                        @endforeach
                    </select>
                    @error('priority_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-md-3">
                    <label for="owner_id" class="form-label">Owner</label>
                    <select id="owner_id" name="owner_id" class="form-select @error('owner_id') is-invalid @enderror">
                        <option value="">Unassigned</option>
                        @foreach ($technicians as $technician)
                            <option value="{{ $technician->id }}" @selected(old('owner_id', $ticket->owner_id) == $technician->id)>{{ $technician->name }}</option>
                        @endforeach
                    </select>
                    @error('owner_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-md-3">
                    <label for="category_id" class="form-label">Category</label>
                    <select id="category_id" name="category_id" class="form-select @error('category_id') is-invalid @enderror">
                        <option value="">No category</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}" @selected(old('category_id', $ticket->category_id) == $category->id)>{{ $category->name }}</option>
                        @endforeach
                    </select>
                    @error('category_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-12">
                    <label class="form-label">Tags</label>
                    <div class="ticket-tag-input form-control d-flex flex-wrap align-items-center gap-1 p-1" data-ticket-tag-input>
                        @foreach(old('tag_names', $ticket->tags->pluck('name')->all()) as $tagName)
                            @continue(blank($tagName))
                            <span class="badge text-bg-secondary d-inline-flex align-items-center gap-1" data-tag-chip="{{ $tagName }}">
                                {{ $tagName }}
                                <button type="button" class="btn-close btn-close-white" data-remove-tag aria-label="Remove {{ $tagName }}"></button>
                                <input type="hidden" name="tag_names[]" value="{{ $tagName }}">
                            </span>
                        @endforeach
                        <input type="text" class="ticket-tag-input__field border-0 flex-grow-1 px-1" list="ticketTagSuggestions" placeholder="Add tag">
                    </div>
                    <datalist id="ticketTagSuggestions">
                        @foreach($tags as $tag)
                            <option value="{{ $tag->name }}"></option>
                        @endforeach
                    </datalist>
                    @error('tag_names')<div class="text-danger small">{{ $message }}</div>@enderror
                </div>
            </div>
        </x-card.default>

        <!-- ------------------------------------------------- -->
        <!-- Schedule card -->
        <!-- Allows deferring SLA, marking the ticket for future work, and setting recurrence. -->
        <!-- ------------------------------------------------- -->
        <x-card.default title="Schedule">
            @php
                $isScheduled = old('is_scheduled', $ticket->schedule()->exists());
                $schedule = $ticket->schedule;
            @endphp
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" id="is_scheduled" name="is_scheduled" value="1" @checked($isScheduled)>
                <label class="form-check-label" for="is_scheduled">Schedule for later</label>
            </div>

            <div id="schedule_details" class="{{ $isScheduled ? '' : 'd-none' }}">
                <div class="mb-3">
                    <label for="schedule_type" class="form-label">Schedule type</label>
                    <select id="schedule_type" name="schedule_type" class="form-select">
                        <option value="one_time" @selected(old('schedule_type', $schedule?->schedule_type) == 'one_time')>One-time</option>
                        <option value="recurring" @selected(old('schedule_type', $schedule?->schedule_type) == 'recurring')>Recurring</option>
                    </select>
                </div>

                <div class="mb-3">
                    <label for="planned_start_at" class="form-label">Planned start</label>
                    <input type="datetime-local" id="planned_start_at" name="planned_start_at" class="form-control @error('planned_start_at') is-invalid @enderror" value="{{ old('planned_start_at', $schedule?->planned_start_at?->format('Y-m-d\TH:i')) }}">
                    @error('planned_start_at')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="mb-3">
                    <label for="planned_end_at" class="form-label">Planned end</label>
                    <input type="datetime-local" id="planned_end_at" name="planned_end_at" class="form-control @error('planned_end_at') is-invalid @enderror" value="{{ old('planned_end_at', $schedule?->planned_end_at?->format('Y-m-d\TH:i')) }}">
                    @error('planned_end_at')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div id="recurrence_details" class="{{ old('schedule_type', $schedule?->schedule_type) == 'recurring' ? '' : 'd-none' }}">
                    <div class="mb-3">
                        <label for="recurrence_rule" class="form-label">Recurrence rule</label>
                        <select id="recurrence_rule" name="recurrence_rule" class="form-select">
                            <option value="FREQ=DAILY" @selected(old('recurrence_rule', $schedule?->recurrence_rule) == 'FREQ=DAILY')>Daily</option>
                            <option value="FREQ=WEEKLY" @selected(old('recurrence_rule', $schedule?->recurrence_rule) == 'FREQ=WEEKLY' || (!old('recurrence_rule') && !$schedule?->recurrence_rule))>Weekly</option>
                            <option value="FREQ=MONTHLY" @selected(old('recurrence_rule', $schedule?->recurrence_rule) == 'FREQ=MONTHLY')>Monthly</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="recurrence_ends_at" class="form-label">Ends at (optional)</label>
                        <input type="date" id="recurrence_ends_at" name="recurrence_ends_at" class="form-control @error('recurrence_ends_at') is-invalid @enderror" value="{{ old('recurrence_ends_at', $schedule?->recurrence_ends_at?->format('Y-m-d')) }}">
                        @error('recurrence_ends_at')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>

                <div class="mb-3">
                    <label for="sla_mode" class="form-label">SLA Deferral</label>
                    <select id="sla_mode" name="sla_mode" class="form-select">
                        <option value="defer_until_planned_start" @selected(old('sla_mode', $schedule?->sla_mode) == 'defer_until_planned_start')>Defer until planned start</option>
                        <option value="non_sla_until_start" @selected(old('sla_mode', $schedule?->sla_mode) == 'non_sla_until_start')>No SLA until start</option>
                        <option value="normal" @selected(old('sla_mode', $schedule?->sla_mode) == 'normal')>Normal (calculate from creation)</option>
                    </select>
                </div>

                <div class="border-top pt-3 mt-3">
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="link_to_calendar" name="link_to_calendar" value="1" @checked(old('link_to_calendar', $schedule?->calendar_event_id))>
                        <label class="form-check-label" for="link_to_calendar">Add to calendar</label>
                    </div>
                    <div id="calendar_selection" class="{{ old('link_to_calendar', $schedule?->calendar_event_id) ? '' : 'd-none' }}">
                        <select name="calendar_id" class="form-select">
                            @foreach($calendars as $calendar)
                                <option value="{{ $calendar->id }}" @selected(old('calendar_id', $schedule?->calendar_event_id ? \App\Modules\Calendar\Models\CalendarEvent::find($schedule->calendar_event_id)?->calendar_id : null) == $calendar->id || (!old('calendar_id') && !$schedule?->calendar_event_id && $calendar->is_default))>{{ $calendar->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <input type="hidden" name="timezone" value="{{ old('timezone', $schedule?->timezone ?? config('app.timezone')) }}">
            </div>
        </x-card.default>

        @include('ticket::Tech.Tickets.partials.custom-fields-form')

        <div class="d-flex justify-content-between mt-3">
            <a href="{{ route('tech.tickets.show', $ticket) }}" class="btn btn-outline-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">Save ticket</button>
        </div>
    </form>
</div>
@endsection

@section('sidebar')
    <x-nav.work-menu />
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const isScheduledSwitch = document.getElementById('is_scheduled');
            const scheduleDetails = document.getElementById('schedule_details');
            const scheduleTypeSelect = document.getElementById('schedule_type');
            const recurrenceDetails = document.getElementById('recurrence_details');
            const linkToCalendarCheckbox = document.getElementById('link_to_calendar');
            const calendarSelection = document.getElementById('calendar_selection');

            if (isScheduledSwitch && scheduleDetails) {
                isScheduledSwitch.addEventListener('change', function () {
                    scheduleDetails.classList.toggle('d-none', !this.checked);
                });
            }

            if (scheduleTypeSelect && recurrenceDetails) {
                scheduleTypeSelect.addEventListener('change', function () {
                    recurrenceDetails.classList.toggle('d-none', this.value !== 'recurring');
                });
            }

            if (linkToCalendarCheckbox && calendarSelection) {
                linkToCalendarCheckbox.addEventListener('change', function () {
                    calendarSelection.classList.toggle('d-none', !this.checked);
                });
            }
        });
    </script>
@endpush

@section('rightbar')
    <x-card.default title="Current details">
        <dl class="mb-0 small">
            <dt>Status</dt>
            <dd>{{ $ticket->status?->name }}</dd>
            <dt>Queue</dt>
            <dd>{{ $ticket->queue?->name }}</dd>
            <dt>Priority</dt>
            <dd>P{{ $ticket->priority?->level }} {{ $ticket->priority?->name }}</dd>
            <dt>Owner</dt>
            <dd>{{ $ticket->owner?->name ?? 'Unassigned' }}</dd>
            <dt>Site</dt>
            <dd>{{ $ticket->site?->name ?? '-' }}</dd>
            <dt>Asset</dt>
            <dd class="mb-0">{{ $ticket->asset?->name ?? '-' }}</dd>
        </dl>
    </x-card.default>
@endsection

@section('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // Toggle schedule details
            const isScheduledCheckbox = document.getElementById('is_scheduled');
            const scheduleDetails = document.getElementById('schedule_details');

            if (isScheduledCheckbox && scheduleDetails) {
                isScheduledCheckbox.addEventListener('change', function () {
                    if (this.checked) {
                        scheduleDetails.classList.remove('d-none');
                    } else {
                        scheduleDetails.classList.add('d-none');
                    }
                });
            }

            const clientSelect = document.getElementById('client_id');
            const contactSelect = document.getElementById('contact_id');
            const normalizeTag = (value) => value.trim().replace(/\s+/g, ' ');

            const syncContactOptions = () => {
                if (!clientSelect || !contactSelect) {
                    return;
                }

                const selectedClientId = clientSelect.value;
                let selectedContactIsVisible = contactSelect.value === '';

                Array.from(contactSelect.options).forEach((option) => {
                    if (!option.value) {
                        option.hidden = false;
                        return;
                    }

                    const visible = selectedClientId !== '' && option.dataset.clientId === selectedClientId;
                    option.hidden = !visible;

                    if (visible && option.selected) {
                        selectedContactIsVisible = true;
                    }
                });

                if (!selectedContactIsVisible) {
                    contactSelect.value = '';
                }

                contactSelect.disabled = selectedClientId === '';
            };

            clientSelect?.addEventListener('change', syncContactOptions);
            syncContactOptions();

            document.querySelectorAll('[data-ticket-tag-input]').forEach((container) => {
                const input = container.querySelector('.ticket-tag-input__field');

                const existingNames = () => Array.from(container.querySelectorAll('input[name="tag_names[]"]'))
                    .map((hidden) => hidden.value.toLowerCase());

                const addTag = (rawName) => {
                    const name = normalizeTag(rawName);

                    if (!name || existingNames().includes(name.toLowerCase())) {
                        input.value = '';
                        return;
                    }

                    const chip = document.createElement('span');
                    chip.className = 'badge text-bg-secondary d-inline-flex align-items-center gap-1';
                    chip.dataset.tagChip = name;
                    chip.append(document.createTextNode(name));

                    const removeButton = document.createElement('button');
                    removeButton.type = 'button';
                    removeButton.className = 'btn-close btn-close-white';
                    removeButton.dataset.removeTag = '';
                    removeButton.setAttribute('aria-label', `Remove ${name}`);

                    const hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = 'tag_names[]';
                    hidden.value = name;

                    chip.append(removeButton, hidden);
                    container.insertBefore(chip, input);
                    input.value = '';
                };

                input.addEventListener('keydown', (event) => {
                    if (event.key === 'Enter' || event.key === ',') {
                        event.preventDefault();
                        addTag(input.value);
                    }

                    if (event.key === 'Backspace' && input.value === '') {
                        const chips = container.querySelectorAll('[data-tag-chip]');
                        chips[chips.length - 1]?.remove();
                    }
                });

                input.addEventListener('change', () => addTag(input.value));
                input.closest('form')?.addEventListener('submit', () => addTag(input.value));

                container.addEventListener('click', (event) => {
                    if (event.target.matches('[data-remove-tag]')) {
                        event.preventDefault();
                        event.target.closest('[data-tag-chip]')?.remove();
                        return;
                    }

                    input.focus();
                });
            });
        });
    </script>
@endsection
