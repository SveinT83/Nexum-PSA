<div>
    <!-- Section: Live contact form with duplicate and client matching. -->
    <form wire:submit.prevent="save">
        <div class="card mb-3">
            <div class="card-header">
                <h2 class="h6 mb-0">Contact</h2>
            </div>
            <div class="card-body">
                @if($selectedExistingContact = $this->selectedExistingContact())
                    @php
                        $selectedEmail = $selectedExistingContact->emails->firstWhere('is_primary', true)?->email ?: $selectedExistingContact->emails->first()?->email;
                    @endphp
                    <div class="alert alert-info py-2 d-flex justify-content-between align-items-center gap-3">
                        <div>
                            <div class="fw-semibold">Updating existing contact</div>
                            <div class="small">{{ $selectedExistingContact->display_name }}{{ $selectedEmail ? ' · '.$selectedEmail : '' }}</div>
                        </div>
                        <span class="badge text-bg-primary">Selected</span>
                    </div>
                @elseif($duplicateMatches->isNotEmpty())
                    <div class="alert alert-warning py-2">
                        <div class="fw-semibold mb-2">Possible existing contact found</div>
                        <div class="list-group">
                            @foreach($duplicateMatches as $match)
                                @php
                                    $matchEmail = $match->emails->firstWhere('is_primary', true)?->email ?: $match->emails->first()?->email;
                                    $matchPhone = $match->phones->firstWhere('is_primary', true)?->phone ?: $match->phones->first()?->phone;
                                @endphp
                                <button type="button" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center gap-3" wire:click="selectExistingContact({{ $match->id }})">
                                    <span>
                                        <span class="fw-semibold">{{ $match->display_name }}</span>
                                        <span class="d-block small text-muted">{{ $matchEmail ?: $matchPhone ?: 'Existing contact' }}</span>
                                    </span>
                                    @if((int) $existing_contact_id === (int) $match->id)
                                        <span class="badge text-bg-primary">Selected</span>
                                    @else
                                        <span class="badge text-bg-light border">Use existing</span>
                                    @endif
                                </button>
                            @endforeach
                        </div>
                        <div class="small text-muted mt-2">Saving with a matched email or phone updates the existing contact instead of creating a duplicate.</div>
                    </div>
                @endif

                <div class="row g-3" wire:key="contact-fields-{{ $existing_contact_id ?: 'new' }}">
                    <div class="col-md-6">
                        <label for="display_name" class="form-label">Name</label>
                        <input id="display_name" type="text" class="form-control @error('display_name') is-invalid @enderror" wire:model.live.debounce.400ms="display_name" required>
                        @error('display_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6 position-relative">
                        <label for="organization_name" class="form-label">Organization / client</label>
                        <input id="organization_name" type="text" class="form-control @error('organization_name') is-invalid @enderror" wire:model.live="organization_name" placeholder="Search client or type external organization">
                        @error('organization_name')<div class="invalid-feedback">{{ $message }}</div>@enderror

                        @if($clientSuggestions->isNotEmpty())
                            <div class="list-group position-absolute start-0 end-0 mx-2 shadow-sm z-3">
                                @foreach($clientSuggestions as $client)
                                    <button type="button" class="list-group-item list-group-item-action py-2" wire:click.prevent="selectClient({{ $client->id }})" onclick="document.getElementById('organization_name').value = @js($client->name)">
                                        <span class="fw-semibold">{{ $client->name }}</span>
                                        <span class="small text-muted d-block">Create client relation</span>
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </div>
                    @if($showSiteField)
                        <div class="col-md-6 offset-md-6">
                            <label for="site_id" class="form-label">Site</label>
                            @if($activeSiteId)
                                <div class="form-control bg-light">{{ $selectedSite?->name ?: '—' }}</div>
                            @else
                                <select id="site_id" class="form-select @error('site_id') is-invalid @enderror" wire:model="site_id">
                                    <option value="">Default site</option>
                                    @foreach($siteOptions as $site)
                                        <option value="{{ $site->id }}">{{ $site->name }} @if($site->client && ! $client_id) ({{ $site->client->name }}) @endif</option>
                                    @endforeach
                                </select>
                                @error('site_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            @endif
                        </div>
                    @endif
                    <div class="col-md-6">
                        <label for="email" class="form-label">Email</label>
                        <input id="email" type="email" class="form-control @error('email') is-invalid @enderror" wire:model.live.debounce.300ms="email">
                        @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6">
                        <label for="phone" class="form-label">Phone</label>
                        <input id="phone" type="text" class="form-control @error('phone') is-invalid @enderror" wire:model.live.debounce.300ms="phone">
                        @error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6 position-relative">
                        <label for="job_title" class="form-label">Role or title</label>
                        <input id="job_title" type="text" class="form-control @error('job_title') is-invalid @enderror" wire:model.live.debounce.300ms="job_title" list="contact-title-suggestions">
                        @error('job_title')<div class="invalid-feedback">{{ $message }}</div>@enderror

                        @if($titleSuggestions->isNotEmpty())
                            <datalist id="contact-title-suggestions">
                                @foreach($titleSuggestions as $title)
                                    <option value="{{ $title }}"></option>
                                @endforeach
                            </datalist>
                        @endif
                    </div>
                    <div class="col-md-6">
                        <label for="relation_type" class="form-label">Relation</label>
                        <select id="relation_type" class="form-select @error('relation_type') is-invalid @enderror" wire:model="relation_type">
                            @foreach($relationOptions as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('relation_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex justify-content-end gap-2">
            <a href="{{ route('tech.contacts.index') }}" class="btn btn-outline-secondary btn-sm">Cancel</a>
            <button type="submit" class="btn btn-primary btn-sm">Save contact</button>
        </div>
    </form>
</div>
