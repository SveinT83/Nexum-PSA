{{-- Shared bounded source scope for self-map and parity-audit previews. --}}
<div>
    <label class="form-label" for="{{ $prefix }}-cutover-accounts">Exact mail accounts</label>
    <select class="form-select" id="{{ $prefix }}-cutover-accounts" name="account_ids[]" multiple required size="{{ min(8, max(3, $accounts->count())) }}">
        @foreach($accounts as $account)
            <option value="{{ $account->id }}">{{ $account->address }} · {{ ucfirst($account->account_kind) }}</option>
        @endforeach
    </select>
</div>
<div class="row g-2">
    <div class="col-sm-4">
        <label class="form-label" for="{{ $prefix }}-cutover-min">Minimum message ID</label>
        <input class="form-control" id="{{ $prefix }}-cutover-min" name="min_message_id" type="number" min="1" value="{{ old('min_message_id') }}">
    </div>
    <div class="col-sm-4">
        <label class="form-label" for="{{ $prefix }}-cutover-max">Maximum message ID</label>
        <input class="form-control" id="{{ $prefix }}-cutover-max" name="max_message_id" type="number" min="1" value="{{ old('max_message_id') }}">
    </div>
    <div class="col-sm-4">
        <label class="form-label" for="{{ $prefix }}-cutover-cap">Item cap</label>
        <input class="form-control" id="{{ $prefix }}-cutover-cap" name="item_cap" type="number" min="1" max="500" value="{{ old('item_cap', 100) }}">
    </div>
</div>
