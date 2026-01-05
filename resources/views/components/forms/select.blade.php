<!-- ------------------------------------------------- -->
<!-- Label -->
<!-- ------------------------------------------------- -->
<label for="{{$name}}" class="form-label fw-bold">{{$labelName}}</label>

<!-- ------------------------------------------------- -->
<!-- Select -->
<!-- ------------------------------------------------- -->
<select class="form-select @error($name) is-invalid @enderror"
        id="{{$name}}"
        name="{{$name}}"
        {{ $enabled ?? 'enabled' }}>

    {{ $slot }}
</select>

<!-- ------------------------------------------------- -->
<!-- Error message -->
<!-- ------------------------------------------------- -->
@error($name)
<div class="invalid-feedback">{{ $message }}</div>
@enderror
