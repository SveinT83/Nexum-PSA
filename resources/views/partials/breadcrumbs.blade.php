{{--
    Breadcrumbs Partial
    This partial renders the breadcrumb navigation based on the current route.
    It uses the breadcrumbs() helper function which pulls data from config/breadcrumbs.php.

    Standard: Bootstrap 5 breadcrumb component.
--}}
@php $crumbs = breadcrumbs(); @endphp

@if(count($crumbs))
    <nav aria-label="breadcrumb" class="page-breadcrumbs">
        <ol class="breadcrumb mb-0 small">
            @foreach($crumbs as $crumb)
                @if($loop->last)
                    <li class="breadcrumb-item active" aria-current="page">{{ $crumb['label'] }}</li>
                @else
                    <li class="breadcrumb-item">
                        @if(isset($crumb['route']))
                            <a href="{{ route($crumb['route']) }}">{{ $crumb['label'] }}</a>
                        @else
                            {{ $crumb['label'] }}
                        @endif
                    </li>
                @endif
            @endforeach
        </ol>
    </nav>
@endif
