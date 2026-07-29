{{-- Shared customer-safe cadence groups keep public, portal, PDF, and staff preview totals aligned. --}}
@forelse($quotePresentation['groups'] as $group)
    <section class="quote-cadence-group mb-4">
        <h2 class="h5">{{ $group['label'] }}</h2>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-2">
                <thead>
                <tr>
                    <th>Description</th>
                    <th class="text-end right">Qty</th>
                    <th class="text-end right">Unit ({{ $group['unit'] }} ex VAT)</th>
                    <th class="text-end right">Total ({{ $group['unit'] }} ex VAT)</th>
                </tr>
                </thead>
                <tbody>
                @foreach($group['lines'] as $line)
                    <tr>
                        <td>
                            <strong>{{ $line->name }}</strong>
                            @if(filled($line->description))
                                <br><span class="text-muted muted small">{{ $line->description }}</span>
                            @endif
                        </td>
                        <td class="text-end right">{{ number_format((float) $line->quantity, 2, ',', ' ') }}</td>
                        <td class="text-end right">{{ number_format((float) $line->unit_price_ex_vat, 2, ',', ' ') }}</td>
                        <td class="text-end right fw-semibold">{{ number_format((float) $line->line_total_ex_vat, 2, ',', ' ') }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <div class="d-flex justify-content-end quote-cadence-totals">
            <dl class="row mb-0 text-end" style="min-width: 22rem;">
                <dt class="col-7">{{ $group['summary_label'] }} ex VAT</dt>
                <dd class="col-5">{{ number_format((float) $group['total_ex_vat'], 2, ',', ' ') }} {{ $group['unit'] }}</dd>
                <dt class="col-7">VAT{{ $group['suffix'] }}</dt>
                <dd class="col-5">{{ number_format((float) $group['vat_total'], 2, ',', ' ') }} {{ $group['unit'] }}</dd>
                <dt class="col-7">{{ $group['summary_label'] }} inc VAT</dt>
                <dd class="col-5 fw-semibold">{{ number_format((float) $group['total_inc_vat'], 2, ',', ' ') }} {{ $group['unit'] }}</dd>
            </dl>
        </div>
    </section>
@empty
    <p class="text-muted muted mb-0">No quote lines are available.</p>
@endforelse
