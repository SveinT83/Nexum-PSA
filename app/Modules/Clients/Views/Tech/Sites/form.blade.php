@extends('layouts.default_tech')

@php
    // Keep create and edit behavior in one template while exposing clear UI copy.
    $isEdit = $site->exists;
    $formTitle = $isEdit ? 'Edit Site' : 'Create Site';
    $action = $isEdit
        ? route('tech.clients.sites.update', $site)
        : route('tech.clients.sites.store', $client);
@endphp

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center">
        <h1>{{ $isEdit ? ($site->name ?? 'Site') : 'New Site' }}</h1>
        <div>
            @if(isset($client))
                <x-buttons.back url="{{ route('tech.clients.show', $client->id) }}" class="mb-0">Back</x-buttons.back>
            @else
                <x-buttons.back url="{{ route('tech.clients.sites.index') }}" class="mb-0">Back</x-buttons.back>
            @endif
        </div>
    </div>
@endsection

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- FORM START - Create and Edit -->
    <!-- ------------------------------------------------- -->
    <div class="card">
        <div class="card-header">
            <h2 class="h5 mb-0">{{ $formTitle }}</h2>
        </div>
        <div class="card-body">
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
                    <!-- Sites Name -->
                    <!-- ------------------------------------------------- -->
                    <div class="col-4 mt-2">
                        <x-forms.input_text name="name" labelName="Site Name" value="{{$site->name ?? ''}}"
                                            inputVar="required"></x-forms.input_text>
                    </div>

                    <!-- ------------------------------------------------- -->
                    <!-- Address -->
                    <!-- ------------------------------------------------- -->
                    <div class="col-4 mt-2">
                        <x-forms.input_text name="address" labelName="Address"
                                            value="{{$site->address ?? ''}}"></x-forms.input_text>
                    </div>

                    <!-- ------------------------------------------------- -->
                    <!-- Address -->
                    <!-- ------------------------------------------------- -->
                    <div class="col-4 mt-2">
                        <x-forms.input_text name="co_address" labelName="CO Address"
                                            value="{{$site->co_address ?? ''}}"></x-forms.input_text>
                    </div>

                    <!-- ------------------------------------------------- -->
                    <!-- Zip -->
                    <!-- ------------------------------------------------- -->
                    <div class="col-1 mt-2">
                        <x-forms.input_text type="number" name="zip" labelName="zip"
                                            value="{{$site->zip ?? ''}}"></x-forms.input_text>
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
                        <x-forms.input_text name="county" labelName="County"
                                            value="{{$site->county ?? ''}}"></x-forms.input_text>
                    </div>

                    <!-- ------------------------------------------------- -->
                    <!-- Country -->
                    <!-- ------------------------------------------------- -->
                    <div class="col-4 mt-2">
                        <x-forms.input_text name="country" labelName="Country"
                                            value="{{$site->country ?? ''}}"></x-forms.input_text>
                    </div>

                    @php
                        $rmmIntegration = \App\Models\System\Integrations\Integration::where('type', 'rmm')->where('status', 'active')->first();
                        $isLinkedToRmm = false;
                        if ($rmmIntegration && isset($client)) {
                            $isLinkedToRmm = $client->rmmLinks()->where('integration_id', $rmmIntegration->id)->exists();
                        }
                    @endphp
                    @if(!$isEdit && ($nableActive ?? false) && $isLinkedToRmm)
                        <div class="col-12 mt-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" value="1" name="create_in_rmm"
                                       id="rmmCheck" {{ old('create_in_rmm') ? 'checked' : '' }}>
                                <label class="form-check-label" for="rmmCheck">
                                    Create in N-able RMM
                                </label>
                            </div>
                        </div>
                    @endif

                </div>

            </x-forms.form-default>
        </div>
    </div>
@endsection

@section('sidebar')
    @if(isset($sidebarMenuItems))
        <x-nav.side-bar :items="$sidebarMenuItems" title="Client workspace" />
    @endif
@endsection

@section('rightbar')
@endsection
