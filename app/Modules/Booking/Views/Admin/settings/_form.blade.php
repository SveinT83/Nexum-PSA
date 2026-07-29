@php
    $isEdit = $setting->exists;
    $action = $isEdit
        ? route('tech.admin.system.booking.settings.update', $setting)
        : route('tech.admin.system.booking.settings.store');
    $routingMode = old('technician_routing_mode', $setting->technician_routing_mode ?: \App\Modules\Booking\Models\BookingServiceSetting::ROUTING_FIXED);
    $selectedEligibleUserIds = collect(old(
        'eligible_user_ids',
        $setting->relationLoaded('eligibleUsers') ? $setting->eligibleUsers->pluck('id')->all() : [],
    ))->map(fn ($id) => (int) $id)->all();
@endphp

<div class="card shadow-sm">
    <div class="card-header bg-body">
        <h2 class="h6 mb-0">Service settings</h2>
    </div>
    <div class="card-body">
        <form method="POST" action="{{ $action }}">
            @csrf
            @if($isEdit)
                @method('PUT')
            @endif

            <div class="row g-3">
                <div class="col-md-6">
                    <label for="service_id" class="form-label">Commercial service <span class="text-danger">*</span></label>
                    <select id="service_id" name="service_id" class="form-select @error('service_id') is-invalid @enderror" required>
                        <option value="">Select service</option>
                        @foreach($services as $service)
                            <option value="{{ $service->id }}" @selected((int) old('service_id', $setting->service_id) === (int) $service->id)>
                                {{ $service->name }} ({{ $service->status }})
                            </option>
                        @endforeach
                    </select>
                    @error('service_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6" data-routing-section="fixed">
                    <label for="assigned_user_id" class="form-label">Assigned technician</label>
                    <select id="assigned_user_id" name="assigned_user_id" class="form-select @error('assigned_user_id') is-invalid @enderror">
                        <option value="">No assigned technician</option>
                        @foreach($users as $user)
                            <option value="{{ $user->id }}" @selected((int) old('assigned_user_id', $setting->assigned_user_id) === (int) $user->id)>
                                {{ $user->name }} ({{ $user->email }})
                            </option>
                        @endforeach
                    </select>
                    <div class="form-text">Used only when the routing mode is Fixed technician.</div>
                    @error('assigned_user_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label for="public_name" class="form-label">Public name <span class="text-danger">*</span></label>
                    <input type="text" id="public_name" name="public_name" value="{{ old('public_name', $setting->public_name) }}" class="form-control @error('public_name') is-invalid @enderror" required>
                    @error('public_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label for="slug" class="form-label">Slug</label>
                    <input type="text" id="slug" name="slug" value="{{ old('slug', $setting->slug) }}" class="form-control @error('slug') is-invalid @enderror" placeholder="generated-from-name">
                    @error('slug')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-4">
                    <label for="status" class="form-label">Status <span class="text-danger">*</span></label>
                    <select id="status" name="status" class="form-select @error('status') is-invalid @enderror" required>
                        @foreach(['draft' => 'Draft', 'active' => 'Active', 'archived' => 'Archived'] as $value => $label)
                            <option value="{{ $value }}" @selected(old('status', $setting->status) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('status')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-4">
                    <label class="form-label">Mode</label>
                    <div class="form-control bg-body-secondary">Staff confirmed</div>
                </div>
                <div class="col-md-4">
                    <label for="location" class="form-label">Location</label>
                    <input type="text" id="location" name="location" value="{{ old('location', $setting->location) }}" class="form-control @error('location') is-invalid @enderror">
                    @error('location')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label for="technician_routing_mode" class="form-label">Technician routing <span class="text-danger">*</span></label>
                    <select id="technician_routing_mode" name="technician_routing_mode" class="form-select @error('technician_routing_mode') is-invalid @enderror" required data-routing-mode>
                        <option value="fixed" @selected($routingMode === 'fixed')>Fixed technician</option>
                        <option value="automatic" @selected($routingMode === 'automatic')>Automatic assignment from eligible technicians</option>
                        <option value="customer_choice" @selected($routingMode === 'customer_choice')>Customer chooses an eligible technician</option>
                    </select>
                    <div class="form-text">Automatic mode shows combined availability without revealing technician identity.</div>
                    @error('technician_routing_mode')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label for="working_hours_source" class="form-label">Working-hours policy <span class="text-danger">*</span></label>
                    <select id="working_hours_source" name="working_hours_source" class="form-select @error('working_hours_source') is-invalid @enderror" required>
                        <option value="company" @selected(old('working_hours_source', $setting->working_hours_source ?: 'company') === 'company')>Company working hours</option>
                        <option value="technician" @selected(old('working_hours_source', $setting->working_hours_source) === 'technician')>Each technician's profile working hours</option>
                    </select>
                    <div class="form-text">The optional public window below further limits these hours.</div>
                    @error('working_hours_source')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-12" data-routing-section="pool">
                    <label class="form-label">Eligible technicians</label>
                    <div class="row g-2 border rounded p-2">
                        @foreach($users as $user)
                            <div class="col-md-6 col-xl-4">
                                <div class="form-check">
                                    <input type="checkbox" id="eligible_user_{{ $user->id }}" name="eligible_user_ids[]" value="{{ $user->id }}" class="form-check-input @error('eligible_user_ids') is-invalid @enderror" @checked(in_array((int) $user->id, $selectedEligibleUserIds, true))>
                                    <label for="eligible_user_{{ $user->id }}" class="form-check-label">{{ $user->name }} <span class="text-muted">({{ $user->email }})</span></label>
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <div class="form-text">Required for automatic and customer-choice routing.</div>
                    @error('eligible_user_ids')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    @error('eligible_user_ids.*')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-3">
                    <label for="duration_minutes" class="form-label">Duration minutes <span class="text-danger">*</span></label>
                    <input type="number" id="duration_minutes" name="duration_minutes" value="{{ old('duration_minutes', $setting->duration_minutes) }}" min="15" max="480" class="form-control @error('duration_minutes') is-invalid @enderror" required>
                    @error('duration_minutes')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-3">
                    <label for="slot_step_minutes" class="form-label">Slot step <span class="text-danger">*</span></label>
                    <select id="slot_step_minutes" name="slot_step_minutes" class="form-select @error('slot_step_minutes') is-invalid @enderror" required>
                        @foreach([15, 30, 60] as $step)
                            <option value="{{ $step }}" @selected((int) old('slot_step_minutes', $setting->slot_step_minutes) === $step)>{{ $step }} minutes</option>
                        @endforeach
                    </select>
                    @error('slot_step_minutes')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-3">
                    <label for="min_notice_hours" class="form-label">Minimum notice <span class="text-danger">*</span></label>
                    <input type="number" id="min_notice_hours" name="min_notice_hours" value="{{ old('min_notice_hours', $setting->min_notice_hours) }}" min="0" max="720" class="form-control @error('min_notice_hours') is-invalid @enderror" required>
                    @error('min_notice_hours')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-3">
                    <label for="horizon_days" class="form-label">Horizon days <span class="text-danger">*</span></label>
                    <input type="number" id="horizon_days" name="horizon_days" value="{{ old('horizon_days', $setting->horizon_days) }}" min="1" max="365" class="form-control @error('horizon_days') is-invalid @enderror" required>
                    @error('horizon_days')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-3">
                    <label for="opening_window_start" class="form-label">Public window starts</label>
                    <input type="time" id="opening_window_start" name="opening_window_start" value="{{ old('opening_window_start', $setting->opening_window_start ? substr((string) $setting->opening_window_start, 0, 5) : '') }}" step="900" class="form-control @error('opening_window_start') is-invalid @enderror">
                    @error('opening_window_start')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-3">
                    <label for="opening_window_end" class="form-label">Public window ends</label>
                    <input type="time" id="opening_window_end" name="opening_window_end" value="{{ old('opening_window_end', $setting->opening_window_end ? substr((string) $setting->opening_window_end, 0, 5) : '') }}" step="900" class="form-control @error('opening_window_end') is-invalid @enderror">
                    <div class="form-text">Leave both blank to use the full selected working hours.</div>
                    @error('opening_window_end')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-12">
                    <label for="public_description" class="form-label">Public description</label>
                    <textarea id="public_description" name="public_description" rows="3" class="form-control @error('public_description') is-invalid @enderror">{{ old('public_description', $setting->public_description) }}</textarea>
                    @error('public_description')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-12">
                    <label for="instructions" class="form-label">Customer instructions</label>
                    <textarea id="instructions" name="instructions" rows="3" class="form-control @error('instructions') is-invalid @enderror">{{ old('instructions', $setting->instructions) }}</textarea>
                    @error('instructions')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-12">
                    <details class="border rounded p-3">
                        <summary class="fw-semibold">Advanced spam protection</summary>
                        <p class="small text-muted mt-3 mb-2">
                            Nexum adds a hidden field that normal visitors never fill in. Automated submissions that fill it are recorded as spam.
                        </p>
                        <label for="spam_honeypot_field" class="form-label">Hidden anti-spam field name</label>
                        <input type="text" id="spam_honeypot_field" name="spam_honeypot_field" value="{{ old('spam_honeypot_field', $setting->spam_honeypot_field ?: 'booking_website') }}" class="form-control @error('spam_honeypot_field') is-invalid @enderror">
                        <div class="form-text">Change this only when an integration requires a different field name.</div>
                        @error('spam_honeypot_field')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </details>
                </div>
            </div>

            <div class="d-flex justify-content-end gap-2 mt-4">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save" aria-hidden="true"></i>
                    Save
                </button>
            </div>
        </form>
    </div>
</div>

@once
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('[data-routing-mode]').forEach((modeSelect) => {
                const form = modeSelect.closest('form');
                const updateRoutingFields = () => {
                    const fixed = modeSelect.value === 'fixed';

                    form.querySelectorAll('[data-routing-section="fixed"]').forEach((section) => {
                        section.classList.toggle('d-none', !fixed);
                        section.querySelectorAll('select, input').forEach((control) => {
                            control.disabled = !fixed;
                        });
                    });

                    form.querySelectorAll('[data-routing-section="pool"]').forEach((section) => {
                        section.classList.toggle('d-none', fixed);
                        section.querySelectorAll('select, input').forEach((control) => {
                            control.disabled = fixed;
                        });
                    });
                };

                modeSelect.addEventListener('change', updateRoutingFields);
                updateRoutingFields();
            });
        });
    </script>
@endonce
