@props([
    'name',
    'id',
    'input_class' => 'form-check-input',
    'labelName' => null,
    'value' => '1',
    'type' => 'checkbox',
    'enabled' => 'enabled',
    'inputVar' => '',
    'labelClass' => 'form-check-label',
    'checked' => ''
])

<div class="form-check">
    <input class="{{$labelClass}}" name="{{$name}}" type="{{$type}}" value="{{$value}}" id="{{$id}}" {{$enabled}} {{$checked}}>
    <label class="{{$labelClass}}" for="{{$id}}">
        {{$labelName}}
    </label>
</div>
