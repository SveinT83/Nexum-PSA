<!-- ------------------------------------------------- -->
<!-- Label -->
<!-- ------------------------------------------------- -->
<label for=" {{$name}}" class="form-label fw-bold">{{$labelName}}</label>

<!-- ------------------------------------------------- -->
<!-- Textarea -->
<!-- ------------------------------------------------- -->
<textarea
    class="form-control @error('short_description') is-invalid @enderror"
    id="{{$name}}"
    name="{{$name}}"
    {{ $vars }}>{{ $value ?? '' }}</textarea>

<!-- ------------------------------------------------- -->
<!-- Error message -->
<!-- ------------------------------------------------- -->
@error('short_description')
    <div class="invalid-feedback">{{ $errorMsg }}</div>
@enderror
