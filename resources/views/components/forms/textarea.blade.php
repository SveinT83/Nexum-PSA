<!-- ------------------------------------------------- -->
<!-- Label -->
<!-- ------------------------------------------------- -->
<label for=" {{$name}}" class="form-label fw-bold">{{$labelName}}</label>

<!-- ------------------------------------------------- -->
<!-- Textarea -->
<!-- ------------------------------------------------- -->
<textarea
    class="form-control @error($name) is-invalid @enderror"
    id="{{$name}}"
    name="{{$name}}"
    {{ $vars ?? '' }}>{{ $value ?? '' }} {{ $slot }}</textarea>

<!-- ------------------------------------------------- -->
<!-- Error message -->
<!-- ------------------------------------------------- -->
@error($name)
    <div class="invalid-feedback">{{ $message }}</div>
@enderror
