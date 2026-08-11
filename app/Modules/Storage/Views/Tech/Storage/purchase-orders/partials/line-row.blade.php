@php
    $lineId = $line['id'] ?? null;
    $selectedItemId = $line['item_id'] ?? null;
    $existingLine = filled($lineId);
    $lineLocked = (bool) ($line['line_locked'] ?? false);
@endphp
<tr class="purchase-order-line" data-existing="{{ $existingLine ? '1' : '0' }}"
    data-line-id="{{ $lineId }}" data-line-locked="{{ $lineLocked ? '1' : '0' }}">
    <td style="min-width: 16rem;">
        @if($lineId)
            <input type="hidden" name="lines[{{ $index }}][id]" value="{{ $lineId }}">
        @endif
        <input type="hidden" name="lines[{{ $index }}][qty_cancelled]" value="{{ $line['qty_cancelled'] ?? 0 }}">
        <input type="hidden" name="lines[{{ $index }}][cancellation_reason]" value="{{ $line['cancellation_reason'] ?? '' }}">

        @if($lineLocked)
            <input type="hidden" name="lines[{{ $index }}][item_id]" value="{{ $selectedItemId }}">
        @endif
        <select name="lines[{{ $index }}][item_id]" class="form-select form-select-sm line-item"
                required @disabled($lineLocked)>
            <option value="">Choose item</option>
            @foreach($items as $item)
                <option
                    value="{{ $item->id }}"
                    data-warehouse="{{ $item->warehouse_id }}"
                    @selected((string) $selectedItemId === (string) $item->id)>
                    {{ $item->sku }} - {{ $item->name }}
                </option>
            @endforeach
        </select>
        <div class="line-compatibility small text-danger mt-1 d-none"></div>
        @if($lineLocked)
            <div class="small text-muted mt-1">
                Immutable shipment, receipt, or cancellation history locks item, quantity, and commercial fields.
            </div>
        @endif
    </td>
    <td style="min-width: 7rem;">
        @if($lineLocked)
            <input type="hidden" name="lines[{{ $index }}][qty_ordered]" value="{{ $line['qty_ordered'] }}">
        @endif
        <input
            type="number"
            name="lines[{{ $index }}][qty_ordered]"
            class="form-control form-control-sm"
            min="1"
            value="{{ $line['qty_ordered'] ?? 1 }}"
            required
            @disabled($lineLocked)>
    </td>
    <td style="min-width: 10rem;">
        @if($lineLocked)
            <input type="hidden" name="lines[{{ $index }}][supplier_sku]" value="{{ $line['supplier_sku'] ?? '' }}">
        @endif
        <input
            type="text"
            name="lines[{{ $index }}][supplier_sku]"
            class="form-control form-control-sm line-supplier-sku"
            maxlength="255"
            value="{{ $line['supplier_sku'] ?? '' }}"
            @disabled($lineLocked)>
    </td>
    <td style="min-width: 8rem;">
        @if($lineLocked)
            <input type="hidden" name="lines[{{ $index }}][unit_cost]" value="{{ $line['unit_cost'] ?? '' }}">
        @endif
        <input
            type="number"
            name="lines[{{ $index }}][unit_cost]"
            class="form-control form-control-sm line-unit-cost"
            min="0"
            step="0.01"
            value="{{ $line['unit_cost'] ?? '' }}"
            @disabled($lineLocked)>
    </td>
    <td style="min-width: 6rem;">
        @if($lineLocked)
            <input type="hidden" name="lines[{{ $index }}][tax_rate]" value="{{ $line['tax_rate'] ?? '' }}">
        @endif
        <input
            type="number"
            name="lines[{{ $index }}][tax_rate]"
            class="form-control form-control-sm line-tax-rate"
            min="0"
            max="100"
            step="0.01"
            value="{{ $line['tax_rate'] ?? '' }}"
            @disabled($lineLocked)>
    </td>
    <td style="min-width: 10rem;">
        <input
            type="date"
            name="lines[{{ $index }}][expected_at]"
            class="form-control form-control-sm"
            value="{{ $line['expected_at'] ?? '' }}">
    </td>
    <td class="text-end">
        @if($existingLine)
            <span class="text-muted small" title="Existing lines remain visible so linked shipment and receipt history cannot be removed accidentally.">
                Existing
            </span>
        @else
            <button type="button" class="btn btn-sm btn-outline-danger remove-line" aria-label="Remove line" title="Remove line">
                <i class="bi bi-trash" aria-hidden="true"></i>
            </button>
        @endif
    </td>
</tr>
