{{-- Shared customer-safe cadence groups keep public, portal, PDF, and staff preview totals aligned. --}}
@php
    $selectionFormId = $selectionFormId ?? null;
    $interactive = filled($selectionFormId ?? null);
@endphp
@forelse($quotePresentation['groups'] as $group)
    <section class="quote-cadence-group mb-4">
        <h2 class="h5">{{ $group['label'] }}</h2>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-2">
                <thead>
                <tr>
                    <th>Description</th>
                    @if($interactive)
                        <th class="text-center">Choice</th>
                    @endif
                    <th class="text-end right">Qty</th>
                    <th class="text-end right">Unit ({{ $group['unit'] }} ex VAT)</th>
                    <th class="text-end right">Total ({{ $group['unit'] }} ex VAT)</th>
                </tr>
                </thead>
                <tbody>
                @foreach($group['lines'] as $line)
                    <tr
                        data-cpq-line
                        data-cpq-cadence="{{ $group['key'] }}"
                        data-cpq-required="{{ $line->is_required ? '1' : '0' }}"
                        data-cpq-price="{{ (float) $line->unit_price_ex_vat }}"
                        data-cpq-discount-value="{{ (float) $line->discount_value }}"
                        data-cpq-discount-type="{{ $line->discount_type }}"
                        data-cpq-vat-rate="{{ (float) ($line->vat_rate ?? 0) }}"
                    >
                        <td>
                            <strong>{{ $line->customer_label ?: $line->name }}</strong>
                            <span class="ms-1">
                                @if($line->is_required)
                                    <span class="badge text-bg-light border">Required</span>
                                @elseif($line->is_recommended)
                                    <span class="badge text-bg-primary">Recommended</span>
                                @else
                                    <span class="badge text-bg-light border">Optional</span>
                                @endif
                            </span>
                            @if($line->optionGroup)
                                <br><span class="text-muted muted small">{{ $line->optionGroup->name }}</span>
                            @endif
                            @if(filled($line->description))
                                <br><span class="text-muted muted small">{{ $line->description }}</span>
                            @endif
                        </td>
                        @if($interactive)
                            <td class="text-center">
                                @if($line->is_required)
                                    <input type="hidden" form="{{ $selectionFormId }}" name="selected_line_ids[]" value="{{ $line->id }}">
                                    <span class="text-muted small">Included</span>
                                @else
                                    <input
                                        type="checkbox"
                                        form="{{ $selectionFormId }}"
                                        name="selected_line_ids[]"
                                        value="{{ $line->id }}"
                                        class="form-check-input"
                                        data-cpq-select
                                        @checked($line->getAttribute('cpq_selected'))
                                    >
                                @endif
                            </td>
                        @endif
                        <td class="text-end right">
                            @if($interactive && $line->customer_quantity_editable)
                                <input
                                    type="number"
                                    form="{{ $selectionFormId }}"
                                    name="quantities[{{ $line->id }}]"
                                    class="form-control form-control-sm text-end ms-auto"
                                    style="max-width: 7rem;"
                                    step="0.01"
                                    min="{{ $line->min_customer_quantity }}"
                                    @if($line->max_customer_quantity !== null) max="{{ $line->max_customer_quantity }}" @endif
                                    value="{{ old('quantities.'.$line->id, $line->getAttribute('cpq_effective_quantity')) }}"
                                    data-cpq-quantity
                                >
                            @else
                                @if($interactive)
                                    <input type="hidden" form="{{ $selectionFormId }}" name="quantities[{{ $line->id }}]" value="{{ $line->getAttribute('cpq_effective_quantity') ?: $line->quantity }}">
                                @endif
                                {{ number_format((float) ($line->getAttribute('cpq_effective_quantity') ?: $line->quantity), 2, ',', ' ') }}
                            @endif
                        </td>
                        <td class="text-end right">{{ number_format((float) $line->unit_price_ex_vat, 2, ',', ' ') }}</td>
                        <td class="text-end right fw-semibold" data-cpq-line-total>{{ number_format((float) ($line->getAttribute('cpq_line_total_ex_vat') ?: 0), 2, ',', ' ') }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <div class="d-flex justify-content-end quote-cadence-totals">
            <dl class="row mb-0 text-end" style="min-width: 22rem;">
                <dt class="col-7">{{ $group['summary_label'] }} ex VAT</dt>
                <dd class="col-5"><span data-cpq-total-ex="{{ $group['key'] }}" data-cpq-unit="{{ $group['unit'] }}">{{ number_format((float) $group['total_ex_vat'], 2, ',', ' ') }} {{ $group['unit'] }}</span></dd>
                <dt class="col-7">VAT{{ $group['suffix'] }}</dt>
                <dd class="col-5"><span data-cpq-total-vat="{{ $group['key'] }}" data-cpq-unit="{{ $group['unit'] }}">{{ number_format((float) $group['vat_total'], 2, ',', ' ') }} {{ $group['unit'] }}</span></dd>
                <dt class="col-7">{{ $group['summary_label'] }} inc VAT</dt>
                <dd class="col-5 fw-semibold"><span data-cpq-total-inc="{{ $group['key'] }}" data-cpq-unit="{{ $group['unit'] }}">{{ number_format((float) $group['total_inc_vat'], 2, ',', ' ') }} {{ $group['unit'] }}</span></dd>
            </dl>
        </div>
    </section>
@empty
    <p class="text-muted muted mb-0">No quote lines are available.</p>
@endforelse

@if($interactive)
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const money = (value) => new Intl.NumberFormat('nb-NO', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value);
            const rows = Array.from(document.querySelectorAll('[data-cpq-line]'));

            const recalculateQuoteSelection = () => {
                const totals = {};

                rows.forEach((row) => {
                    const cadence = row.dataset.cpqCadence;
                    totals[cadence] ||= { ex: 0, vat: 0, inc: 0 };

                    const selector = row.querySelector('[data-cpq-select]');
                    const selected = row.dataset.cpqRequired === '1' || !selector || selector.checked;
                    const quantityInput = row.querySelector('[data-cpq-quantity]');
                    const quantity = Number(quantityInput?.value || row.querySelector('input[name^="quantities"]')?.value || 0);
                    const base = Number(row.dataset.cpqPrice || 0) * quantity;
                    const discountValue = Number(row.dataset.cpqDiscountValue || 0);
                    const discount = row.dataset.cpqDiscountType === 'percent' ? base * (discountValue / 100) : discountValue;
                    const exVat = selected ? Math.max(0, base - discount) : 0;
                    const vat = exVat * (Number(row.dataset.cpqVatRate || 0) / 100);
                    const incVat = exVat + vat;

                    row.querySelector('[data-cpq-line-total]').textContent = money(exVat);
                    totals[cadence].ex += exVat;
                    totals[cadence].vat += vat;
                    totals[cadence].inc += incVat;
                });

                Object.entries(totals).forEach(([cadence, total]) => {
                    const ex = document.querySelector(`[data-cpq-total-ex="${cadence}"]`);
                    const vat = document.querySelector(`[data-cpq-total-vat="${cadence}"]`);
                    const inc = document.querySelector(`[data-cpq-total-inc="${cadence}"]`);

                    if (ex) ex.textContent = `${money(total.ex)} ${ex.dataset.cpqUnit || ''}`.trim();
                    if (vat) vat.textContent = `${money(total.vat)} ${vat.dataset.cpqUnit || ''}`.trim();
                    if (inc) inc.textContent = `${money(total.inc)} ${inc.dataset.cpqUnit || ''}`.trim();
                });
            };

            rows.forEach((row) => {
                row.querySelector('[data-cpq-select]')?.addEventListener('change', recalculateQuoteSelection);
                row.querySelector('[data-cpq-quantity]')?.addEventListener('input', recalculateQuoteSelection);
            });
            recalculateQuoteSelection();
        });
    </script>
@endif
