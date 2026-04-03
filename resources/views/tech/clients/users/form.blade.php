@extends('layouts.default_tech')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center py-3">
        <h2 class="h4 mb-0">New user for: {{ $client->name }} {{ $activeSite->name ?? ''}}</h2>
        <div>
            <a href="{{ route('tech.clients.index') }}" class="btn btn-sm btn-outline-secondary">Back</a>
        </div>
    </div>
@endsection

@section('content')

    <x-forms.form-default class="container-sm" action="#">

        <!-- ------------------------------------------------- -->
        <!-- Name -->
        <!-- ------------------------------------------------- -->
        <div class="row mt-3">
            <x-forms.input_text labelName="Name*" layout="vertical" name="name" inputVar="required" value="{{ old('name') }}" />
        </div>

        <!-- ------------------------------------------------- -->
        <!-- E-mail -->
        <!-- ------------------------------------------------- -->
        <div class="row mt-3">
                <x-forms.input_text name="email" labelName="E-mail*" layout="vertical" type="mail" inputVar="required" value="{{ old('email') }}" />
        </div>

        <!-- ------------------------------------------------- -->
        <!-- Phone -->
        <!-- ------------------------------------------------- -->
        <div class="row mt-3">
            <x-forms.input_text name="phone" labelName="Phone" layout="vertical" type="tel" value="{{ old('email') }}" />
        </div>

        <!-- ------------------------------------------------- -->
        <!-- Role -->
        <!-- ------------------------------------------------- -->
        <div class="row mt-3">
                <x-forms.input_text name="role" labelName="Role" layout="vertical" value="{{ old('role') }}"> </x-forms.input_text>
        </div>

            <!-- ------------------------------------------------- -->
            <!-- Site / Sites -->
            <!-- ------------------------------------------------- -->
        <div class="row mt-3">
            <x-forms.select name="client_site_id" layout="vertical" labelName="Site*"  inputVar="required">

                <!-- Active Site -->
                @if(isset($activeSite))
                    <option value="{{ $activeSite->id }}">{{ $activeSite->name }}</option>
                @else
                    <option value="">Choose an site</option>
                @endif

                <!-- Site options -->
                @foreach($sites as $site)
                    <option value="{{ $site->id }}">{{ $site->name }}</option>
                @endforeach

            </x-forms.select>
        </div>


        <!-- ------------------------------------------------- -->
        <!-- Address -->
        <!-- ------------------------------------------------- -->
        <div class="row mt-3">
            <b class="col-md-3">Address</b>
            <div class="col">
                <x-forms.input_text name="address" value="{{ old('address') }}"></x-forms.input_text>
                <x-forms.input_text name="co_address" input_class="mt-2" placeholder="Co Adress" value="{{ old('co_address') }}"></x-forms.input_text>
            </div>
        </div>

        <!-- ------------------------------------------------- -->
        <!-- Zip Code -->
        <!-- ------------------------------------------------- -->
        <div class="row mt-3">
            <x-forms.input_text name="zip" layout="vertical" input_class="col-md-2" type="number" labelName="Zip" value="{{ old('zip') }}"></x-forms.input_text>
        </div>

        <!-- ------------------------------------------------- -->
        <!-- City -->
        <!-- ------------------------------------------------- -->
        <div class="row mt-3">
            <x-forms.input_text name="city" layout="vertical" labelName="City" value="{{ old('city') }}"></x-forms.input_text>
        </div>

        <!-- ------------------------------------------------- -->
        <!-- County -->
        <!-- ------------------------------------------------- -->
        <div class="row mt-3">
            <x-forms.input_text name="county" layout="vertical" labelName="County" value="{{ old('county') }}"></x-forms.input_text>
        </div>

        <!-- ------------------------------------------------- -->
        <!-- Country -->
        <!-- ------------------------------------------------- -->
        <div class="row mt-3">
            <x-forms.input_text name="country" labelName="Country" layout="vertical" value="{{ old('country') }}"></x-forms.input_text>
        </div>

        <!-- ------------------------------------------------- -->
        <!-- Langue -->
        <!-- ------------------------------------------------- -->
        <div class="row mt-3">
            <x-forms.input_text name="langue" labelName="Langue" layout="vertical" value="{{ old('langue') }}"></x-forms.input_text>
        </div>

    </x-forms.form-default>

@endsection

@section('sidebar')
@endsection

@section('rightbar')
    <div class="p-3 small text-muted">Recent clients (MVP later)</div>
@endsection
