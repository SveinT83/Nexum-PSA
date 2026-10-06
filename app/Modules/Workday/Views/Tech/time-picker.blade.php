{{-- A native date/time picker with explicit disambiguation only when the local clock repeats. --}}
<label class="form-label" :for="'{{ $kind }}-'+i+'-{{ $edge }}'">{{ ucfirst($edge) }}</label>
<input class="form-control" type="datetime-local" step="60"
    :id="'{{ $kind }}-'+i+'-{{ $edge }}'" :value="local({{ $model }})"
    @input="{{ $model }} = $event.target.value" required>
<input type="hidden" :name="'{{ $kind }}['+i+'][{{ $edge }}]'" :value="{{ $model }}">
<div class="mt-2" x-show="offsets(local({{ $model }})).length > 1" x-cloak>
    <label class="form-label small" :for="'{{ $kind }}-'+i+'-{{ $edge }}-offset'">Clock-change occurrence</label>
    <select class="form-select form-select-sm" :id="'{{ $kind }}-'+i+'-{{ $edge }}-offset'"
        :value="offset({{ $model }})" @change="{{ $model }} = local({{ $model }}) + $event.target.value"
        :required="offsets(local({{ $model }})).length > 1">
        <option value="">Choose occurrence</option>
        <template x-for="choice in offsets(local({{ $model }}))" :key="choice">
            <option :value="choice" :selected="offset({{ $model }}) === choice" x-text="'UTC' + choice"></option>
        </template>
    </select>
</div>
