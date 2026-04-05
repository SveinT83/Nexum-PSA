@props([
    'name',
    'selectClass' => '',
    'labelName' => null,
    'layout' => 'horizontal',
    'enabled' => 'enabled',
    'labelClass' => '',
    'inputVar' => ''
])

<!-- ------------------------------------------------- -->
<!-- Layout variables -->
<!-- ------------------------------------------------- -->
@php
    $layout = $layout ?? "horizontal";

    if($layout === 'vertical'){
        $labelClass = "col-md-3 " . $labelClass;
        // Vi sjekker om vi allerede har en col-klasse, hvis ikke legger vi til 'col'
        if (!str_contains($selectClass, 'col')) {
            $selectClass = "col " . $selectClass;
        }
    }

    $disabledAttr = (str_contains($inputVar, 'disabled') || $enabled === 'disabled') ? 'disabled' : '';
@endphp

<!-- ------------------------------------------------- -->
<!-- Label -->
<!-- ------------------------------------------------- -->
@if(isset($labelName))
    <label for="{{$name}}" class="form-label fw-bold {{$labelClass}}">{{$labelName}}</label>
@endif

<!-- Hvis layout er vertical, legger vi selecten inni en div med kolonne-klassen -->
@if($layout == 'vertical')<div class="{{$selectClass}}">@endif

    <select class="form-select @error($name) is-invalid @enderror"
            id="{{$name}}"
            name="{{$name}}"
        {{ $disabledAttr }}>

        {{ $slot }}
    </select>

    @error($name)
    <div class="invalid-feedback">{{ $message }}</div>
    @enderror

    @if($layout == 'vertical')</div>@endif
