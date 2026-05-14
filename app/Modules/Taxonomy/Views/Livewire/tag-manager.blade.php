<div>
    <div class="card shadow-sm border-0 mb-3">
        <div class="card-header bg-light border-0 d-flex justify-content-between align-items-center">
            <h5 class="card-title mb-0 small fw-bold text-uppercase text-muted">Tags</h5>
            @if(!$model->exists)
                <span class="badge bg-warning text-dark small" style="font-size: 0.7rem;">Save first</span>
            @endif
        </div>
        <div class="card-body p-2">
            <div class="d-flex flex-wrap gap-1 mb-2">
                @forelse($attachedTags as $tag)
                    <span class="badge d-flex align-items-center gap-1" style="background-color: {{ $tag->color ?? '#6c757d' }};">
                        {{ $tag->name }}
                        <button type="button" wire:click="removeTag({{ $tag->id }})" class="btn-close btn-close-white" style="font-size: 0.5rem;" aria-label="Remove"></button>
                    </span>
                @empty
                    <span class="text-muted small px-1">No tags yet</span>
                @endforelse
            </div>

            @if($model->exists)
                <div class="position-relative mt-2">
                    <input type="text"
                        wire:model.live="search"
                        class="form-control form-control-sm border-0 bg-light"
                        placeholder="Add tag..."
                        wire:keydown.enter.prevent="createAndAddTag">

                    @if($suggestions->isNotEmpty())
                        <div class="position-absolute w-100 bg-white shadow-sm border rounded mt-1 overflow-hidden" style="z-index: 1050;">
                            @foreach($suggestions as $suggestion)
                                <button type="button"
                                    wire:click="addTag({{ $suggestion->id }})"
                                    class="btn btn-sm btn-light w-100 text-start border-0 rounded-0 py-1 px-2 d-flex align-items-center justify-content-between">
                                    <span>{{ $suggestion->name }}</span>
                                    @if($attachedTags->contains($suggestion->id))
                                        <i class="fas fa-check text-success small"></i>
                                    @endif
                                </button>
                            @endforeach

                            @php
                                $exactMatch = $suggestions->contains('name', trim($search));
                            @endphp

                            @if(!$exactMatch && !empty(trim($search)))
                                <button type="button"
                                    wire:click="createAndAddTag"
                                    class="btn btn-sm btn-primary w-100 text-start border-0 rounded-0 py-1 px-2">
                                    <i class="fas fa-plus me-1"></i> Create "{{ trim($search) }}"
                                </button>
                            @endif
                        </div>
                    @elseif(!empty(trim($search)))
                        <div class="position-absolute w-100 bg-white shadow-sm border rounded mt-1 overflow-hidden" style="z-index: 1050;">
                            <button type="button"
                                wire:click="createAndAddTag"
                                class="btn btn-sm btn-primary w-100 text-start border-0 rounded-0 py-1 px-2">
                                <i class="fas fa-plus me-1"></i> Create "{{ trim($search) }}"
                            </button>
                        </div>
                    @endif
                </div>
            @endif
        </div>
    </div>
</div>
