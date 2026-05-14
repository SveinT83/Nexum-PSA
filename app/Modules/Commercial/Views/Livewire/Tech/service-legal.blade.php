<div>
    <div class="row">
        @if($enabled !== 'disabled')
            <!-- Available Terms -->
            <div class="col-md-6">
                <x-card.default title="Available Terms & Legal">
                    <div class="list-group list-group-flush" style="max-height: 400px; overflow-y: auto;">
                        @foreach($this->allTerms as $term)
                            <button type="button"
                                    wire:click="toggleTerm({{ $term->id }})"
                                    class="list-group-item list-group-item-action d-flex justify-content-between align-items-center {{ in_array((string)$term->id, $selectedTermIds) ? 'active' : '' }}">
                                <span>
                                    <strong>{{ $term->name }}</strong><br>
                                    @if($term->term)
                                        <small class="{{ in_array((string)$term->id, $selectedTermIds) ? 'text-white-50' : 'text-muted' }}">
                                            <strong>Term:</strong> {{ Str::limit($term->term, 50) }}
                                        </small><br>
                                    @endif
                                    @if($term->legal)
                                        <small class="{{ in_array((string)$term->id, $selectedTermIds) ? 'text-white-50' : 'text-muted' }}">
                                            <strong>Legal:</strong> {{ Str::limit($term->legal, 50) }}
                                        </small>
                                    @endif
                                </span>
                                @if(in_array((string)$term->id, $selectedTermIds))
                                    <i class="bi bi-check-circle-fill"></i>
                                @else
                                    <i class="bi bi-plus-circle"></i>
                                @endif
                            </button>
                        @endforeach
                    </div>
                </x-card.default>
            </div>
        @endif

        <!-- Selected Terms -->
        <div class="{{ $enabled === 'disabled' ? 'col-md-12' : 'col-md-6' }}">
            <x-card.default title="Selected Terms for Service">
                <div class="list-group list-group-flush" style="max-height: 400px; overflow-y: auto;">
                    @forelse($this->selectedTerms as $term)
                        <div class="list-group-item d-flex justify-content-between align-items-center">
                            <span>
                                <strong>{{ $term->name }}</strong><br>
                                @if($term->term)
                                    <small class="text-muted"><strong>Term:</strong> {{ $enabled === 'disabled' ? $term->term : Str::limit($term->term, 50) }}</small>@if($term->legal)<br>@endif
                                @endif
                                @if($term->legal)
                                    <small class="text-muted"><strong>Legal:</strong> {{ $enabled === 'disabled' ? $term->legal : Str::limit($term->legal, 50) }}</small>
                                @endif
                            </span>
                            <div class="d-flex align-items-center">
                                <input type="hidden" name="terms[]" value="{{ $term->id }}">
                                @if($enabled !== 'disabled')
                                    <button type="button" wire:click="removeTerm({{ $term->id }})" class="btn btn-sm btn-outline-danger ms-2">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="text-center py-4 text-muted">
                            No terms selected yet.
                        </div>
                    @endforelse
                </div>
            </x-card.default>
        </div>
    </div>
</div>

