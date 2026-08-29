@if(($customFields ?? collect())->isNotEmpty())
    @php
        $formatTicketCustomFieldValue = function ($value) {
            if (is_array($value)) {
                return $value === [] ? '—' : implode(', ', $value);
            }

            if (is_bool($value)) {
                return $value ? 'Yes' : 'No';
            }

            return filled($value) ? $value : '—';
        };
    @endphp

    <!-- Visible Ticket Custom Fields remain a collapsed operational detail and never enter Customer Portal views. -->
    <div class="accordion-item border rounded mb-2 overflow-hidden">
        <h2 class="accordion-header" id="ticketCustomFieldsHeading">
            <button
                class="accordion-button collapsed py-2 px-3"
                type="button"
                data-bs-toggle="collapse"
                data-bs-target="#ticketCustomFieldsCollapse"
                aria-expanded="false"
                aria-controls="ticketCustomFieldsCollapse">
                <span class="d-flex align-items-center gap-2 w-100">
                    <i class="bi bi-ui-checks-grid" aria-hidden="true"></i>
                    <span>Custom fields</span>
                    <span class="badge text-bg-light border ms-auto">{{ $customFields->count() }}</span>
                </span>
            </button>
        </h2>
        <div
            id="ticketCustomFieldsCollapse"
            class="accordion-collapse collapse"
            aria-labelledby="ticketCustomFieldsHeading"
            data-bs-parent="#ticketRightbarAccordion">
            <div class="accordion-body p-3">
                <div class="row g-2 small">
                    @foreach($customFields as $field)
                        <div class="col-12">
                            <dl class="border rounded bg-light px-2 py-1 mb-0">
                                <dt class="text-muted fw-normal">{{ $field['label'] }}</dt>
                                <dd class="fw-semibold mb-0">{{ $formatTicketCustomFieldValue($field['value']) }}</dd>
                                @if(filled($field['help_text']))
                                    <dd class="text-muted mt-1 mb-0">{{ $field['help_text'] }}</dd>
                                @endif
                            </dl>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
@endif
