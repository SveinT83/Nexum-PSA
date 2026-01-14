<div>
    <x-card.default title="Pricing & Margin">
        <div class="table-responsive" style="overflow-x: auto; overflow-y: visible;">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Category</th>
                        <th class="text-end">Cost Price</th>
                        <th class="text-end">Suggested Sales Price</th>
                        <th class="text-end" style="width: 200px;">Sales Price</th>
                        <th class="text-end">Margin</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($this->pricingRows as $unit => $row)
                        <tr wire:key="pricing-row-{{ $unit }}">
                            <td class="text-capitalize">{{ $unit }}</td>
                            <td class="text-end text-muted">{{ number_format($row['cost'], 2) }}</td>
                            <td class="text-end text-muted">{{ number_format($row['suggested_sales_price'], 2) }}</td>
                            <td class="text-end">
                                <div class="input-group input-group-sm">
                                    <input type="number"
                                           step="0.01"
                                           wire:model.live="customSalesPrices.{{ $unit }}"
                                           name="sales_price_{{ $unit }}"
                                           class="form-control text-end {{ $row['has_custom'] ? 'border-primary text-primary fw-bold' : '' }}"
                                           placeholder="{{ number_format($row['suggested_sales_price'], 2, '.', '') }}"
                                           {{ $enabled === 'disabled' ? 'disabled' : '' }}>
                                    <span class="input-group-text">kr</span>
                                </div>
                            </td>
                            <td class="text-end">
                                <span class="{{ $row['margin'] >= 0 ? 'text-success' : 'text-danger' }} fw-bold">
                                    {{ number_format($row['margin'], 2) }}
                                </span>
                                <br>
                                <small class="text-muted">({{ number_format($row['margin_percent'], 1) }}%)</small>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                        <tfoot class="table-light fw-bold">
                    <tr>
                        <td>Total</td>
                        <td class="text-end">{{ number_format($this->totalCostPrice, 2) }}</td>
                        <td class="text-end">{{ number_format($this->totalServiceSalesPrice, 2) }}</td>
                        <td class="text-end">{{ number_format($this->currentSalesPrice, 2) }}</td>
                        <td class="text-end">
                            @php
                                $totalMargin = $this->marginAmount;
                                $totalMarginPercent = $this->marginPercentage;
                            @endphp
                            <span class="{{ $totalMargin >= 0 ? 'text-success' : 'text-danger' }} fs-5">
                                {{ number_format($totalMargin, 2) }} kr
                            </span>
                            <br>
                            <small class="text-muted">({{ number_format($totalMarginPercent, 1) }}%)</small>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div class="mt-3 p-2 border rounded bg-light">
            <small class="text-muted">
                <i class="fas fa-info-circle me-1"></i>
                Sales Price overstyrer summen fra tjenester for hver kategori. Marginen beregnes per enhet og totalt basert på valgt Sales Price (Suggested eller Custom).
            </small>
        </div>
    </x-card.default>
</div>
