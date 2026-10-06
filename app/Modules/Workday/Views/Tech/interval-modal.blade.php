{{-- One accessible Bootstrap dialog owns entry and editing; opening it never persists time. --}}
<div class="modal fade" id="workday-entry-modal" tabindex="-1" aria-labelledby="workday-entry-title"
     aria-hidden="true" x-ref="intervalModal">
    <div class="modal-dialog modal-lg modal-dialog-scrollable modal-fullscreen-sm-down">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title fs-5" id="workday-entry-title"
                    x-text="!selection ? 'Day details' : (editingIndex === null ? 'Register time' : 'Edit time')">Time entry</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close time entry"></button>
            </div>
            <div class="modal-body">
                <form method="POST" action="{{ route('tech.workdays.save', $day['work_date']) }}" @submit="submitting = true">
                    @if($errors->any())
                        <div class="alert alert-danger" role="alert" tabindex="-1" x-ref="editorErrors">
                            <p class="fw-semibold mb-1">Check your time entry</p>
                            <ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                        </div>
                    @endif
                    @csrf @method('PUT')
                    <input type="hidden" name="version" value="{{ $day['version'] }}">
                    <input type="hidden" name="request_key" value="{{ old('request_key', (string) \Illuminate\Support\Str::uuid()) }}">
                    <input type="hidden" name="selected_work_date" value="{{ $day['work_date'] }}">
                    <input type="hidden" name="editor_index" :value="payloadIndex()">
                    <input type="hidden" name="timezone" value="{{ $day['timezone'] }}">
                    <template x-for="(interval, i) in payloadIntervals()" :key="i">
                        <div>
                            <input type="hidden" :name="'intervals['+i+'][start]'" :value="interval.start">
                            <input type="hidden" :name="'intervals['+i+'][end]'" :value="interval.end">
                            <input type="hidden" :name="'intervals['+i+'][description]'" :value="interval.description">
                        </div>
                    </template>
                    <div class="border rounded p-3 mb-3">
                        <h3 class="h6">Actual time</h3>
                        @if($timeline['plan_state'] === 'known')
                            <p class="small text-muted" x-show="selection &amp;&amp; editingIndex === null">Adjust Start and End to the exact minutes you worked.</p>
                        @else
                            <p class="small text-muted">{{ $timeline['plan_state'] === 'unknown' ? 'No saved work plan is available for this date.' : 'No working time is planned for this date after availability and absence.' }} Choose a time in the calendar to register actual work.</p>
                        @endif
                        <p class="small text-muted" x-show="editingIndex !== null">Editing saved time. Changes update this block.</p>
                        <template x-if="selection">
                            <div @input="selectionDirty = true">
                                <div class="fw-semibold mb-2" x-text="label(selection.start) + '–' + label(selection.end)"></div>
                                <div class="mb-2">
                                    <label for="entry-start" class="form-label">Start</label>
                                    <input id="entry-start" class="form-control" type="datetime-local" step="60" :value="local(selection.start)" @input="selection.start = $event.target.value" required>
                                </div>
                                <div class="mb-2">
                                    <label for="entry-end" class="form-label">End</label>
                                    <input id="entry-end" class="form-control" type="datetime-local" step="60" :value="local(selection.end)" @input="selection.end = $event.target.value" required>
                                </div>
                                @foreach(['start', 'end'] as $edge)
                                    <div x-show="offsets(local(selection.{{ $edge }})).length > 1" x-cloak class="mb-2">
                                        <label class="form-label small" for="entry-{{ $edge }}-offset">{{ ucfirst($edge) }} clock-change occurrence</label>
                                        <select id="entry-{{ $edge }}-offset" class="form-select" :value="offset(selection.{{ $edge }})"
                                            @change="selection.{{ $edge }} = local(selection.{{ $edge }}) + $event.target.value; selectionDirty = true"
                                            :required="offsets(local(selection.{{ $edge }})).length > 1">
                                            <option value="">Choose occurrence</option>
                                            <template x-for="choice in offsets(local(selection.{{ $edge }}))" :key="choice">
                                                <option :value="choice" :selected="offset(selection.{{ $edge }}) === choice" x-text="'UTC' + choice"></option>
                                            </template>
                                        </select>
                                    </div>
                                @endforeach
                                <div class="mb-3"><label class="form-label" for="entry-description">Activity</label>
                                    <textarea id="entry-description" class="form-control" rows="3" maxlength="1000" x-model="selection.description" placeholder="What did you work on?"></textarea></div>
                                <p class="small text-danger" role="alert" x-show="selectionError()" x-text="selectionError()"></p>
                                <div class="d-flex flex-wrap gap-2">
                                    <button class="btn btn-primary" :disabled="!!selectionError()" type="submit" @click="includeSelection = true"><i class="bi bi-save" aria-hidden="true"></i> Save interval</button>
                                    <button class="btn btn-sm btn-outline-danger" type="button" @click="removeSelection()" x-text="editingIndex === null ? 'Clear selection' : 'Remove interval'"></button>
                                </div>
                            </div>
                        </template>
                        <p x-show="!selection" class="small text-muted mb-0">Choose a free time or a saved block in the calendar.</p>
                        <p class="small text-muted mt-2 mb-0">Times use {{ $day['timezone'] }}. Saving records your time immediately. No separate confirmation is needed.</p>
                    </div>
                    <details class="border rounded p-3" @input="dirty = true" @change="dirty = true" :open="!selection || reopenEditor">
                        <summary>Day description and breaks</summary>
                        <div class="mt-2"><label class="form-label" for="description">Work description</label>
                            <textarea class="form-control mb-2" id="description" name="description" maxlength="2000" rows="2" required>{{ old('description', $snapshot['description']) }}</textarea></div>
                        <p class="small text-muted">Work intervals include their breaks. Choose whether each break counts as actual time.</p>
                        <template x-for="(pause, i) in breaks" :key="i">
                            <fieldset class="border rounded p-2 mb-2"><legend class="float-none w-auto fs-6 px-1" x-text="'Break ' + (i + 1)"></legend>
                                <div class="mb-2">@include('workday::Tech.time-picker', ['kind' => 'breaks', 'edge' => 'start', 'model' => 'pause.start'])</div>
                                <div class="mb-2">@include('workday::Tech.time-picker', ['kind' => 'breaks', 'edge' => 'end', 'model' => 'pause.end'])</div>
                                <label class="form-label d-block">Treatment<select class="form-select" :name="'breaks['+i+'][included]'" x-model="pause.included" required><option value="">Choose treatment</option><option value="0">Excluded from actual time</option><option value="1">Included in actual time</option></select></label>
                                <button class="btn btn-sm btn-outline-danger" type="button" @click="breaks.splice(i, 1); dirty = true">Remove break</button>
                            </fieldset>
                        </template>
                        <button class="btn btn-sm btn-outline-secondary mb-2" type="button" @click="breaks.push({start:'', end:'', included:''}); dirty = true" :disabled="breaks.length >= 48">Add break</button>
                        <div><button class="btn btn-outline-primary" type="submit" formnovalidate @click="includeSelection = false">Save day details</button></div>
                        <p class="small text-muted mt-2 mb-0">Saves the description, breaks and removals without adding the selected hour.</p>
                    </details>
                    <noscript><p class="alert alert-warning mt-3">Enable JavaScript to use the hourly calendar.</p></noscript>
                </form>
            </div>
            <div class="modal-footer justify-content-between">
                <span class="small text-muted">Closing keeps unsaved changes on this page. Save to record them.</span>
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
