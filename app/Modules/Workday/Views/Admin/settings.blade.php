@extends('layouts.default_tech')
@section('title', 'Workday settings')
@section('pageHeader')
    <div class="col"><h1 class="h4 mb-0">Workday settings</h1></div>
@endsection
@section('content')
    {{-- Activation and fixed retention contract --}}
    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <div class="card"><div class="card-body">
        <p>Employees record and confirm their own actual work. Manager approval and billing are separate workflows.</p>
        <p>Current availability: <strong>{{ $settings['effective_enabled'] ? 'Enabled' : 'Disabled' }}</strong>.
            @if(!$settings['deployment_enabled'])The deployment switch is off. Saving this setting does not open employee access.@endif</p>
        <form method="POST" action="{{ route('tech.admin.settings.workday.update') }}">
            @csrf @method('PATCH')
            <input type="hidden" name="version" value="{{ $settings['version'] }}">
            <input type="hidden" name="request_key" value="{{ \Illuminate\Support\Str::uuid() }}">
            <input type="hidden" name="enabled" value="0">
            <div class="form-check mb-3"><input class="form-check-input" id="enabled" name="enabled" type="checkbox" value="1" @checked($settings['enabled'])>
                <label class="form-check-label" for="enabled">Enable employee workdays when deployment activation is approved</label></div>
            <p>Work and absence retention: three years from the original work date or absence-period end. Corrections do not extend this period. Cleanup has its own server activation setting and can run while employee access is disabled.</p>
            <button class="btn btn-primary" type="submit">Save settings</button>
        </form>
    </div></div>
    {{-- Metadata-only preview; deletion is an operational server action. --}}
    <section class="card mt-3"><div class="card-body">
        <h2 class="h5">Retention</h2>
        <p>Inspect expired records before the separately approved cleanup is activated.</p>
        <form method="POST" action="{{ route('tech.admin.settings.workday.retention-preview') }}">@csrf<button class="btn btn-outline-primary" type="submit">Preview expired records</button></form>
    </div></section>
@endsection
