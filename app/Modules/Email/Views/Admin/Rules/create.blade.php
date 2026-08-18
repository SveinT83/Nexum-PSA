@extends('layouts.default_tech')

@php
  $isEdit = $mode === 'edit';
  $storedConditions = $rule->conditions_json ?: [['field' => 'subject', 'operator' => 'contains', 'value' => '']];
  $storedGroups = data_get($storedConditions, 'groups');
  $conditionMatch = old('condition_match', is_array($storedConditions) && ! array_is_list($storedConditions) ? ($storedConditions['match'] ?? 'all') : 'all');
  $conditions = old('conditions', $storedGroups
      ? collect($storedGroups)->flatMap(fn ($group) => collect($group['conditions'] ?? [])->map(fn ($condition) => $condition + [
          'group' => $group['name'] ?? 'Default',
          'group_match' => $group['match'] ?? 'all',
      ]))->values()->all()
      : $storedConditions);
  $actions = old('actions', $rule->actions_json ?: [['type' => 'tag_message', 'value' => '']]);
  $selectedAccountIds = collect(old('account_ids', $isEdit ? $rule->accounts->pluck('id')->all() : ($selectedAccountIds ?? [])))
      ->map(fn ($id) => (int) $id)
      ->all();
@endphp

@section('title', $isEdit ? 'Edit email rule' : 'Create email rule')

@section('pageHeader')
  <div class="d-flex align-items-center justify-content-between">
    <h1>{{ $isEdit ? 'Edit Email Rule' : 'Create Email Rule' }}</h1>
    <a href="{{ route('tech.admin.settings.email.rules') }}" class="btn btn-outline-secondary">Back to rules</a>
  </div>
@endsection

@section('sidebar')
  <x-nav.admin-menu group="email" />
@endsection

@section('content')
  <div class="col-12">
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
            <div class="col-12">
              <label for="routing_phase" class="form-label">Routing phase</label>
              <select id="routing_phase" name="routing_phase" class="form-select @error('routing_phase') is-invalid @enderror" required>
                <option value="normal" @selected(old('routing_phase', $rule->routing_phase ?? \App\Modules\Email\Models\EmailRule::ROUTING_PHASE_NORMAL) === 'normal')>Normal - after machine and AI classification</option>
                <option value="preclassification" @selected(old('routing_phase', $rule->routing_phase) === 'preclassification')>Preclassification - before machine and AI classification</option>
              </select>
              @error('routing_phase')<div class="invalid-feedback">{{ $message }}</div>@enderror
              <div class="form-text">Use preclassification only for explicit, narrow handoffs that must run before the generic classifier.</div>
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
          <h2 class="h5">Mailbox scope</h2>
          <div class="row g-2">
            @forelse($accounts as $account)
              <div class="col-md-6">
                <div class="form-check border rounded p-2 ps-5 h-100">
                  <input class="form-check-input" type="checkbox" id="account_{{ $account->id }}" name="account_ids[]" value="{{ $account->id }}" @checked(in_array((int) $account->id, $selectedAccountIds, true))>
                  <label class="form-check-label" for="account_{{ $account->id }}">
                    <span class="fw-semibold">{{ $account->address }}</span>
                    <span class="badge text-bg-light ms-1">{{ ucfirst($account->account_kind) }}</span>
                  </label>
                </div>
              </div>
            @empty
              <div class="col-12">
                <div class="alert alert-warning mb-0">No shared or system mailboxes have Ticket ingress enabled.</div>
              </div>
            @endforelse
          </div>
          @error('account_ids')<div class="text-danger small mt-2">{{ $message }}</div>@enderror
        </div>
      </div>

      <div class="card mb-3">
        <div class="card-body">
          <h2 class="h5">Conditions</h2>
          <div class="row g-2 align-items-end mb-3">
            <div class="col-md-4">
              <label class="form-label" for="condition_match">Group matching</label>
              <select id="condition_match" name="condition_match" class="form-select">
                <option value="all" @selected($conditionMatch === 'all')>All groups must match</option>
                <option value="any" @selected($conditionMatch === 'any')>Any group can match</option>
              </select>
            </div>
            <div class="col-md-auto ms-md-auto">
              <button type="button" class="btn btn-outline-primary" data-email-rule-add-condition>
                <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add condition
              </button>
            </div>
          </div>
          <div data-email-rule-conditions>
            @foreach($conditions as $index => $condition)
            <div class="row g-2 align-items-end mb-2" data-email-rule-condition-row>
              <div class="col-md-2">
                <label class="form-label" for="condition_group_{{ $index }}">Group</label>
                <input id="condition_group_{{ $index }}" name="conditions[{{ $index }}][group]" class="form-control" value="{{ $condition['group'] ?? 'Default' }}">
              </div>
              <div class="col-md-2">
                <label class="form-label" for="condition_group_match_{{ $index }}">Inside group</label>
                <select id="condition_group_match_{{ $index }}" name="conditions[{{ $index }}][group_match]" class="form-select">
                  <option value="all" @selected(($condition['group_match'] ?? 'all') === 'all')>All</option>
                  <option value="any" @selected(($condition['group_match'] ?? 'all') === 'any')>Any</option>
                </select>
              </div>
              <div class="col-md-3">
                <label class="form-label" for="condition_field_{{ $index }}">Field</label>
                <select id="condition_field_{{ $index }}" name="conditions[{{ $index }}][field]" class="form-select">
                  @foreach(['from' => 'From address', 'from_domain' => 'From domain', 'to' => 'To', 'cc' => 'Cc', 'subject' => 'Subject', 'body' => 'Body', 'message_id' => 'Message-ID', 'is_reply' => 'Is reply', 'has_ticket_key' => 'Has ticket key'] as $value => $label)
                    <option value="{{ $value }}" @selected(($condition['field'] ?? '') === $value)>{{ $label }}</option>
                  @endforeach
                </select>
              </div>
              <div class="col-md-2">
                <label class="form-label" for="condition_operator_{{ $index }}">Operator</label>
                <select id="condition_operator_{{ $index }}" name="conditions[{{ $index }}][operator]" class="form-select">
                  @foreach(['contains' => 'Contains', 'equals' => 'Equals', 'not_equals' => 'Not equals', 'starts_with' => 'Starts with', 'ends_with' => 'Ends with', 'regex' => 'Regex', 'present' => 'Present'] as $value => $label)
                    <option value="{{ $value }}" @selected(($condition['operator'] ?? 'contains') === $value)>{{ $label }}</option>
                  @endforeach
                </select>
              </div>
              <div class="col-md-3">
                <label class="form-label" for="condition_value_{{ $index }}">Value</label>
                <input id="condition_value_{{ $index }}" name="conditions[{{ $index }}][value]" class="form-control" value="{{ $condition['value'] ?? '' }}">
              </div>
              <div class="col-md-auto">
                <button type="button" class="btn btn-outline-secondary" data-email-rule-remove-condition title="Remove condition" @disabled(count($conditions) === 1)>
                  <i class="bi bi-trash" aria-hidden="true"></i>
                  <span class="visually-hidden">Remove condition</span>
                </button>
              </div>
            </div>
            @endforeach
          </div>
          <template data-email-rule-condition-template>
            <div class="row g-2 align-items-end mb-2" data-email-rule-condition-row>
              <div class="col-md-2">
                <label class="form-label" data-email-rule-label="group">Group</label>
                <input data-email-rule-field="group" class="form-control" value="Default">
              </div>
              <div class="col-md-2">
                <label class="form-label" data-email-rule-label="group_match">Inside group</label>
                <select data-email-rule-field="group_match" class="form-select">
                  <option value="all">All</option>
                  <option value="any">Any</option>
                </select>
              </div>
              <div class="col-md-3">
                <label class="form-label" data-email-rule-label="field">Field</label>
                <select data-email-rule-field="field" class="form-select">
                  @foreach(['from' => 'From address', 'from_domain' => 'From domain', 'to' => 'To', 'cc' => 'Cc', 'subject' => 'Subject', 'body' => 'Body', 'message_id' => 'Message-ID', 'is_reply' => 'Is reply', 'has_ticket_key' => 'Has ticket key'] as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                  @endforeach
                </select>
              </div>
              <div class="col-md-2">
                <label class="form-label" data-email-rule-label="operator">Operator</label>
                <select data-email-rule-field="operator" class="form-select">
                  @foreach(['contains' => 'Contains', 'equals' => 'Equals', 'not_equals' => 'Not equals', 'starts_with' => 'Starts with', 'ends_with' => 'Ends with', 'regex' => 'Regex', 'present' => 'Present'] as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                  @endforeach
                </select>
              </div>
              <div class="col-md-2">
                <label class="form-label" data-email-rule-label="value">Value</label>
                <input data-email-rule-field="value" class="form-control">
              </div>
              <div class="col-md-auto">
                <button type="button" class="btn btn-outline-secondary" data-email-rule-remove-condition title="Remove condition">
                  <i class="bi bi-trash" aria-hidden="true"></i>
                  <span class="visually-hidden">Remove condition</span>
                </button>
              </div>
            </div>
          </template>
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
                  @foreach(['link_ticket_by_subject_token' => 'Link to ticket by subject token', 'create_ticket' => 'Create ticket from inbound email', 'archive' => 'Archive / hide locally (legacy)', 'provider_archive' => 'Archive at mail provider', 'provider_move' => 'Move at mail provider', 'tag_message' => 'Apply tag to this message', 'tag_conversation' => 'Apply tag to the conversation', 'set_conversation_category' => 'Set conversation Email category', 'tag' => 'Apply legacy message tag', 'emit_signal' => 'Emit Signal'] as $value => $label)
                    <option value="{{ $value }}" @selected(($action['type'] ?? '') === $value)>{{ $label }}</option>
                  @endforeach
                </select>
              </div>
              <div class="col-md-7">
                <label class="form-label" for="action_value_{{ $index }}">Target folder ID / value / signal type</label>
                <input id="action_value_{{ $index }}" name="actions[{{ $index }}][value]" class="form-control" value="{{ $action['target_folder_id'] ?? $action['value'] ?? $action['signal_type'] ?? '' }}" placeholder="Provider folder ID, tag, Email category, queue, or signal type" list="email-rule-action-suggestions">
              </div>
            </div>
          @endforeach
          <datalist id="email-rule-action-suggestions">
            @foreach($tags as $tag)
              <option value="{{ $tag->name }}"></option>
            @endforeach
            @foreach($emailCategories as $category)
              <option value="{{ $category->name }}"></option>
            @endforeach
            @foreach($providerFolders as $folder)
              <option value="{{ $folder->id }}">{{ $folder->account?->address }} — {{ $folder->path }}</option>
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
  <x-card.default title="Examples">
    <p class="small text-muted mb-2">Spam filter: from domain contains bad-domain.example, action archive, stop processing.</p>
    <p class="small text-muted">Ticket replies: has ticket key present, action link to ticket by subject token.</p>
    <p class="small text-muted">New inbound tickets: recipient or mailbox condition, action create ticket from inbound email. Optional value can be a queue id or slug.</p>
    <p class="small text-muted">Message tags affect only the matched email. Conversation tags and Email categories are shared by every message in the account-scoped conversation.</p>
    <p class="small text-muted">Provider Archive and Move require one mailbox and an active provider folder ID. They use the auditable remote-operation ledger; the legacy Archive action only changes local Email state.</p>
    <p class="small text-muted mb-0">Signal handoff: choose Emit Signal and set the value to a signal type such as <code>security_notice</code>. Signal rules decide any cross-module follow-up.</p>
  </x-card.default>
@endsection

@section('scripts')
  <script>
    document.addEventListener('DOMContentLoaded', () => {
      const container = document.querySelector('[data-email-rule-conditions]');
      const template = document.querySelector('[data-email-rule-condition-template]');
      const addButton = document.querySelector('[data-email-rule-add-condition]');

      if (!container || !template || !addButton) {
        return;
      }

      const refreshIndexes = () => {
        container.querySelectorAll('[data-email-rule-condition-row]').forEach((row, index) => {
          row.querySelectorAll('[data-email-rule-field]').forEach((field) => {
            const key = field.getAttribute('data-email-rule-field');
            field.setAttribute('name', `conditions[${index}][${key}]`);
            field.setAttribute('id', `condition_${key}_${index}`);
          });
          row.querySelectorAll('[data-email-rule-label]').forEach((label) => {
            const key = label.getAttribute('data-email-rule-label');
            label.setAttribute('for', `condition_${key}_${index}`);
          });
          const remove = row.querySelector('[data-email-rule-remove-condition]');
          if (remove) {
            remove.disabled = container.querySelectorAll('[data-email-rule-condition-row]').length === 1;
          }
        });
      };

      addButton.addEventListener('click', () => {
        container.append(template.content.firstElementChild.cloneNode(true));
        refreshIndexes();
      });

      container.addEventListener('click', (event) => {
        const button = event.target.closest('[data-email-rule-remove-condition]');
        if (!button || container.querySelectorAll('[data-email-rule-condition-row]').length === 1) {
          return;
        }

        button.closest('[data-email-rule-condition-row]')?.remove();
        refreshIndexes();
      });

      refreshIndexes();
    });
  </script>
@endsection
