@extends('layouts.default_tech')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center py-3">
        <h2 class="h4 mb-0">New site</h2>
        <div>
            <a href="{{ route('tech.clients.sites.index', $client) }}" class="btn btn-sm btn-primary">Back</a>
        </div>
    </div>
@endsection

@section('content')

    @php
        // Definer logikken én gang på toppen
        $isEdit = $site->exists;
        $action = $isEdit
            ? route('tech.clients.sites.update', $site)
            : route('tech.clients.sites.store', $client);
    @endphp

    <!-- ------------------------------------------------- -->
    <!-- FORM START - Create and Edit -->
    <!-- ------------------------------------------------- -->
    <x-forms.form-default :action="$action" :button-text="$isEdit ? 'Update' : 'Save'">
        @if($isEdit)
            @method('PUT')
        @endif

            @csrf

            @if(!$isEdit && $allClients->isNotEmpty())
            <div class="row">
                <div class="col-4 mt-2">
                    <x-forms.select name="client_id" labelName="Client">
                        @foreach($allClients as $client)
                            <option value="{{ $client->id }}">{{ $client->name }}</option>
                        @endforeach
                    </x-forms.select>
                </div>
            </div>
            @else
                {{-- Skjult felt hvis klient allerede er låst --}}
                <input type="hidden" name="client_id" value="{{ $client->id ?? $site->client_id }}">
            @endif

        <div class="row">

            <!-- ------------------------------------------------- -->
            <!-- Site Name -->
            <!-- ------------------------------------------------- -->
            <div class="col-4 mt-2">
                <x-forms.input_text name="name" labelName="Site Name" value="{{$site->name ?? ''}}" inputVar="required"></x-forms.input_text>
            </div>

            <!-- ------------------------------------------------- -->
            <!-- Address -->
            <!-- ------------------------------------------------- -->
            <div class="col-4 mt-2">
                <x-forms.input_text name="address" labelName="Address" value="{{$site->address ?? ''}}"></x-forms.input_text>
            </div>

            <!-- ------------------------------------------------- -->
            <!-- Address -->
            <!-- ------------------------------------------------- -->
            <div class="col-4 mt-2">
                <x-forms.input_text name="co_address" labelName="CO Address" value="{{$site->co_address ?? ''}}"></x-forms.input_text>
            </div>

            <!-- ------------------------------------------------- -->
            <!-- Zip -->
            <!-- ------------------------------------------------- -->
            <div class="col-1 mt-2">
                <x-forms.input_text type="number" name="zip" labelName="zip" value="{{$site->zip ?? ''}}"></x-forms.input_text>
            </div>

            <!-- ------------------------------------------------- -->
            <!-- City -->
            <!-- ------------------------------------------------- -->
            <div class="col-3 mt-2">
                <x-forms.input_text name="city" labelName="City" value="{{$site->city ?? ''}}"></x-forms.input_text>
            </div>

            <!-- ------------------------------------------------- -->
            <!-- County -->
            <!-- ------------------------------------------------- -->
            <div class="col-4 mt-2">
                <x-forms.input_text name="county" labelName="County" value="{{$site->county ?? ''}}"></x-forms.input_text>
            </div>

            <!-- ------------------------------------------------- -->
            <!-- Country -->
            <!-- ------------------------------------------------- -->
            <div class="col-4 mt-2">
                <x-forms.input_text name="country" labelName="Country" value="{{$site->country ?? ''}}"></x-forms.input_text>
            </div>

        </div>

    </x-forms.form-default>
@endsection

@section('sidebar')

@endsection

@section('rightbar')
    <div class="p-3 small text-muted">Recent clients (MVP later)</div>
@endsection
