@extends('layouts.default_tech')

@section('title', $mode === 'edit' ? 'Edit Quote Template' : 'Create Quote Template')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center">
        <h1 class="mb-0">{{ $mode === 'edit' ? 'Edit Quote Template' : 'Create Quote Template' }}</h1>
        <x-buttons.back url="{{ route('tech.admin.settings.sales.quote-templates.index') }}">Back</x-buttons.back>
    </div>
@endsection

@section('content')
    <form method="POST" action="{{ $mode === 'edit' ? route('tech.admin.settings.sales.quote-templates.update', $template) : route('tech.admin.settings.sales.quote-templates.store') }}">
        @csrf
        @if($mode === 'edit')
            @method('PUT')
        @endif

        <!-- ------------------------------------------------- -->
        <!-- Template Details -->
        <!-- ------------------------------------------------- -->
        <x-card.default title="Template details">
            <div class="row g-3">
                <div class="col-md-5">
                    <label for="template_name" class="form-label">Name</label>
                    <input id="template_name" name="name" class="form-control" value="{{ old('name', $template->name) }}" required>
                </div>
                <div class="col-md-3">
                    <label for="template_target_type" class="form-label">Opportunity type</label>
                    <select id="template_target_type" name="target_type" class="form-select">
                        <option value="">Any type</option>
                        @foreach($targetTypes as $key => $label)
                            <option value="{{ $key }}" @selected(old('target_type', $template->target_type) === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="template_customer_segment" class="form-label">Customer segment</label>
                    <select id="template_customer_segment" name="customer_segment" class="form-select">
                        @foreach($customerSegments as $key => $label)
                            <option value="{{ $key }}" @selected(old('customer_segment', $template->customer_segment ?: 'general') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-1">
                    <div class="form-check form-switch mt-4">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" id="template_is_active" name="is_active" value="1" class="form-check-input" @checked(old('is_active', $template->is_active ?? true))>
                        <label for="template_is_active" class="form-check-label">Active</label>
                    </div>
                </div>
                <div class="col-12">
                    <label for="template_description" class="form-label">Description</label>
                    <textarea id="template_description" name="description" class="form-control" rows="2">{{ old('description', $template->description) }}</textarea>
                </div>
                <div class="col-12">
                    <details class="border rounded p-3">
                        <summary class="fw-semibold">Customer text and internal checklist</summary>
                        <div class="row g-3 mt-1">
                            <div class="col-md-6">
                                <label for="template_intro" class="form-label">Introduction</label>
                                <textarea id="template_intro" name="intro_text" class="form-control" rows="3">{{ old('intro_text', $template->intro_text) }}</textarea>
                            </div>
                            <div class="col-md-6">
                                <label for="template_scope" class="form-label">Solution and scope</label>
                                <textarea id="template_scope" name="scope_text" class="form-control" rows="3">{{ old('scope_text', $template->scope_text) }}</textarea>
                            </div>
                            <div class="col-md-4">
                                <label for="template_assumptions" class="form-label">Assumptions</label>
                                <textarea id="template_assumptions" name="assumptions_text" class="form-control" rows="3">{{ old('assumptions_text', $template->assumptions_text) }}</textarea>
                            </div>
                            <div class="col-md-4">
                                <label for="template_exclusions" class="form-label">Exclusions</label>
                                <textarea id="template_exclusions" name="exclusions_text" class="form-control" rows="3">{{ old('exclusions_text', $template->exclusions_text) }}</textarea>
                            </div>
                            <div class="col-md-4">
                                <label for="template_next_steps" class="form-label">Next steps</label>
                                <textarea id="template_next_steps" name="next_steps_text" class="form-control" rows="3">{{ old('next_steps_text', $template->next_steps_text) }}</textarea>
                            </div>
                            <div class="col-md-6">
                                <label for="template_checklist" class="form-label">Seller checklist</label>
                                <textarea id="template_checklist" name="seller_checklist_text" class="form-control" rows="3">{{ old('seller_checklist_text', implode("\n", $template->seller_checklist ?: [])) }}</textarea>
                            </div>
                            <div class="col-md-6">
                                <label for="template_hints" class="form-label">Approval policy hints</label>
                                <textarea id="template_hints" name="approval_policy_hints_text" class="form-control" rows="3">{{ old('approval_policy_hints_text', implode("\n", $template->approval_policy_hints ?: [])) }}</textarea>
                            </div>
                        </div>
                    </details>
                </div>
            </div>
        </x-card.default>

        <div class="d-flex justify-content-between gap-2 flex-wrap">
            <div>
                @if($mode === 'edit')
                    @can('sales.manage_settings')
                        <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteQuoteTemplateModal">
                            Delete template
                        </button>
                    @endcan
                @endif
            </div>
            <div class="d-flex gap-2">
                <a href="{{ route('tech.admin.settings.sales.quote-templates.index') }}" class="btn btn-outline-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary">{{ $mode === 'edit' ? 'Save template' : 'Create template' }}</button>
            </div>
        </div>
    </form>

    @if($mode === 'edit')
        <!-- ------------------------------------------------- -->
        <!-- Quote Lines -->
        <!-- ------------------------------------------------- -->
        <x-card.default title="1. Quote lines">
            <div class="table-responsive mb-3">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Line</th>
                            <th>Source</th>
                            <th>Group</th>
                            <th>Billing</th>
                            <th class="text-end">Qty</th>
                            <th class="text-end">Price</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($template->lines as $line)
                            <tr>
                                <td>
                                    <div class="fw-semibold">{{ $line->name }}</div>
                                    <div class="small text-muted">{{ $line->is_required ? 'Required' : 'Optional' }}{{ $line->is_recommended ? ' / Recommended' : '' }}</div>
                                </td>
                                <td>{{ $sourceTypes[$line->source_type] ?? $line->source_type }}</td>
                                <td>{{ $line->optionGroup?->name ?: '-' }}</td>
                                <td>{{ $quoteCadences[$line->billing_cadence]['unit'] ?? $line->billing_cadence }}</td>
                                <td class="text-end">{{ number_format((float) $line->quantity, 2, ',', ' ') }}</td>
                                <td class="text-end">{{ number_format((float) $line->unit_price_ex_vat, 2, ',', ' ') }}</td>
                                <td class="text-end">
                                    <form method="POST" action="{{ route('tech.admin.settings.sales.quote-templates.lines.destroy', [$template, $line]) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">No quote lines yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="accordion" id="salesTemplateLineEditor">
                <div class="accordion-item">
                    <h2 class="accordion-header" id="salesTemplateLineHeading">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#salesTemplateLineCollapse">
                            Add quote line
                        </button>
                    </h2>
                    <div id="salesTemplateLineCollapse" class="accordion-collapse collapse" data-bs-parent="#salesTemplateLineEditor">
                        <div class="accordion-body">
                            <form method="POST" action="{{ route('tech.admin.settings.sales.quote-templates.lines.store', $template) }}" class="row g-3 template-line-form">
                                @csrf
                                <div class="col-md-5">
                                    <label for="template_source" class="form-label">Catalog source</label>
                                    <select id="template_source" name="source_reference" class="form-select template-line-source">
                                        <option value="custom">Custom line</option>
                                        @foreach($lineCatalogs as $sourceType => $items)
                                            @if($items->isNotEmpty())
                                                <optgroup label="{{ $sourceTypes[$sourceType] ?? $sourceType }}">
                                                    @foreach($items as $item)
                                                        <option
                                                            value="{{ $item['value'] }}"
                                                            data-name="{{ $item['name'] }}"
                                                            data-description="{{ $item['description'] }}"
                                                            data-price="{{ $item['price'] }}"
                                                            data-cost="{{ $item['cost'] }}"
                                                            data-vat="{{ $item['vat'] }}"
                                                        >
                                                            {{ $item['label'] }}
                                                        </option>
                                                    @endforeach
                                                </optgroup>
                                            @endif
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label for="template_section" class="form-label">Section</label>
                                    <select id="template_section" name="section" class="form-select">
                                        @foreach($sections as $key => $label)
                                            <option value="{{ $key }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label for="template_downstream" class="form-label">Downstream</label>
                                    <select id="template_downstream" name="downstream_type" class="form-select">
                                        @foreach($downstreamTypes as $key => $label)
                                            <option value="{{ $key }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label for="template_billing" class="form-label">Billing</label>
                                    <select id="template_billing" name="billing_cadence" class="form-select">
                                        @foreach($quoteCadences as $key => $cadence)
                                            <option value="{{ $key }}">{{ $cadence['unit'] }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label for="template_line_name" class="form-label">Line name</label>
                                    <input id="template_line_name" name="name" class="form-control template-line-name" required>
                                </div>
                                <div class="col-md-4">
                                    <label for="template_line_description" class="form-label">Customer explanation</label>
                                    <input id="template_line_description" name="description" class="form-control template-line-description">
                                </div>
                                <div class="col-md-4">
                                    <label for="template_line_group" class="form-label">Option group</label>
                                    <select id="template_line_group" class="form-select template-option-group-select">
                                        <option value="">No group</option>
                                        @foreach($template->optionGroups as $optionGroup)
                                            <option value="{{ $optionGroup->id }}">{{ $optionGroup->name }} ({{ $optionGroupTypes[$optionGroup->type] ?? $optionGroup->type }})</option>
                                        @endforeach
                                        <option value="__new">Create new group</option>
                                    </select>
                                    <input type="hidden" name="option_group_id" class="template-option-group-id">
                                </div>
                                <div class="col-12 template-new-group-fields d-none">
                                    <div class="row g-3 border rounded p-3">
                                        <div class="col-md-4">
                                            <label for="template_new_group_name" class="form-label">New group name</label>
                                            <input id="template_new_group_name" name="option_group_name" class="form-control" disabled>
                                        </div>
                                        <div class="col-md-3">
                                            <label for="template_new_group_type" class="form-label">Group type</label>
                                            <select id="template_new_group_type" name="option_group_type" class="form-select" disabled>
                                                @foreach($optionGroupTypes as $key => $label)
                                                    <option value="{{ $key }}">{{ $label }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="col-md-2">
                                            <label for="template_new_group_min" class="form-label">Min choices</label>
                                            <input id="template_new_group_min" name="option_group_min_select" type="number" min="0" value="0" class="form-control" disabled>
                                        </div>
                                        <div class="col-md-2">
                                            <label for="template_new_group_max" class="form-label">Max choices</label>
                                            <input id="template_new_group_max" name="option_group_max_select" type="number" min="1" class="form-control" disabled>
                                        </div>
                                        <div class="col-md-12">
                                            <label for="template_new_group_description" class="form-label">Group description</label>
                                            <input id="template_new_group_description" name="option_group_description" class="form-control" disabled>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <label for="template_line_quantity" class="form-label">Qty</label>
                                    <input id="template_line_quantity" name="quantity" type="number" step="0.01" min="0.01" value="1" class="form-control" required>
                                </div>
                                <div class="col-md-2">
                                    <label for="template_line_price" class="form-label">Price</label>
                                    <input id="template_line_price" name="unit_price_ex_vat" type="number" step="0.01" min="0" class="form-control template-line-price">
                                </div>
                                <div class="col-md-2">
                                    <label for="template_line_discount_type" class="form-label">Discount</label>
                                    <select id="template_line_discount_type" name="discount_type" class="form-select">
                                        <option value="amount">Amount</option>
                                        <option value="percent">Percent</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label for="template_line_discount" class="form-label">Discount value</label>
                                    <input id="template_line_discount" name="discount_value" type="number" step="0.01" min="0" value="0" class="form-control">
                                </div>
                                <div class="col-md-4">
                                    <div class="border rounded p-2 mt-4">
                                        <div class="form-check form-check-inline">
                                            <input type="hidden" name="is_required" value="0">
                                            <input type="checkbox" name="is_required" value="1" class="form-check-input" id="template_line_required" checked>
                                            <label class="form-check-label" for="template_line_required">Required</label>
                                        </div>
                                        <div class="form-check form-check-inline">
                                            <input type="hidden" name="is_recommended" value="0">
                                            <input type="checkbox" name="is_recommended" value="1" class="form-check-input" id="template_line_recommended">
                                            <label class="form-check-label" for="template_line_recommended">Recommended</label>
                                        </div>
                                        <div class="form-check form-check-inline">
                                            <input type="hidden" name="customer_selected_by_default" value="0">
                                            <input type="checkbox" name="customer_selected_by_default" value="1" class="form-check-input" id="template_line_default" checked>
                                            <label class="form-check-label" for="template_line_default">Default selected</label>
                                        </div>
                                        <div class="form-check form-check-inline">
                                            <input type="hidden" name="customer_quantity_editable" value="0">
                                            <input type="checkbox" name="customer_quantity_editable" value="1" class="form-check-input" id="template_line_qty_editable">
                                            <label class="form-check-label" for="template_line_qty_editable">Quantity selectable</label>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <details class="border rounded p-3">
                                        <summary class="fw-semibold">Advanced line settings</summary>
                                        <div class="row g-3 mt-1">
                                            <div class="col-md-2">
                                                <label for="template_line_cost" class="form-label">Cost</label>
                                                <input id="template_line_cost" name="unit_cost_ex_vat" type="number" step="0.01" min="0" class="form-control template-line-cost">
                                            </div>
                                            <div class="col-md-2">
                                                <label for="template_line_vat" class="form-label">VAT %</label>
                                                <input id="template_line_vat" name="vat_rate" type="number" step="0.01" min="0" max="100" value="25" class="form-control template-line-vat">
                                            </div>
                                            <div class="col-md-3">
                                                <label for="template_line_customer_label" class="form-label">Customer label</label>
                                                <input id="template_line_customer_label" name="customer_label" class="form-control">
                                            </div>
                                            <div class="col-md-2">
                                                <label for="template_line_min_qty" class="form-label">Min customer qty</label>
                                                <input id="template_line_min_qty" name="min_customer_quantity" type="number" step="0.01" min="0.01" class="form-control">
                                            </div>
                                            <div class="col-md-2">
                                                <label for="template_line_max_qty" class="form-label">Max customer qty</label>
                                                <input id="template_line_max_qty" name="max_customer_quantity" type="number" step="0.01" min="0.01" class="form-control">
                                            </div>
                                            <div class="col-md-1">
                                                <label for="template_line_sort" class="form-label">Sort</label>
                                                <input id="template_line_sort" name="sort_order" type="number" min="0" value="0" class="form-control">
                                            </div>
                                        </div>
                                    </details>
                                </div>
                                <div class="col-12">
                                    <button type="submit" class="btn btn-outline-primary">Add quote line</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </x-card.default>

        <!-- ------------------------------------------------- -->
        <!-- Acknowledgements -->
        <!-- ------------------------------------------------- -->
        <x-card.default title="2. Acknowledgements">
            <div class="table-responsive mb-3">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Title</th>
                            <th>Scope</th>
                            <th>Required</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($template->acknowledgements as $acknowledgement)
                            <tr>
                                <td>{{ $acknowledgement->title }}</td>
                                <td>{{ $acknowledgement->line?->name ?: 'Quote' }}</td>
                                <td>{{ $acknowledgement->is_required ? 'Yes' : 'No' }}</td>
                                <td class="text-end">
                                    <form method="POST" action="{{ route('tech.admin.settings.sales.quote-templates.acknowledgements.destroy', [$template, $acknowledgement]) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="text-center text-muted py-4">No acknowledgements yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="accordion" id="salesTemplateAcknowledgementEditor">
                <div class="accordion-item">
                    <h2 class="accordion-header" id="salesTemplateAcknowledgementHeading">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#salesTemplateAcknowledgementCollapse">
                            Add acknowledgement
                        </button>
                    </h2>
                    <div id="salesTemplateAcknowledgementCollapse" class="accordion-collapse collapse" data-bs-parent="#salesTemplateAcknowledgementEditor">
                        <div class="accordion-body">
                            <form method="POST" action="{{ route('tech.admin.settings.sales.quote-templates.acknowledgements.store', $template) }}" class="row g-3">
                                @csrf
                                <div class="col-md-3">
                                    <label for="template_ack_line" class="form-label">Scope</label>
                                    <select id="template_ack_line" name="template_line_id" class="form-select">
                                        <option value="">Quote-level</option>
                                        @foreach($template->lines as $line)
                                            <option value="{{ $line->id }}">{{ $line->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label for="template_ack_title" class="form-label">Title</label>
                                    <input id="template_ack_title" name="title" class="form-control" required>
                                </div>
                                <div class="col-md-4">
                                    <label for="template_ack_body" class="form-label">Body</label>
                                    <input id="template_ack_body" name="body" class="form-control" required>
                                </div>
                                <div class="col-md-1">
                                    <label for="template_ack_sort" class="form-label">Sort</label>
                                    <input id="template_ack_sort" name="sort_order" type="number" min="0" value="0" class="form-control">
                                </div>
                                <div class="col-md-1">
                                    <div class="form-check mt-4">
                                        <input type="hidden" name="is_required" value="0">
                                        <input type="checkbox" name="is_required" value="1" class="form-check-input" id="template_ack_required" checked>
                                        <label class="form-check-label" for="template_ack_required">Required</label>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <button type="submit" class="btn btn-outline-primary">Add acknowledgement</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </x-card.default>
    @endif

    @if($mode === 'edit')
        @can('sales.manage_settings')
            <div class="modal fade" id="deleteQuoteTemplateModal" tabindex="-1" aria-labelledby="deleteQuoteTemplateModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <form method="POST" action="{{ route('tech.admin.settings.sales.quote-templates.destroy', $template) }}">
                            @csrf
                            @method('DELETE')
                            <div class="modal-header">
                                <h2 class="modal-title h5" id="deleteQuoteTemplateModalLabel">Delete quote template?</h2>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <p class="mb-0">
                                    This removes <strong>{{ $template->name }}</strong> from active quote template lists.
                                    Existing quote drafts, sent quotes, and accepted snapshots keep their copied template data.
                                </p>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-danger">Delete template</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        @endcan
    @endif
@endsection

@section('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('.template-line-form').forEach((form) => {
                const sourceSelect = form.querySelector('.template-line-source');
                const nameInput = form.querySelector('.template-line-name');
                const descriptionInput = form.querySelector('.template-line-description');
                const priceInput = form.querySelector('.template-line-price');
                const costInput = form.querySelector('.template-line-cost');
                const vatInput = form.querySelector('.template-line-vat');
                const groupSelect = form.querySelector('.template-option-group-select');
                const groupIdInput = form.querySelector('.template-option-group-id');
                const newGroupFields = form.querySelector('.template-new-group-fields');

                const assignValue = (input, value) => {
                    if (! input || value === undefined || value === null || value === '') {
                        return;
                    }

                    input.value = value;
                };

                sourceSelect?.addEventListener('change', () => {
                    const option = sourceSelect.selectedOptions[0];

                    if (! option || option.value === 'custom') {
                        return;
                    }

                    assignValue(nameInput, option.dataset.name);
                    assignValue(descriptionInput, option.dataset.description);
                    assignValue(priceInput, option.dataset.price);
                    assignValue(costInput, option.dataset.cost);
                    assignValue(vatInput, option.dataset.vat);
                });

                const syncGroupFields = () => {
                    const selectedValue = groupSelect?.value || '';
                    const isNewGroup = selectedValue === '__new';

                    if (groupIdInput) {
                        groupIdInput.value = /^\d+$/.test(selectedValue) ? selectedValue : '';
                    }

                    newGroupFields?.classList.toggle('d-none', ! isNewGroup);
                    newGroupFields?.querySelectorAll('input, select, textarea').forEach((field) => {
                        field.disabled = ! isNewGroup;
                    });
                };

                groupSelect?.addEventListener('change', syncGroupFields);
                syncGroupFields();
            });
        });
    </script>
@endsection

@section('sidebar')
    <x-nav.admin-menu group="sales" />
@endsection

@section('rightbar')
    @if($mode === 'edit')
        <x-card.default title="Template">
            <div class="d-flex justify-content-between small mb-2">
                <span class="text-muted">Lines</span>
                <strong>{{ $template->lines->count() }}</strong>
            </div>
            <div class="d-flex justify-content-between small mb-2">
                <span class="text-muted">Groups</span>
                <strong>{{ $template->optionGroups->count() }}</strong>
            </div>
            <div class="d-flex justify-content-between small mb-2">
                <span class="text-muted">Acknowledgements</span>
                <strong>{{ $template->acknowledgements->count() }}</strong>
            </div>
            <div class="d-flex justify-content-between small mb-0">
                <span class="text-muted">Status</span>
                <strong>{{ $template->is_active ? 'Active' : 'Disabled' }}</strong>
            </div>
        </x-card.default>
    @endif
@endsection
