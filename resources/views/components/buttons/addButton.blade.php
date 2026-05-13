@props([
    'type' => 'button',
])

<button {{ $attributes->merge(['type' => $type, 'class' => 'btn btn-sm btn-primary mb-3 bi bi-plus']) }}>
    {{$slot}}
</button>
