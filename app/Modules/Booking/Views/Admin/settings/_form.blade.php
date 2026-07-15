@php
    $isEdit = $setting->exists;
    $action = $isEdit
        ? route('tech.admin.system.booking.settings.update', $setting)
        : route('tech.admin.system.booking.settings.store');
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
                <div class="col-md-6">
                    <label for="assigned_user_id" class="form-label">Assigned technician</label>
                    <select id="assigned_user_id" name="assigned_user_id" class="form-select @error('assigned_user_id') is-invalid @enderror">
                        <option value="">No assigned technician</option>
                        @foreach($users as $user)
                            <option value="{{ $user->id }}" @selected((int) old('assigned_user_id', $setting->assigned_user_id) === (int) $user->id)>
                                {{ $user->name }} ({{ $user->email }})
                            </option>
                        @endforeach
                    </select>
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
                <div class="col-md-6">
                    <label for="spam_honeypot_field" class="form-label">Honeypot field</label>
                    <input type="text" id="spam_honeypot_field" name="spam_honeypot_field" value="{{ old('spam_honeypot_field', $setting->spam_honeypot_field ?: 'booking_website') }}" class="form-control @error('spam_honeypot_field') is-invalid @enderror">
                    @error('spam_honeypot_field')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>

            <div class="d-flex justify-content-between gap-2 mt-4">
                <a href="{{ route('tech.admin.system.booking.index') }}" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left" aria-hidden="true"></i>
                    Back
                </a>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save" aria-hidden="true"></i>
                    Save
                </button>
            </div>
        </form>
    </div>
</div>
