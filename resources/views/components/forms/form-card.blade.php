<div class="card mb-3">
    <div class="card-header">
        <h3>{{ $title }}</h3>
    </div>
    <div class="card-body">
        {{ $slot }}
    </div>
    @isset($footer)
        <div class="card-footer">
            {{ $footer }}
        </div>
    @endisset
</div>