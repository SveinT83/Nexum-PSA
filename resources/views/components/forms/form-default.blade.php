<!-- resources/views/components/forms/form-default.blade.php -->

@props([
    'action',
    'method',
    'class'=>'mt-3 mb-3',
])

<!-- Usage: action=your_action_route buttonText=Submit -->



<form class="{{$class}}" method="{{$method ?? 'post'}}" action="{{ $action}}">
    @csrf <!-- CSRF token for security -->
    {{ $slot }}

    <button type="submit" class="btn btn-primary mt-3">{{$buttonText ?? 'Send'}}</button>
</form>
