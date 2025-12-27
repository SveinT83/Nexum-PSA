<!-- resources/views/components/forms/form-default.blade.php -->

<!-- Usage: action=your_action_route buttonText=Submit -->



<form class="pt-3 pb-3" method="{{$method ?? 'post'}}" action="{{ $action }}">
    @csrf <!-- CSRF token for security -->
    {{ $slot }}

    <button type="submit" class="btn btn-primary">{{$buttonText}}</button>
</form>
