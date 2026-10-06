{{-- Explicit page completeness; empty/out-of-range pages do not claim to be the complete result. --}}
<p class="small text-muted mb-2">Page {{ $meta['page'] }} of {{ $meta['last_page'] }} · {{ $meta['returned_count'] }} of {{ $meta['total'] }} records shown · {{ ucfirst($meta['status']) }} response</p>
<nav aria-label="Confirmed work pages">
    @if($meta['page'] > 1)<a class="btn btn-sm btn-outline-secondary" href="{{ request()->fullUrlWithQuery(['page' => $meta['page'] - 1]) }}">Previous</a>@endif
    @if($meta['next_page'])<a class="btn btn-sm btn-outline-secondary" href="{{ request()->fullUrlWithQuery(['page' => $meta['next_page']]) }}">Next</a>@endif
</nav>
