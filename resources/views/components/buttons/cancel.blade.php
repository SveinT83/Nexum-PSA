@props(['url', 'class', 'slot'])

<a href="{{ $url ?? '#' }}"
   class="{{ $class ?? 'btn btn-sm btn-outline-secondary mb-3 "bi bi-x-octagon' }}">
    {{ $slot ?? 'Cancel' }}
</a>

