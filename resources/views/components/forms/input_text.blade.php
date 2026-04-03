@props([
    'name',
    'input_class' => '',
    'labelName' => null,
    'layout' => 'horizontal',
    'value' => '',
    'type' => 'text',
    'placeholder' => '',
    'enabled' => 'enabled',
    'inputVar' => '',
    'labelClass' => ''
])

<!-- ------------------------------------------------- -->
<!-- Layout variables -->
<!-- ------------------------------------------------- -->
@php
    // Nå vil $input_class inneholde "col-md-1"
    $layout = $layout ?? "horizontal";

    if($layout === 'vertical'){
        $labelClass = "col-md-3";
        if (!str_contains($input_class, 'col')) {
            $input_class = "col ";
        }
    }

@endphp

<!-- ------------------------------------------------- -->
<!-- Input Text whit label-->
<!-- ------------------------------------------------- -->

@if(isset($labelName))
    <label for="{{$name}}" class="form-label fw-bold {{$labelClass}}">{{ $labelName ?? ""}}</label>
@endif

@if($layout == 'vertical')<div class="{{$input_class}}">@endif

<input type="{{ $type ?? 'text' }}"
       class="{{$input_class ?? ''}} form-control @error($name) is-invalid @enderror"
       id="{{ $name }}"
       name="{{ $name }}"
       value="{{ $value ?? '' }}"
       placeholder="{{ $placeholder ?? '' }}"
        {{ $enabled ?? "enabled" }}
        {{ $inputVar ?? '' }}
       aria-describedby="{{$name ?? ''}}"/>

@error($name)
    <div class="invalid-feedback">{{ $message }}</div>
@enderror

@if($layout == 'vertical')</div>@endif
