@if(($customFields ?? collect())->isNotEmpty())
    <!-- ------------------------------------------------- -->
    <!-- Ticket custom fields -->
    <!-- Active, authorized definitions share the established Custom Field inputs and one Ticket save action. -->
    <!-- ------------------------------------------------- -->
    <x-card.default title="Custom fields">
        <div class="row g-3">
            @foreach($customFields as $field)
                @php($definition = $field['definition'])
                <div class="col-md-6">
                    <label class="form-label" for="ticketCustomField{{ $definition->id }}">
                        {{ $field['label'] }}
                        @if($field['required'])
                            <span class="text-danger" aria-hidden="true">*</span>
                            <span class="visually-hidden">Required</span>
                        @endif
                    </label>

                    @include('customfield::components.value-input', [
                        'field' => $field,
                        'inputName' => 'custom_fields['.$field['key'].']',
                        'inputId' => 'ticketCustomField'.$definition->id,
                    ])

                    @error('custom_fields.'.$field['key'])
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                </div>
            @endforeach
        </div>
    </x-card.default>
@endif
