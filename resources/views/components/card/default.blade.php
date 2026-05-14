<div class="card mt-3 mb-3">
    <div class="card-header d-flex align-items-center justify-content-between gap-3">
        <h4>{{ $title ?? ''}}</h4>
        {{-- Optional header actions let module views place compact create buttons without duplicating card markup. --}}
        @isset($headerActions)
            <div>{{ $headerActions }}</div>
        @endisset
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
