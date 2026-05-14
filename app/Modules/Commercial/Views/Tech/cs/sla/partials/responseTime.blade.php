<div class="d-flex align-items-center justify-content-between mb-2">
    <h3 class="h6 mb-0">Response Time</h3>
    <div class="small text-muted">First response and onsite targets by priority</div>
</div>

<div class="row g-3">

    <!-- ------------------------------------------------- -->
    <!-- Low -->
    <!-- ------------------------------------------------- -->
    <div class="col-md-4">
        <article class="card h-100">
            <div class="card-header d-flex align-items-center justify-content-between py-2">
                <h4 class="h6 mb-0">
                    <i class="bi bi-shield-check text-success" aria-hidden="true"></i>
                    Low
                </h4>
                <span class="badge text-bg-light border">Default 24 Hours</span>
            </div>
            <div class="card-body py-3">

                <!-- ------------------------------------------------- -->
                <!-- Low - Response time -->
                <!-- ------------------------------------------------- -->
                <div class="row g-2">
                    <div class="col-7">
                        <x-forms.input_text
                            name="low_firstResponse"
                            labelName="Response time"
                            type="number"
                            value="{{$sla->low_firstResponse ?? '24'}}"
                            inputVar="{{$disabled ?? ''}}"></x-forms.input_text>
                    </div>
                    <div class="col-5">
                        <x-forms.select name="low_firstResponse_type" labelName="Type" enabled="{{$disabled ?? ''}}">
                            <option value="{{$sla->low_firstResponse_type ?? 'Hours'}}" selected>{{$sla->low_firstResponse_type ?? 'Hours'}}</option>
                            <option value="Minutes">Minutes</option>
                            <option value="Days">Days</option>
                        </x-forms.select>
                    </div>
                </div>

                <!-- ------------------------------------------------- -->
                <!-- Low - Onsite -->
                <!-- ------------------------------------------------- -->
                <div class="row g-2 mt-2">
                    <div class="col-7">
                        <x-forms.input_text
                            name="low_onsite"
                            labelName="Onsite"
                            type="number"
                            value="{{$sla->low_onsite ?? '48'}}"
                            inputVar="{{$disabled ?? ''}}"></x-forms.input_text>
                    </div>
                    <div class="col-5">
                        <x-forms.select name="low_onsite_type" labelName="Type" enabled="{{$disabled ?? ''}}">
                            <option value="{{$sla->low_onsite_type ?? 'Hours'}}" selected>{{$sla->low_onsite_type ?? 'Hours'}}</option>
                            <option value="Minutes">Minutes</option>
                            <option value="Days">Days</option>
                        </x-forms.select>
                    </div>
                </div>
            </div>
        </article>
    </div>

    <!-- ------------------------------------------------- -->
    <!-- Medium -->
    <!-- ------------------------------------------------- -->
    <div class="col-md-4">
        <article class="card h-100">
            <div class="card-header d-flex align-items-center justify-content-between py-2">
                <h4 class="h6 mb-0">
                    <i class="bi bi-shield-exclamation text-warning" aria-hidden="true"></i>
                    Medium
                </h4>
                <span class="badge text-bg-light border">Default 12 Hours</span>
            </div>
            <div class="card-body py-3">

            <!-- ------------------------------------------------- -->
            <!-- Medium - Response time -->
            <!-- ------------------------------------------------- -->
            <div class="row g-2">
                <div class="col-7">
                    <x-forms.input_text
                        name="medium_firstResponse"
                        labelName="Response time"
                        type="number"
                        value="{{$sla->medium_firstResponse ?? '12'}}"
                        inputVar="{{$disabled ?? ''}}"></x-forms.input_text>
                </div>
                <div class="col-5">
                    <x-forms.select name="medium_firstResponse_type" labelName="Type" enabled="{{$disabled ?? ''}}">
                        <option value="{{$sla->medium_firstResponse_type ?? 'Hours'}}" selected>{{$sla->medium_firstResponse_type ?? 'Hours'}}</option>
                        <option value="Minutes">Minutes</option>
                        <option value="Days">Days</option>
                    </x-forms.select>
                </div>
            </div>

            <!-- ------------------------------------------------- -->
            <!-- Medium - Onsite -->
            <!-- ------------------------------------------------- -->
            <div class="row g-2 mt-2">
                <div class="col-7">
                    <x-forms.input_text
                        name="medium_onsite"
                        labelName="Onsite"
                        type="number"
                        value="{{$sla->medium_onsite ?? '24'}}"
                        inputVar="{{$disabled ?? ''}}"></x-forms.input_text>
                </div>
                <div class="col-5">
                    <x-forms.select name="medium_onsite_type" labelName="Type" enabled="{{$disabled ?? ''}}">
                        <option value="{{$sla->medium_onsite_type ?? 'Hours'}}" selected>{{$sla->medium_onsite_type ?? 'Hours'}}</option>
                        <option value="Minutes">Minutes</option>
                        <option value="Days">Days</option>
                    </x-forms.select>
                </div>
            </div>
            </div>
        </article>
    </div>

    <!-- ------------------------------------------------- -->
    <!-- High -->
    <!-- ------------------------------------------------- -->
    <div class="col-md-4">
        <article class="card h-100">
            <div class="card-header d-flex align-items-center justify-content-between py-2">
                <h4 class="h6 mb-0">
                    <i class="bi bi-shield-fill-exclamation text-danger" aria-hidden="true"></i>
                    High
                </h4>
                <span class="badge text-bg-light border">Default 6 Hours</span>
            </div>
            <div class="card-body py-3">

            <!-- ------------------------------------------------- -->
            <!-- High - Response time -->
            <!-- ------------------------------------------------- -->
            <div class="row g-2">
                <div class="col-7">
                    <x-forms.input_text
                        name="high_firstResponse"
                        labelName="Response time"
                        type="number"
                        value="{{$sla->high_firstResponse ?? '6'}}"
                        inputVar="{{$disabled ?? ''}}"></x-forms.input_text>
                </div>
                <div class="col-5">
                    <x-forms.select name="high_firstResponse_type" labelName="Type" enabled="{{$disabled ?? ''}}">
                        <option value="{{$sla->high_firstResponse_type ?? 'Hours'}}" selected>{{$sla->high_firstResponse_type ?? 'Hours'}}</option>
                        <option value="Minutes">Minutes</option>
                        <option value="Days">Days</option>
                    </x-forms.select>
                </div>
            </div>

            <!-- ------------------------------------------------- -->
            <!-- High - Onsite -->
            <!-- ------------------------------------------------- -->
            <div class="row g-2 mt-2">
                <div class="col-7">
                    <x-forms.input_text
                        name="high_onsite"
                        labelName="Onsite"
                        type="number"
                        value="{{$sla->high_onsite ?? '12'}}"
                        inputVar="{{$disabled ?? ''}}"></x-forms.input_text>
                </div>
                <div class="col-5">
                    <x-forms.select name="high_onsite_type" labelName="Type" enabled="{{$disabled ?? ''}}">
                        <option value="{{$sla->high_onsite_type ?? 'Hours'}}" selected>{{$sla->high_onsite_type ?? 'Hours'}}</option>
                        <option value="Minutes">Minutes</option>
                        <option value="Days">Days</option>
                    </x-forms.select>
                </div>
            </div>
            </div>
        </article>
    </div>
</div>
