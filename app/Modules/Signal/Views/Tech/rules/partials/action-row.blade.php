@php
    $type = $action['type'] ?? '';
    $prefix = "actions[{$index}]";
    $summary = $type && isset($definition::ACTION_TYPES[$type]) ? $definition::ACTION_TYPES[$type]['label'] : 'New action';
@endphp

<div class="border rounded bg-body" data-signal-action-row draggable="true">
    <div class="d-flex align-items-center gap-2 p-2 bg-body-tertiary border-bottom">
        <button type="button" class="btn btn-sm btn-light text-muted" data-signal-action-drag aria-label="Drag action" title="Drag action">
            <i class="bi bi-grip-vertical" aria-hidden="true"></i>
        </button>
        <button type="button" class="btn btn-sm flex-grow-1 d-flex align-items-center gap-2 text-start" data-toggle-signal-action aria-expanded="{{ $type ? 'false' : 'true' }}">
            <i class="bi {{ $type ? 'bi-chevron-right' : 'bi-chevron-down' }} text-muted" data-signal-action-chevron aria-hidden="true"></i>
            <span class="fw-semibold text-truncate" data-signal-action-summary>{{ $summary }}</span>
        </button>
        <button type="button" class="btn btn-sm btn-outline-danger" data-remove-signal-action aria-label="Remove action" title="Remove action">
            <i class="bi bi-trash" aria-hidden="true"></i>
        </button>
    </div>
    <div class="p-3 {{ $type ? 'd-none' : '' }}" data-signal-action-panel>
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Action type</label>
                <select name="{{ $prefix }}[type]" class="form-select" data-signal-action-type>
                    <option value="">Select action</option>
                    @foreach($definition::ACTION_TYPES as $value => $meta)
                        <option value="{{ $value }}" @selected($type === $value)>{{ $meta['label'] }}</option>
                    @endforeach
                </select>
            </div>

            <div class="col-md-6" data-action-fields="tag_contact tag_client">
                <label class="form-label">Tag</label>
                <input type="text" name="{{ $prefix }}[tag]" class="form-control" value="{{ $action['tag'] ?? '' }}">
            </div>

            <div class="col-md-6" data-action-fields="emit_signal">
                <label class="form-label">New signal type</label>
                <input type="text" name="{{ $prefix }}[signal_type]" class="form-control" value="{{ $action['signal_type'] ?? '' }}">
            </div>
            <div class="col-md-3" data-action-fields="emit_signal">
                <label class="form-label">Severity</label>
                <select name="{{ $prefix }}[severity]" class="form-select">
                    <option value="">Use current</option>
                    @foreach(\App\Modules\Signal\Support\SignalSettings::SEVERITY_OPTIONS as $value => $label)
                        <option value="{{ $value }}" @selected(($action['severity'] ?? '') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3" data-action-fields="emit_signal">
                <label class="form-label">Confidence</label>
                <input type="number" name="{{ $prefix }}[confidence]" class="form-control" min="0" max="100" value="{{ $action['confidence'] ?? '' }}">
            </div>

            <div class="col-12" data-action-fields="webhook">
                <label class="form-label">Webhook URL</label>
                <input type="url" name="{{ $prefix }}[url]" class="form-control" value="{{ $action['url'] ?? '' }}">
            </div>

            <div class="col-md-6" data-action-fields="sales_follow_up ticket_follow_up task_follow_up portal_invitation">
                <label class="form-label">Actor / creator</label>
                <select name="{{ $prefix }}[actor_id]" class="form-select">
                    <option value="">Rule owner</option>
                    @foreach($actorOptions as $actor)
                        <option value="{{ $actor->id }}" @selected((string) ($action['actor_id'] ?? $action['creator_id'] ?? '') === (string) $actor->id)>{{ $actor->name ?: $actor->email }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-6" data-action-fields="sales_follow_up ticket_follow_up">
                <label class="form-label">Owner</label>
                <select name="{{ $prefix }}[owner_id]" class="form-select">
                    <option value="">No owner</option>
                    @foreach($actorOptions as $actor)
                        <option value="{{ $actor->id }}" @selected((string) ($action['owner_id'] ?? '') === (string) $actor->id)>{{ $actor->name ?: $actor->email }}</option>
                    @endforeach
                </select>
            </div>

            <div class="col-md-6" data-action-fields="sales_follow_up ticket_follow_up task_follow_up">
                <label class="form-label">Subject / title</label>
                <input type="text" name="{{ $prefix }}[subject]" class="form-control" value="{{ $action['subject'] ?? $action['activity_subject'] ?? $action['title'] ?? '' }}">
            </div>
            <div class="col-md-6" data-action-fields="sales_follow_up">
                <label class="form-label">Opportunity title</label>
                <input type="text" name="{{ $prefix }}[opportunity_title]" class="form-control" value="{{ $action['opportunity_title'] ?? '' }}">
            </div>
            <div class="col-md-4" data-action-fields="sales_follow_up">
                <label class="form-label">Opportunity type</label>
                <input type="text" name="{{ $prefix }}[opportunity_type]" class="form-control" value="{{ $action['opportunity_type'] ?? '' }}">
            </div>
            <div class="col-md-4" data-action-fields="sales_follow_up">
                <label class="form-label">Probability %</label>
                <input type="number" name="{{ $prefix }}[probability_percent]" class="form-control" min="0" max="100" value="{{ $action['probability_percent'] ?? '' }}">
            </div>
            <div class="col-md-4" data-action-fields="sales_follow_up">
                <label class="form-label">Estimated value</label>
                <input type="number" name="{{ $prefix }}[estimated_value_ex_vat]" class="form-control" min="0" step="0.01" value="{{ $action['estimated_value_ex_vat'] ?? '' }}">
            </div>
            <div class="col-md-4" data-action-fields="sales_follow_up">
                <label class="form-label">Follow-up in minutes</label>
                <input type="number" name="{{ $prefix }}[follow_up_minutes_from_now]" class="form-control" min="0" value="{{ $action['follow_up_minutes_from_now'] ?? '' }}">
            </div>
            <div class="col-md-4" data-action-fields="sales_follow_up">
                <label class="form-label">Next follow-up type</label>
                <input type="text" name="{{ $prefix }}[next_follow_up_type]" class="form-control" value="{{ $action['next_follow_up_type'] ?? '' }}">
            </div>
            <div class="col-md-4" data-action-fields="sales_follow_up">
                <label class="form-label">Next follow-up note</label>
                <input type="text" name="{{ $prefix }}[next_follow_up_note]" class="form-control" value="{{ $action['next_follow_up_note'] ?? '' }}">
            </div>
            <div class="col-md-6" data-action-fields="sales_follow_up">
                <div class="form-check form-switch">
                    <input type="hidden" name="{{ $prefix }}[append_to_existing]" value="0">
                    <input type="checkbox" role="switch" name="{{ $prefix }}[append_to_existing]" value="1" class="form-check-input" @checked($action['append_to_existing'] ?? true)>
                    <label class="form-check-label">Append to open opportunity</label>
                </div>
            </div>
            <div class="col-md-6" data-action-fields="sales_follow_up">
                <div class="form-check form-switch">
                    <input type="hidden" name="{{ $prefix }}[create_if_missing]" value="0">
                    <input type="checkbox" role="switch" name="{{ $prefix }}[create_if_missing]" value="1" class="form-check-input" @checked($action['create_if_missing'] ?? true)>
                    <label class="form-check-label">Create opportunity if missing</label>
                </div>
            </div>

            <div class="col-md-6" data-action-fields="task_follow_up">
                <label class="form-label">Assign task to</label>
                <select name="{{ $prefix }}[assigned_to]" class="form-select">
                    <option value="">Unassigned</option>
                    @foreach($actorOptions as $actor)
                        <option value="{{ $actor->id }}" @selected((string) ($action['assigned_to'] ?? '') === (string) $actor->id)>{{ $actor->name ?: $actor->email }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3" data-action-fields="task_follow_up">
                <label class="form-label">Due in minutes</label>
                <input type="number" name="{{ $prefix }}[due_minutes_from_now]" class="form-control" min="0" value="{{ $action['due_minutes_from_now'] ?? '' }}">
            </div>
            <div class="col-md-3" data-action-fields="task_follow_up">
                <label class="form-label">Estimate minutes</label>
                <input type="number" name="{{ $prefix }}[estimated_minutes]" class="form-control" min="0" value="{{ $action['estimated_minutes'] ?? '' }}">
            </div>

            <div class="col-md-6" data-action-fields="portal_invitation">
                <label class="form-label">Portal role</label>
                <select name="{{ $prefix }}[role]" class="form-select">
                    <option value="">Viewer</option>
                    @foreach($portalRoleOptions as $value => $label)
                        <option value="{{ $value }}" @selected(($action['role'] ?? '') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-6" data-action-fields="portal_invitation">
                <label class="form-label">Email override</label>
                <input type="email" name="{{ $prefix }}[email]" class="form-control" value="{{ $action['email'] ?? '' }}">
            </div>

            <div class="col-md-4" data-action-fields="ticket_follow_up task_follow_up portal_invitation">
                <label class="form-label">Site ID</label>
                <input type="number" name="{{ $prefix }}[site_id]" class="form-control" min="1" value="{{ $action['site_id'] ?? '' }}">
            </div>
            <div class="col-md-4" data-action-fields="ticket_follow_up task_follow_up">
                <label class="form-label">Queue ID</label>
                <input type="number" name="{{ $prefix }}[queue_id]" class="form-control" min="1" value="{{ $action['queue_id'] ?? '' }}">
            </div>
            <div class="col-md-4" data-action-fields="ticket_follow_up task_follow_up">
                <label class="form-label">Priority ID</label>
                <input type="number" name="{{ $prefix }}[priority_id]" class="form-control" min="1" value="{{ $action['priority_id'] ?? '' }}">
            </div>
            <div class="col-md-4" data-action-fields="ticket_follow_up">
                <label class="form-label">Contact ID</label>
                <input type="number" name="{{ $prefix }}[contact_id]" class="form-control" min="1" value="{{ $action['contact_id'] ?? '' }}">
            </div>
            <div class="col-md-4" data-action-fields="ticket_follow_up">
                <label class="form-label">Ticket type ID</label>
                <input type="number" name="{{ $prefix }}[ticket_type_id]" class="form-control" min="1" value="{{ $action['ticket_type_id'] ?? '' }}">
            </div>
            <div class="col-md-4" data-action-fields="ticket_follow_up task_follow_up">
                <label class="form-label">Category ID</label>
                <input type="number" name="{{ $prefix }}[category_id]" class="form-control" min="1" value="{{ $action['category_id'] ?? '' }}">
            </div>

            <div class="col-12" data-action-fields="emit_signal sales_follow_up ticket_follow_up task_follow_up">
                <label class="form-label">Description / body / summary</label>
                <textarea name="{{ $prefix }}[description]" rows="3" class="form-control">{{ $action['description'] ?? $action['activity_body'] ?? $action['summary'] ?? '' }}</textarea>
            </div>
        </div>
    </div>
</div>
