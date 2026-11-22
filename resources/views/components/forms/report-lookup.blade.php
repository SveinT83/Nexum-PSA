@props(['id', 'label', 'url', 'name', 'value' => null, 'text' => '', 'textName' => null])
@php
    $lookupConfig = ['url' => $url, 'value' => $value, 'text' => $text, 'id' => $id, 'allowText' => (bool) $textName];
@endphp
{{-- Stable IDs are cleared as soon as the user edits the visible selection. --}}
<div class="position-relative" x-data="nexumReportLookup({{ \Illuminate\Support\Js::from($lookupConfig) }})" @click.outside="open = false">
    <label for="{{ $id }}" class="form-label">{{ $label }}</label>
    <input type="hidden" name="{{ $name }}" :value="selectedId">
    @if($textName)<input type="hidden" name="{{ $textName }}" :value="selectedId ? '' : text">@endif
    <input id="{{ $id }}" type="search" class="form-control"
        role="combobox" aria-autocomplete="list" aria-controls="{{ $id }}-options"
        :aria-expanded="open.toString()" :aria-activedescendant="active >= 0 && open ? '{{ $id }}-option-' + active : null"
        autocomplete="off" maxlength="100" x-ref="input" x-model="text" @focus="focus()" @input="changed()"
        @keydown.arrow-down.prevent="move(1)" @keydown.arrow-up.prevent="move(-1)"
        @keydown.enter="if (open && active >= 0) { $event.preventDefault(); choose(items[active]); }"
        @keydown.escape.prevent.stop="open = false" @keydown.tab="open = false">
    <div x-cloak x-show="open" class="dropdown-menu show w-100 p-0 overflow-auto shadow-sm" style="max-height: 18rem; z-index: 1050">
        <div id="{{ $id }}-options" role="listbox" aria-label="{{ $label }}">
            <template x-for="(item, index) in items" :key="item.id">
                <button type="button" role="option" class="dropdown-item text-wrap" :class="{active: active === index}"
                    :id="'{{ $id }}-option-' + index" :aria-selected="(String(selectedId) === String(item.id)).toString()"
                    @pointerdown.prevent="choose(item)" @click="choose(item)" @mouseenter="active = index">
                    <span x-text="item.label"></span> <small class="opacity-75" x-text="item.hint"></small>
                </button>
            </template>
        </div>
        <div class="px-3 py-2 small text-muted" x-show="loading" role="status">Loading…</div>
        <div class="px-3 py-2 small text-muted" x-show="!loading && !items.length && !error">No matches.</div>
        <div class="px-3 py-2 small text-danger" x-show="error" role="status" x-text="error"></div>
        <button type="button" class="dropdown-item text-primary" x-show="nextPage && !loading" @click="load(true)">More suggestions</button>
    </div>
</div>
@once
    @include('report::Tech.lookup-script')
@endonce
