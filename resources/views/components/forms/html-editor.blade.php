@props([
    'id',
    'name',
    'value' => null,
    'rows' => 10,
    'height' => 340,
])

<!-- -------------------------------------------------------------------------------------------------- -->
<!-- Shared outbound HTML editor -->
<!-- Keeps a native textarea as the canonical form control while Jodit provides visual/source editing. -->
<!-- -------------------------------------------------------------------------------------------------- -->
<textarea
    id="{{ $id }}"
    name="{{ $name }}"
    rows="{{ $rows }}"
    data-html-editor
    data-html-editor-height="{{ $height }}"
    {{ $attributes->class(['form-control']) }}
>{{ $value }}</textarea>
<div class="form-text">Use the visual toolbar for content or choose <strong>Source</strong> to edit its HTML.</div>
