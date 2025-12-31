<div class="card mt-3 mb-3">
    <div class="card-header">
        <h4>{{ $title ?? ''}}</h4>
    </div>
    <div class="card-body">
        {{ $slot }}
    </div>
    @isset($footer)
        <div class="card-footer">
            {{ $footer ?? ''}}
        </div>
    @endisset
</div>
