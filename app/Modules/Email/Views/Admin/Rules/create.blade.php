@extends('layouts.default_tech')

@php
  $isEdit = $mode === 'edit';
  $conditions = old('conditions', $rule->conditions_json ?: [['field' => 'subject', 'operator' => 'contains', 'value' => '']]);
  $actions = old('actions', $rule->actions_json ?: [['type' => 'tag', 'value' => '']]);
@endphp

@section('title', $isEdit ? 'Edit email rule' : 'Create email rule')

@section('pageHeader')
  <div class="d-flex align-items-center justify-content-between">
    <h1>{{ $isEdit ? 'Edit Email Rule' : 'Create Email Rule' }}</h1>
    <a href="{{ route('tech.admin.settings.email.rules') }}" class="btn btn-outline-secondary">Back to rules</a>
  </div>
@endsection

@section('content')
  <div class="col-12 col-xl-9">
    <form method="POST" action="{{ $isEdit ? route('tech.admin.settings.email.rules.update', $rule) : route('tech.admin.settings.email.rules.store') }}">
      @csrf
      @if($isEdit)
        @method('PUT')
      @endif

      <div class="card mb-3">
        <!-- Actions: these are executed in order by the inbound rule engine after all conditions match. -->
        <div class="card-body">
          <h2 class="h5">General</h2>
          <div class="row g-3">
            <div class="col-md-8">
              <label for="name" class="form-label">Name</label>
              <input id="name" name="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $rule->name) }}" required>
              @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-4">
              <label for="weight" class="form-label">Weight</label>
              <input id="weight" name="weight" type="number" min="0" max="100000" class="form-control @error('weight') is-invalid @enderror" value="{{ old('weight', $rule->weight ?? 10) }}" required>
              @error('weight')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-12">
              <label for="description" class="form-label">Description</label>
              <textarea id="description" name="description" class="form-control" rows="2">{{ old('description', $rule->description) }}</textarea>
            </div>
            <div class="col-md-6">
              <div class="form-check form-switch">
                <input type="hidden" name="is_active" value="0">
                <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" @checked(old('is_active', $rule->is_active ?? true))>
                <label class="form-check-label" for="is_active">Active</label>
              </div>
            </div>
            <div class="col-md-6">
              <div class="form-check form-switch">
                <input type="hidden" name="stop_processing" value="0">
                <input class="form-check-input" type="checkbox" id="stop_processing" name="stop_processing" value="1" @checked(old('stop_processing', $rule->stop_processing ?? false))>
                <label class="form-check-label" for="stop_processing">Stop processing after this rule</label>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="card mb-3">
        <div class="card-body">
          <h2 class="h5">Conditions</h2>
          <p class="small text-muted">All conditions must match.</p>
          @foreach($conditions as $index => $condition)
            <div class="row g-2 align-items-end mb-2">
              <div class="col-md-4">
                <label class="form-label" for="condition_field_{{ $index }}">Field</label>
                <select id="condition_field_{{ $index }}" name="conditions[{{ $index }}][field]" class="form-select">
                  @foreach(['from' => 'From address', 'from_domain' => 'From domain', 'to' => 'To', 'cc' => 'Cc', 'subject' => 'Subject', 'body' => 'Body', 'message_id' => 'Message-ID', 'is_reply' => 'Is reply', 'has_ticket_key' => 'Has ticket key'] as $value => $label)
                    <option value="{{ $value }}" @selected(($condition['field'] ?? '') === $value)>{{ $label }}</option>
                  @endforeach
                </select>
              </div>
              <div class="col-md-3">
                <label class="form-label" for="condition_operator_{{ $index }}">Operator</label>
                <select id="condition_operator_{{ $index }}" name="conditions[{{ $index }}][operator]" class="form-select">
                  @foreach(['contains' => 'Contains', 'equals' => 'Equals', 'not_equals' => 'Not equals', 'starts_with' => 'Starts with', 'ends_with' => 'Ends with', 'regex' => 'Regex', 'present' => 'Present'] as $value => $label)
                    <option value="{{ $value }}" @selected(($condition['operator'] ?? 'contains') === $value)>{{ $label }}</option>
                  @endforeach
                </select>
              </div>
              <div class="col-md-5">
                <label class="form-label" for="condition_value_{{ $index }}">Value</label>
                <input id="condition_value_{{ $index }}" name="conditions[{{ $index }}][value]" class="form-control" value="{{ $condition['value'] ?? '' }}">
              </div>
            </div>
          @endforeach
          @error('conditions')<div class="text-danger small">{{ $message }}</div>@enderror
        </div>
      </div>

      <div class="card mb-3">
        <div class="card-body">
          <h2 class="h5">Actions</h2>
          @foreach($actions as $index => $action)
            <div class="row g-2 align-items-end mb-2">
              <div class="col-md-5">
                <label class="form-label" for="action_type_{{ $index }}">Action</label>
                <select id="action_type_{{ $index }}" name="actions[{{ $index }}][type]" class="form-select">
                  @foreach(['link_ticket_by_subject_token' => 'Link to ticket by subject token', 'create_ticket' => 'Create ticket from inbound email', 'archive' => 'Archive / hide from inbox', 'tag' => 'Apply tag'] as $value => $label)
                    <option value="{{ $value }}" @selected(($action['type'] ?? '') === $value)>{{ $label }}</option>
                  @endforeach
                </select>
              </div>
              <div class="col-md-7">
                <label class="form-label" for="action_value_{{ $index }}">Value</label>
                <input id="action_value_{{ $index }}" name="actions[{{ $index }}][value]" class="form-control" value="{{ $action['value'] ?? '' }}" placeholder="Tag name, queue id, or queue slug if action uses one" list="email-rule-tag-suggestions">
              </div>
            </div>
          @endforeach
          <datalist id="email-rule-tag-suggestions">
            @foreach($tags as $tag)
              <option value="{{ $tag->name }}"></option>
            @endforeach
          </datalist>
          @error('actions')<div class="text-danger small">{{ $message }}</div>@enderror
        </div>
      </div>

      <div class="d-flex justify-content-end gap-2">
        <a href="{{ route('tech.admin.settings.email.rules') }}" class="btn btn-outline-secondary">Cancel</a>
        <button type="submit" class="btn btn-primary">{{ $isEdit ? 'Save rule' : 'Create rule' }}</button>
      </div>
    </form>
  </div>
@endsection

@section('rightbar')
  <div class="mt-3">
    <h3 class="h6">Examples</h3>
    <p class="small text-muted mb-2">Spam filter: from domain contains bad-domain.example, action archive, stop processing.</p>
    <p class="small text-muted">Ticket replies: has ticket key present, action link to ticket by subject token.</p>
    <p class="small text-muted">New inbound tickets: recipient or mailbox condition, action create ticket from inbound email. Optional value can be a queue id or slug.</p>
  </div>
@endsection
