<div>
    <div class="row">
        <!-- Available Services -->
        <div class="col-md-6">
            <x-card.default title="Available Services">
                <div class="list-group list-group-flush" style="max-height: 400px; overflow-y: auto;">
                    @foreach($this->allServices as $service)
                        <button type="button"
                                wire:click="toggleService({{ $service->id }})"
                                class="list-group-item list-group-item-action d-flex justify-content-between align-items-center {{ in_array((string)$service->id, $selectedServiceIds) ? 'active' : '' }}"
                                {{ $enabled === 'disabled' ? 'disabled' : '' }}>
                            <span>
                                <strong>{{ $service->name }}</strong><br>
                                <small class="{{ in_array((string)$service->id, $selectedServiceIds) ? 'text-white-50' : 'text-muted' }}">
                                    {{ $service->price_ex_vat }} / {{ $service->billing_cycle }}
                                </small>
                            </span>
                            @if(in_array((string)$service->id, $selectedServiceIds))
                                <i class="bi bi-check-circle-fill"></i>
                            @else
                                <i class="bi bi-plus-circle"></i>
                            @endif
                        </button>
                    @endforeach
                </div>
            </x-card.default>
        </div>

        <!-- Selected Services -->
        <div class="col-md-6">
            <x-card.default title="Selected Services in Package">
                <div class="list-group list-group-flush" style="max-height: 400px; overflow-y: auto;">
                    @forelse($this->selectedServices as $service)
                        <div class="list-group-item d-flex justify-content-between align-items-center">
                            <span>
                                <strong>{{ $service->name }}</strong><br>
                                <small class="text-muted">{{ $service->price_ex_vat }} / {{ $service->billing_cycle }}</small>
                            </span>
                            <div class="d-flex align-items-center">
                                <input type="hidden" name="services[]" value="{{ $service->id }}">
                                @if($enabled !== 'disabled')
                                    <button type="button" wire:click="removeService({{ $service->id }})" class="btn btn-sm btn-outline-danger ms-2">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="text-center py-4 text-muted">
                            No services selected yet.
                        </div>
                    @endforelse
                </div>

                @if($this->selectedServices->isNotEmpty())
                    <div class="mt-3 p-2 bg-light rounded">
                        <strong>Total Monthly:</strong> {{ number_format($this->selectedServices->where('billing_cycle', 'monthly')->sum('price_ex_vat'), 2) }}<br>
                        <strong>Total One-time:</strong> {{ number_format($this->selectedServices->sum('one_time_fee'), 2) }}
                    </div>
                @endif
            </x-card.default>
        </div>
    </div>
</div>
