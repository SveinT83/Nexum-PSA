<!-- ------------------------------------------------- -->
<!-- Input Text whit label-->
<!-- ------------------------------------------------- -->

<label for="{{$name}}" class="form-label fw-bold">{{ $labelName }}</label>
<input type="{{ $type ?? 'text' }}"
       class="form-control @error($name) is-invalid @enderror"
       id="{{ $name }}"
       name="{{ $name }}"
       value="{{ $value ?? '' }}"
        {{ $enabled ?? "enabled" }}
        {{ $inputVar ?? '' }}/>

@error($name)
    <div class="invalid-feedback">{{ $message }}</div>
@enderror

