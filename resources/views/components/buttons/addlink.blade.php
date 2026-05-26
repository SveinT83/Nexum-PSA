@props(['url' => '#', 'class' => '', 'slot' => null])

<a href="{{ $url }}"
   class="btn btn-sm btn-primary bi bi-plus {{ $class }}">
    {{ $slot }}
</a>
