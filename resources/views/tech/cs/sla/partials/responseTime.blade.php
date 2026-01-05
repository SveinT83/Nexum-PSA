<div class="row mt-3">
    <h2>Response Time</h2>
</div>

<div class="row">

    <!-- ------------------------------------------------- -->
    <!-- Low -->
    <!-- ------------------------------------------------- -->
    <div class="col-md-4 mb-3">
        <x-card.default title="Low priority" class="col-md-4 card">

            <!-- ------------------------------------------------- -->
            <!-- Low - Response time -->
            <!-- ------------------------------------------------- -->
            <div class="row g-3 mt-2">
                <div class="col-auto">
                    <x-forms.input_text
                        name="low_firstResponse"
                        labelName="Response time"
                        type="number"
                        value="{{$sla->low_firstResponse ?? '24'}}"
                        inputVar="{{$disabled ?? ''}}"></x-forms.input_text>

                    <div id="low_firstResponse" class="form-text">
                        Value in minutes hours or days
                    </div>
                </div>
                <div class="col-auto">
                    <x-forms.select name="low_firstResponse_type" labelName="type" enabled="{{$disabled ?? ''}}">
                        <option value="{{$sla->low_firstResponse_type ?? 'Hours'}}" selected>{{$sla->low_firstResponse_type ?? 'Hours'}}</option>
                        <option value="Minutes">Minutes</option>
                        <option value="Days">Days</option>
                    </x-forms.select>
                </div>
            </div>

            <!-- ------------------------------------------------- -->
            <!-- Low - Onsite -->
            <!-- ------------------------------------------------- -->
            <div class="row g-3 mt-2">
                <div class="col-auto">
                    <x-forms.input_text
                        name="low_onsite"
                        labelName="Onsite"
                        type="number"
                        value="{{$sla->low_onsite ?? '48'}}"
                        inputVar="{{$disabled ?? ''}}"></x-forms.input_text>

                    <div id="low_onsite" class="form-text">
                        Value in minutes hours or days
                    </div>
                </div>
                <div class="col-auto">
                    <x-forms.select name="low_onsite_type" labelName="type" enabled="{{$disabled ?? ''}}">
                        <option value="{{$sla->low_onsite_type ?? 'Hours'}}" selected>{{$sla->low_onsite_type ?? 'Hours'}}</option>
                        <option value="Minutes">Minutes</option>
                        <option value="Days">Days</option>
                    </x-forms.select>
                </div>
            </div>
        </x-card.default>
    </div>

    <!-- ------------------------------------------------- -->
    <!-- Medium -->
    <!-- ------------------------------------------------- -->
    <div class="col-md-4 mb-3">
        <x-card.default title="Medium priority / Normal" class="col-md-4 card">

            <!-- ------------------------------------------------- -->
            <!-- Medium - Responsetime -->
            <!-- ------------------------------------------------- -->
            <div class="row g-3 mt-2">
                <div class="col-auto">
                    <x-forms.input_text
                        name="medium_firstResponse"
                        labelName="Response time"
                        type="number"
                        value="{{$sla->medium_firstResponse ?? '12'}}"
                        inputVar="{{$disabled ?? ''}}"></x-forms.input_text>

                    <div id="medium_firstResponse" class="form-text">
                        Value in minutes hours or days
                    </div>
                </div>
                <div class="col-auto">
                    <x-forms.select name="medium_firstResponse_type" labelName="type" enabled="{{$disabled ?? ''}}">
                        <option value="{{$sla->medium_firstResponse_type ?? 'Hours'}}" selected>{{$sla->medium_firstResponse_type ?? 'Hours'}}</option>
                        <option value="Minutes">Minutes</option>
                        <option value="Days">Days</option>
                    </x-forms.select>
                </div>
            </div>

            <!-- ------------------------------------------------- -->
            <!-- Medium - Onsite -->
            <!-- ------------------------------------------------- -->
            <div class="row g-3 mt-2">
                <div class="col-auto">
                    <x-forms.input_text
                        name="medium_onsite"
                        labelName="Onsite"
                        type="number"
                        value="{{$sla->medium_onsite ?? '24'}}"
                        inputVar="{{$disabled ?? ''}}"></x-forms.input_text>

                    <div id="medium_firstResponse" class="form-text">
                        Value in minutes hours or days
                    </div>
                </div>
                <div class="col-auto">
                    <x-forms.select name="medium_onsite_type" labelName="type" enabled="{{$disabled ?? ''}}">
                        <option value="{{$sla->medium_onsite_type ?? 'Hours'}}" selected>{{$sla->medium_onsite_type ?? 'Hours'}}</option>
                        <option value="Minutes">Minutes</option>
                        <option value="Days">Days</option>
                    </x-forms.select>
                </div>
            </div>
        </x-card.default>
    </div>

    <!-- ------------------------------------------------- -->
    <!-- High -->
    <!-- ------------------------------------------------- -->
    <div class="col-md-4 mb-3">
        <x-card.default title="Hig priority" class="col-md-4 card">

            <!-- ------------------------------------------------- -->
            <!-- Medium - Responsetime -->
            <!-- ------------------------------------------------- -->
            <div class="row g-3 mt-2">
                <div class="col-auto">
                    <x-forms.input_text
                        name="high_firstResponse"
                        labelName="Response time"
                        type="number"
                        value="{{$sla->high_firstResponse ?? '6'}}"
                        inputVar="{{$disabled ?? ''}}"></x-forms.input_text>

                    <div id="high_firstResponse" class="form-text">
                        Value in minutes or hours
                    </div>
                </div>
                <div class="col-auto">
                    <x-forms.select name="high_firstResponse_type" labelName="type" enabled="{{$disabled ?? ''}}">
                        <option value="{{$sla->high_firstResponse_type ?? 'Hours'}}" selected>{{$sla->high_firstResponse_type ?? 'Hours'}}</option>
                        <option value="Minutes">Minutes</option>
                        <option value="Days">Days</option>
                    </x-forms.select>
                </div>
            </div>

            <!-- ------------------------------------------------- -->
            <!-- Medium - Onsite -->
            <!-- ------------------------------------------------- -->
            <div class="row g-3 mt-2">
                <div class="col-auto">
                    <x-forms.input_text
                        name="high_onsite"
                        labelName="Onsite"
                        type="number"
                        value="{{$sla->high_onsite ?? '12'}}"
                        inputVar="{{$disabled ?? ''}}"></x-forms.input_text>

                    <div id="high_onsite" class="form-text">
                        Value in minutes or hours
                    </div>
                </div>
                <div class="col-auto">
                    <x-forms.select name="high_onsite_type" labelName="type" enabled="{{$disabled ?? ''}}">
                        <option value="{{$sla->high_onsite_type ?? 'Hours'}}" selected>{{$sla->high_onsite_type ?? 'Hours'}}</option>
                        <option value="Minutes">Minutes</option>
                        <option value="Days">Days</option>
                    </x-forms.select>
                </div>
            </div>
        </x-card.default>
    </div>
</div>
