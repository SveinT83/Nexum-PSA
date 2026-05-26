@props(['url' => '#', 'class' => '', 'slot' => null])

<a href="{{ $url }}"
   class="btn btn-sm btn-outline-secondary bi bi-arrow-left {{ $class ?: 'mb-3' }}">
    {{ $slot ?? 'Back' }}
</a>
