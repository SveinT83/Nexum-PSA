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

# tech.cs.contracts.create – View Specification

**Date:** 2025-10-17
**URL:** `tech.cs.contracts.create` → `/tech/cs/contracts/create`
**Access levels:** `contract.create`, `contract.admin`, `tech.admin`, `superuser`
**Controller:** `App\\Http\\Controllers\\Tech\\CS\\ContractsController@create` (GET) / `@store` (POST)
**Status:** Not completed
**Difficulty:** High
**Estimated time:** 5.0 hours

---

## Purpose

Create a new **client-specific contract**, define its terms, binding, and pricing. The contract acts as a snapshot of chosen services with overrideable fields. The process ensures contract stability while allowing flexible pricing, auto-renewal, and indexation control.

---

## Design & Layout (Bootstrap)

**Template regions:** Top header / Main content / Right slim rail.
**Icons (suggested):** plus-circle, layers, tag, percent, calendar, file-text, refresh-cw, check-circle, alert-triangle, save.

* **Top header**

  * Title: “Create Contract”
  * Buttons: `Save`, `Cancel`, `Preview PDF`
  * Display auto-assigned ID (starts at 10000; generated on save)

* **Main content (fieldsets)**

  1. **Client Selection**

     * Dropdown/autocomplete (search clients)
     * Once selected, locks client for the remainder of the session.
  2. **Contract Period**

     * Start date / End date
     * Binding enabled (checkbox) + Binding duration (months)
     * Auto-renew toggle + Renewal duration (months)
     * “Floating allowed after binding” (checkbox)
  3. **Indexing Policy**

     * Allow price indexing during binding (checkbox)
     * Max indexing % (numeric)
     * Post-binding index % (optional field)
     * Apply decreases during binding (checkbox)
  4. **Add Services** (core section)

     * Button: `Add Service` (opens right-side drawer)
     * Flat table showing added services with key info:

       * Name, SKU (read-only), Billing interval, Unit price, SLA, Caps, Discount, Setup fee
       * Row actions: Edit (drawer), Remove, Duplicate
     * Totals recalculated live (unit price × interval normalization)
  5. **Terms & Legal**

     * Terms preview compiled dynamically from selected services + global defaults
     * Editable rich text for additional clauses (contract-level)
     * Internal notes (non-public)

* **Right slim rail**

  * **SummaryCard**: live totals (monthly equivalent), binding end, next renewal
  * **PolicySummaryCard**: highlights binding/indexing selections
  * **AuditInfo**: shows user creating + timestamp
  * **Save actions**: `Save Draft`, `Save & Send for Approval`

---

## Components (Livewire)

* **`ContractCreator`** – orchestrates the full workflow; holds client, terms, totals.
* **`ServiceAddPanel`** – side drawer for adding one service at a time (search existing, configure overrides, add).
* **`ContractTotals`** – reactive computation of totals based on service lines.
* **`IndexingPanel`** – handles both binding and post-binding index rules.
* **`BindingDurationPanel`** – computes binding end and renewal markers.
* **`ContractTermsEditor`** – merges service-level terms and allows inline additions.
* **`ApprovalOptions`** – define approval path (send email link, internal record).

Reusable: shared number/date inputs, client autocomplete, table component.

---

## Behaviors & Validation

* **Client** must be selected before services can be added.
* **Service addition** uses right-side drawer → adds line with snapshot fields.
* **Live recalculation** of totals as services are added/edited/removed.
* **Binding validation**: if binding enabled, duration > 0 required.
* **Indexing rules**: allow/decrease toggles depend on policy in settings.
* **Duplicate prevention**: identical service/SKU cannot be added twice unless multi-instance allowed.
* **Save as Draft** allowed anytime; unapproved contracts can be reopened.
* **Approval flow**: sending triggers email with approve/decline link (7-day expiry, configurable).
* **Cancellation of approval** invalidates prior link.

---

## Right Rail Widgets

* **Totals Summary**: monthly/annualized cost, included services count
* **Indexing Health**: displays max allowed % and whether decreases apply
* **Renewal Overview**: renewal rule and next review date
* **Checklist Widget**: visual completeness indicator (client chosen, services added, binding valid, terms ready)

---

## Permissions

* View client list: `client.view`
* Create: `contract.create`
* Run indexing immediately after create (manual): `contract.admin | tech.admin | superuser`
* Send for approval: `contract.create | contract.admin`

---

## Audit & Notifications

* Audit log on create with all major fields (client, binding, indexing, totals, services snapshot).
* Optional notification to admin when new draft created.
* Approval email: logs IP, time, and approver metadata.

---

## Edge Cases

* Start date > end date → validation error.
* Adding service missing SKU → blocked until fixed in catalog.
* Attempting to bind for 0 months → error.
* Duplicate service in same contract → blocked unless multi-instance flag set.

---

## QA Scenarios (high level)

* Create contract for a client with binding 12 months and 3 services; totals update live.
* Disable binding; verify floating option toggles on.
* Set max indexing % and post-binding index %; verify live preview in rail.
* Send for approval; contract becomes locked; editing blocked until canceled.
* Add service with discount and verify monthly recalculation reflects reduction.

---

## Notes

* No HTML or code here—only logical structure and UI definition.
* Dashboard/layout static; all dynamic content handled via Livewire.
* Consistent top/main/rail layout with other tech.cs modules.
@endsection

@section('sidebar')
    <x-nav.sales-menu />
@endsection

@section('rightbar')

@endsection
