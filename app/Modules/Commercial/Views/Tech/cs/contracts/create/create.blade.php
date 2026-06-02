@extends('layouts.default_tech')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center">
        <h1>{{ isset($contract) ? 'Edit Contract' : 'New Contract' }}</h1>
        <div>
            <x-buttons.back url="{{ route('tech.contracts.index') }}" class="mb-0">Back</x-buttons.back>
        </div>
    </div>
@endsection

@section('content')

    <form action="{{ isset($contract) ? route('tech.contracts.update', $contract) : route('tech.contracts.store') }}" method="POST">

        <!-- Token -->
        @csrf

        @if(isset($contract))
            @method('PUT')
        @endif

        <!-- ------------------------------------------------- -->
        <!-- Client information -->
        <!-- ------------------------------------------------- -->
        <x-card.default title="Client information" >
            <div class="row mt-3 mb-3">

                <!-- ------------------------------------------------- -->
                <!-- Clients -->
                <!-- Shows all clients as options in an select and if active client in session -->
                <!-- that client is selected by default -->
                <!-- ------------------------------------------------- -->
                <div class="col-md-6">
                    <x-forms.select name="client_id" labelName="Client">

                        <!-- Show active client if set -->
                        @if (isset($activeClient))
                            <option value="{{ $activeClient->id }}" selected>{{ $activeClient->name }}</option>
                        @endif

                        <option value="">Select Client</option>

                        <!-- Show all clients -->
                        @foreach ($clients as $client)
                            @if(!isset($activeClient) || $client->id != $activeClient->id)
                                <option value="{{ $client->id }}">{{ $client->name }}</option>
                            @endif
                        @endforeach
                    </x-forms.select>
                </div>

                <!-- ------------------------------------------------- -->
                <!-- Created By / Contact person -->
                <!-- ------------------------------------------------- -->
                <div class="col-md-6">
                    <x-forms.select name="created_by" labelName="Technician">
                        <option value="">Select Technician</option>
                        <!-- Show all Technicians -->
                        @foreach ($technicians as $technician)
                            <option value="{{ $technician->id }}" {{ (isset($contract) && $contract->created_by == $technician->id) ? 'selected' : '' }}>{{ $technician->name }}</option>
                        @endforeach
                    </x-forms.select>
                </div>

                <!-- ------------------------------------------------- -->
                <!-- SLA Policy -->
                <!-- Selects the structured SLA profile inherited by new tickets for this contract. -->
                <!-- ------------------------------------------------- -->
                <div class="col-md-6 mt-3">
                    <x-forms.select name="sla_id" labelName="SLA Policy">
                        <option value="">Use current system default SLA</option>
                        @foreach($slas as $sla)
                            <option value="{{ $sla->id }}" {{ (int) old('sla_id', $contract->sla_id ?? 0) === $sla->id ? 'selected' : '' }}>
                                {{ $sla->name }}{{ $sla->is_default ? ' (default)' : '' }}
                            </option>
                        @endforeach
                    </x-forms.select>
                    <div class="form-text">
                        Leave blank when this contract should follow the active default SLA policy. Select a policy to lock this contract to that SLA.
                    </div>
                </div>

            </div>
        </x-card.default>

        <!-- ------------------------------------------------- -->
        <!-- Contract Description -->
        <!-- ------------------------------------------------- -->
        <x-card.default title="Description" >

            <x-forms.textarea name="description" labelName="Comments to the contract" value="{{ isset($contract) ? $contract->description : '' }}" />
        </x-card.default>

        <!-- ------------------------------------------------- -->
        <!-- Contract Period -->
        <!-- ------------------------------------------------- -->
        <x-card.default title="Contract period" >

            <div class="row">

                <!-- Start Date -->
                <div class="col-md-4">
                    <x-forms.input_text name="start_date" label-name="Start date" type="date" value="{{ $startDate }}"></x-forms.input_text>
                </div>

                <!-- End Date -->
                <div class="col-md-4">
                    <x-forms.input_text name="end_date" label-name="End date" type="date" value="{{ $endDate }}"></x-forms.input_text>
                </div>

                <!-- Binding End Date -->
                <div class="col-md-4">
                    <x-forms.input_text name="binding_end_date" label-name="Binding end date" type="date" value="{{ $bindingEndDate }}"></x-forms.input_text>
                </div>

            </div>
        </x-card.default>

        <!-- ------------------------------------------------- -->
        <!-- Renewal -->
        <!-- ------------------------------------------------- -->
        <x-card.default title="Contract period">

            <div class="row align-items-end">

                <!-- Auto renew checkbox -->
                <div class="col-md-4">
                    <x-forms.checkbox name="auto_renew" labelName="Auto-renew" id="auto_renew"  checked="{{ (isset($contract) ? $contract->auto_renew : true) ? 'checked' : '' }}"/>
                </div>

                <!-- Renewals Months -->
                <div class="col-md-4">
                    <x-forms.select name="renewal_months" labelName="Renewal Months">
                        @for($i = 1; $i <= 12; $i++)
                            <option value="{{ $i }}" {{ (isset($contract) ? $contract->renewal_months : 3) == $i ? 'selected' : '' }}>{{ $i }}</option>
                        @endfor
                    </x-forms.select>
                </div>

            </div>
        </x-card.default>

        <!-- ------------------------------------------------- -->
        <!-- Indexing Policy -->
        <!-- ------------------------------------------------- -->
        <x-card.default title="Contract period">
            <div class="row align-items-end">

                <div class="col-md-4">

                    <!-- Allow indexing during binding checkbox -->
                    <x-forms.checkbox name="allow_indexing_during_binding" labelName="Allow indexing during binding" id="allow_indexing_during_binding" checked="{{ (isset($contract) ? $contract->allow_indexing_during_binding : true) ? 'checked' : '' }}" />

                    <!-- Allow decrese during binding checkbox -->
                    <x-forms.checkbox name="allow_decrease_during_binding" labelName="Allow decrese during binding" id="allow_decrease_during_binding" checked="{{ (isset($contract) && $contract->allow_decrease_during_binding) ? 'checked' : '' }}"/>
                </div>

                <!-- Max index price index during binding -->
                <div class="col-md-4">
                    <label for="max_index_pct_binding" class="form-label fw-bold">Max index price during binding</label>

                    <div class="input-group">
                        <input type="text" name="max_index_pct_binding" class="form-control" value="{{ isset($contract) ? $contract->max_index_pct_binding : '3.5' }}">
                        <span class="input-group-text" id="max_index_pct_binding">%</span>
                    </div>
                </div>

                <!-- Max index price index after binding -->
                <div class="col-md-4">
                    <label for="post_binding_index_pct" class="form-label fw-bold">Max index price after binding</label>

                    <div class="input-group">
                        <input type="text" name="post_binding_index_pct" class="form-control" value="{{ isset($contract) ? $contract->post_binding_index_pct : '10' }}">
                        <span class="input-group-text" id="post_binding_index_pct">%</span>
                    </div>
                </div>

            </div>
        </x-card.default>

        <!-- Row whit button -->
        <div class="row mt-3">
            <div class="col-md-12">
                <button type="submit" class="btn btn-primary">{{ isset($contract) ? 'Update Contract' : 'Create Contract' }}</button>
            </div>
        </div>

    </form>
@endsection

@section('sidebar')
    <x-nav.sales-menu />
@endsection

@section('rightbar')

@endsection
