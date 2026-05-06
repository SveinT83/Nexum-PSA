@props(['url', 'class', 'slot'])

<a href="{{ $url ?? '#' }}"
   class="{{ $class ?? 'btn btn-sm btn-outline-secondary mb-3 bi bi-arrow-left' }}">
    {{ $slot ?? 'Back' }}
</a>
