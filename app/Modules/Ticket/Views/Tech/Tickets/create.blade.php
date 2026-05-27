@extends('layouts.default_tech')

@section('title', 'New ticket')

@section('pageName')
    <h3>Tickets</h3>
@endsection

<!-- -------------------------------------------------------------------------------------------------- -->
<!-- Page header -->
<!-- Contains the create-page title and the shared back button component. -->
<!-- -------------------------------------------------------------------------------------------------- -->
@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center">
        <h1 class="mb-0">New ticket</h1>
        <x-buttons.back url="{{ route('tech.tickets.index') }}">Back</x-buttons.back>
    </div>
@endsection

@section('content')
<div class="container-fluid px-0">

    <!-- -------------------------------------------------------------------------------------------------- -->
    <!-- Create ticket form -->
    <!-- Posts normalized ticket data to the Ticket module store action. -->
    <!-- -------------------------------------------------------------------------------------------------- -->
    <form method="POST" action="{{ route('tech.tickets.store') }}">
        @csrf

        <!-- ------------------------------------------------- -->
        <!-- Channel -->
        <!-- Manual ticket creation always uses the manual channel; imported/inbound flows set other channels elsewhere. -->
        <!-- ------------------------------------------------- -->
        <input type="hidden" name="channel" value="manual">

        <!-- ------------------------------------------------- -->
        <!-- First row -->
        <!-- Main ticket content. Keep this wide because it is the primary work area for technicians. -->
        <!-- ------------------------------------------------- -->
        <div class="row">
            <div class="col-lg-12">

                <!-- -------------------------------------------------------------------------------------------------- -->
                <!-- Create ticket card -->
                <!-- Captures the human-readable problem statement and optional initial internal note. -->
                <!-- -------------------------------------------------------------------------------------------------- -->
                <x-card.default title="Ticket details">
                    <div class="mb-3">
                        <label for="subject" class="form-label">Subject</label>
                        <input id="subject" name="subject" type="text" class="form-control @error('subject') is-invalid @enderror" value="{{ old('subject') }}" required>
                        @error('subject')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-0">
                        <label for="description" class="form-label">Description</label>
                        <textarea id="description" name="description" rows="11" class="form-control @error('description') is-invalid @enderror">{{ old('description') }}</textarea>
                        @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </x-card.default>
            </div>
        </div>

        <!-- ------------------------------------------------- -->
        <!-- Second row -->
        <!-- Supporting metadata. These cards should stay compact and quick to scan. -->
        <!-- ------------------------------------------------- -->
        <div class="row">

            <!-- ------------------------------------------------- -->
            <!-- Client and contact card -->
            <!-- Visible datalist inputs provide fast lookup; hidden IDs keep POST data stable. -->
            <!-- ------------------------------------------------- -->
            <div class="col-md-4">
                <x-card.default title="Client and contact">
                    <!-- ------------------------------------------------- -->
                    <!-- Client lookup -->
                    <!-- Changing client reloads the page so contacts and duplicate-ticket hints are server-rendered. -->
                    <!-- ------------------------------------------------- -->
                    <div class="mb-3">
                        @php
                            $selectedClientForInput = old('client_id')
                                ? $clients->firstWhere('id', (int) old('client_id'))
                                : $selectedClient;
                            $selectedClientLabel = $selectedClientForInput
                                ? $selectedClientForInput->name . ($selectedClientForInput->client_number ? ' (' . $selectedClientForInput->client_number . ')' : '')
                                : '';
                        @endphp

                        <label for="client_lookup" class="form-label">Client</label>
                        <input
                            id="client_lookup"
                            type="search"
                            class="form-control @error('client_id') is-invalid @enderror"
                            value="{{ $selectedClientLabel }}"
                            list="client_suggestions"
                            placeholder="Search and select client"
                            autocomplete="off"
                        >
                        <input id="client_id" name="client_id" type="hidden" value="{{ old('client_id', request('client_id')) }}">
                        <datalist id="client_suggestions">
                            @foreach ($clients as $client)
                                <option value="{{ $client->name }}@if ($client->client_number) ({{ $client->client_number }})@endif" data-id="{{ $client->id }}"></option>
                            @endforeach
                        </datalist>
                        @error('client_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <!-- ------------------------------------------------- -->
                    <!-- Contact lookup -->
                    <!-- Contacts are loaded only for the selected client and are validated again on submit. -->
                    <!-- ------------------------------------------------- -->
                    <div class="mb-3">
                        @php
                            $selectedContact = old('contact_id')
                                ? $contacts->firstWhere('id', (int) old('contact_id'))
                                : $selectedContact;
                            $selectedContactLabel = $selectedContact
                                ? $selectedContact->name . ($selectedContact->email ? ' - ' . $selectedContact->email : '')
                                : '';
                        @endphp

                        <label for="contact_lookup" class="form-label">Contact</label>
                        <input
                            id="contact_lookup"
                            type="search"
                            class="form-control @error('contact_id') is-invalid @enderror"
                            value="{{ $selectedContactLabel }}"
                            list="contact_suggestions"
                            placeholder="{{ $selectedClient ? 'Search and select contact' : 'Select a client first' }}"
                            autocomplete="off"
                            @disabled(! $selectedClient)
                        >
                        <input id="contact_id" name="contact_id" type="hidden" value="{{ old('contact_id', $selectedContact?->id) }}">
                        <datalist id="contact_suggestions">
                            @foreach ($contacts as $contact)
                                <option value="{{ $contact->name }}@if ($contact->email) - {{ $contact->email }}@endif" data-id="{{ $contact->id }}"></option>
                            @endforeach
                        </datalist>
                        @error('contact_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <!-- ------------------------------------------------- -->
                    <!-- Site lookup -->
                    <!-- Contact selection owns the site, otherwise technicians can scope the ticket and assets by site. -->
                    <!-- ------------------------------------------------- -->
                    <div class="mb-3">
                        <label for="site_id" class="form-label">Site</label>
                        <select id="site_id" name="site_id" class="form-select @error('site_id') is-invalid @enderror" @disabled(! $selectedClient || filled($selectedContact))>
                            <option value="">No site</option>
                            @foreach ($sites as $site)
                                <option value="{{ $site->id }}" @selected(old('site_id', $selectedSite?->id) == $site->id)>{{ $site->name }}</option>
                            @endforeach
                        </select>
                        @error('site_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        @if ($selectedContact)
                            <div class="form-text">Site is set from the selected contact.</div>
                            <input type="hidden" name="site_id" value="{{ $selectedSite?->id }}">
                        @endif
                    </div>

                    <!-- ------------------------------------------------- -->
                    <!-- Asset lookup -->
                    <!-- Assets are scoped by client, and when a contact is selected contact assets are listed before site assets. -->
                    <!-- ------------------------------------------------- -->
                    <div class="mb-0 mt-3">
                        <label for="asset_id" class="form-label">Asset</label>
                        <select id="asset_id" name="asset_id" class="form-select @error('asset_id') is-invalid @enderror" @disabled($assetOptions->isEmpty())>
                            <option value="">No asset</option>
                            @foreach ($assetOptions->groupBy('group') as $group => $assets)
                                <optgroup label="{{ $group }}">
                                    @foreach ($assets as $asset)
                                        <option value="{{ $asset['id'] }}" @selected(old('asset_id') == $asset['id'])>{{ $asset['label'] }}</option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                        @error('asset_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        @if ($assetOptions->isEmpty())
                            <div class="form-text">{{ $selectedClient ? 'No assets found for the current client/contact selection.' : 'Select a client to choose an asset.' }}</div>
                        @endif
                    </div>
                </x-card.default>
            </div>

            <!-- ------------------------------------------------- -->
            <!-- Classification card -->
            <!-- Queue, priority, and status use defaults created by EnsureTicketDefaults. -->
            <!-- ------------------------------------------------- -->
            <div class="col-md-4">
                <x-card.default title="Classification">
                    <div class="mb-3">
                        <label for="queue_id" class="form-label">Queue</label>
                        <select id="queue_id" name="queue_id" class="form-select">
                            @foreach ($queues as $queue)
                                <option value="{{ $queue->id }}" @selected(old('queue_id') == $queue->id || (! old('queue_id') && $queue->is_default))>{{ $queue->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="priority_id" class="form-label">Priority</label>
                        <select id="priority_id" name="priority_id" class="form-select">
                            @foreach ($priorities as $priority)
                                <option value="{{ $priority->id }}" @selected(old('priority_id') == $priority->id || (! old('priority_id') && $priority->is_default))>P{{ $priority->level }} {{ $priority->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="status_id" class="form-label">Status</label>
                        <select id="status_id" name="status_id" class="form-select">
                            @foreach ($statuses as $status)
                                <option value="{{ $status->id }}" @selected(old('status_id') == $status->id || (! old('status_id') && $status->is_default))>{{ $status->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="category_id" class="form-label">Category</label>
                        <select id="category_id" name="category_id" class="form-select @error('category_id') is-invalid @enderror">
                            <option value="">No category</option>
                            @foreach ($categories as $category)
                                <option value="{{ $category->id }}" @selected(old('category_id') == $category->id)>{{ $category->name }}</option>
                            @endforeach
                        </select>
                        @error('category_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-0">
                        <label class="form-label">Tags</label>
                        <div class="ticket-tag-input form-control d-flex flex-wrap align-items-center gap-1 p-1" data-ticket-tag-input>
                            @foreach(old('tag_names', []) as $tagName)
                                @continue(blank($tagName))
                                <span class="badge text-bg-secondary d-inline-flex align-items-center gap-1" data-tag-chip="{{ $tagName }}">
                                    {{ $tagName }}
                                    <button type="button" class="btn-close btn-close-white" data-remove-tag aria-label="Remove {{ $tagName }}"></button>
                                    <input type="hidden" name="tag_names[]" value="{{ $tagName }}">
                                </span>
                            @endforeach
                            <input type="text" class="ticket-tag-input__field border-0 flex-grow-1 px-1" list="ticketTagSuggestions" placeholder="Add tag">
                        </div>
                        <datalist id="ticketTagSuggestions">
                            @foreach($tags as $tag)
                                <option value="{{ $tag->name }}"></option>
                            @endforeach
                        </datalist>
                        @error('tag_names')<div class="text-danger small">{{ $message }}</div>@enderror
                    </div>
                </x-card.default>
            </div>

            <!-- ------------------------------------------------- -->
            <!-- Assignment card -->
            <!-- Defaults to the authenticated technician but allows immediate reassignment. -->
            <!-- ------------------------------------------------- -->
            <div class="col-md-4">
                <x-card.default title="Assignment">
                    <div class="mb-0">
                        <label for="owner_id" class="form-label">Technician</label>
                        <select id="owner_id" name="owner_id" class="form-select @error('owner_id') is-invalid @enderror">
                            @foreach ($technicians as $technician)
                                <option value="{{ $technician->id }}" @selected(old('owner_id', auth()->id()) == $technician->id)>
                                    {{ $technician->name }}
                                </option>
                            @endforeach
                        </select>
                        @error('owner_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </x-card.default>
            </div>
        </div>

        <!-- ------------------------------------------------- -->
        <!-- Form actions -->
        <!-- Keep actions outside cards so they apply to the whole create form. -->
        <!-- ------------------------------------------------- -->
        <div class="row">
            <div class="col-12">
                <div class="d-flex justify-content-between mt-3">
                    <x-buttons.cancel url="{{ route('tech.tickets.index') }}">Cancel</x-buttons.cancel>
                    <button type="submit" class="btn btn-primary">Create ticket</button>
                </div>
            </div>
        </div>
    </form>
</div>

<!-- -------------------------------------------------------------------------------------------------- -->
<!-- Client/contact lookup behavior -->
<!-- Keeps lookup fast with native datalist suggestions and stores selected model IDs in hidden inputs. -->
<!-- -------------------------------------------------------------------------------------------------- -->
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const clientLookup = document.getElementById('client_lookup');
        const clientId = document.getElementById('client_id');
        const clientOptions = Array.from(document.getElementById('client_suggestions').options);
        const contactLookup = document.getElementById('contact_lookup');
        const contactId = document.getElementById('contact_id');
        const contactOptions = Array.from(document.getElementById('contact_suggestions').options);
        const siteId = document.getElementById('site_id');

        function selectedOption(options, value) {
            return options.find(function (option) {
                return option.value === value;
            });
        }

        // Client selection changes available contacts and rightbar tickets, so exact matches reload the page.
        clientLookup.addEventListener('change', function () {
            const option = selectedOption(clientOptions, this.value);
            const nextClientId = option ? option.dataset.id : '';

            clientId.value = nextClientId;

            if (! nextClientId && this.value.trim() !== '') {
                return;
            }

            const url = new URL(@json(route('tech.tickets.create')));

            if (nextClientId) {
                url.searchParams.set('client_id', nextClientId);
            }

            window.location.href = url.toString();
        });

        // While typing, clear client_id unless the visible value exactly matches a datalist option.
        clientLookup.addEventListener('input', function () {
            const option = selectedOption(clientOptions, this.value);
            clientId.value = option ? option.dataset.id : '';
        });

        // Contact selection changes available assets, so exact matches reload the page with client and contact context.
        contactLookup.addEventListener('change', function () {
            const option = selectedOption(contactOptions, this.value);
            const nextContactId = option ? option.dataset.id : '';

            contactId.value = nextContactId;

            if (! nextContactId && this.value.trim() !== '') {
                return;
            }

            const url = new URL(@json(route('tech.tickets.create')));

            if (clientId.value) {
                url.searchParams.set('client_id', clientId.value);
            }

            if (nextContactId) {
                url.searchParams.set('contact_id', nextContactId);
            }

            window.location.href = url.toString();
        });

        // Contact suggestions are already scoped by selected client; only exact matches should submit an ID while typing.
        contactLookup.addEventListener('input', function () {
            const option = selectedOption(contactOptions, this.value);
            contactId.value = option ? option.dataset.id : '';
        });

        siteId.addEventListener('change', function () {
            const url = new URL(@json(route('tech.tickets.create')));

            if (clientId.value) {
                url.searchParams.set('client_id', clientId.value);
            }

            if (this.value) {
                url.searchParams.set('site_id', this.value);
            }

            window.location.href = url.toString();
        });

        const normalizeTag = (value) => value.trim().replace(/\s+/g, ' ');

        document.querySelectorAll('[data-ticket-tag-input]').forEach((container) => {
            const input = container.querySelector('.ticket-tag-input__field');

            const existingNames = () => Array.from(container.querySelectorAll('input[name="tag_names[]"]'))
                .map((hidden) => hidden.value.toLowerCase());

            const addTag = (rawName) => {
                const name = normalizeTag(rawName);

                if (!name || existingNames().includes(name.toLowerCase())) {
                    input.value = '';
                    return;
                }

                const chip = document.createElement('span');
                chip.className = 'badge text-bg-secondary d-inline-flex align-items-center gap-1';
                chip.dataset.tagChip = name;
                chip.append(document.createTextNode(name));

                const removeButton = document.createElement('button');
                removeButton.type = 'button';
                removeButton.className = 'btn-close btn-close-white';
                removeButton.dataset.removeTag = '';
                removeButton.setAttribute('aria-label', `Remove ${name}`);

                const hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = 'tag_names[]';
                hidden.value = name;

                chip.append(removeButton, hidden);
                container.insertBefore(chip, input);
                input.value = '';
            };

            input.addEventListener('keydown', (event) => {
                if (event.key === 'Enter' || event.key === ',') {
                    event.preventDefault();
                    addTag(input.value);
                }

                if (event.key === 'Backspace' && input.value === '') {
                    const chips = container.querySelectorAll('[data-tag-chip]');
                    chips[chips.length - 1]?.remove();
                }
            });

            input.addEventListener('change', () => addTag(input.value));
            input.closest('form')?.addEventListener('submit', () => addTag(input.value));

            container.addEventListener('click', (event) => {
                if (event.target.matches('[data-remove-tag]')) {
                    event.preventDefault();
                    event.target.closest('[data-tag-chip]')?.remove();
                    return;
                }

                input.focus();
            });
        });
    });
</script>
@endsection

@section('sidebar')
    <x-nav.work-menu />
@endsection

<!-- -------------------------------------------------------------------------------------------------- -->
<!-- Rightbar -->
<!-- Shows existing open tickets for the selected client to reduce accidental duplicate tickets. -->
<!-- -------------------------------------------------------------------------------------------------- -->
@section('rightbar')
    <x-card.default title="Open client tickets">
        @if ($selectedClient)
            <div class="small text-muted mb-2">{{ $selectedClient->name }}</div>

            <div class="list-group list-group-flush">
                @forelse ($openClientTickets as $ticket)
                    <a href="{{ route('tech.tickets.show', $ticket) }}" class="list-group-item list-group-item-action px-0">
                        <div class="d-flex justify-content-between gap-2">
                            <strong>{{ $ticket->ticket_key }}</strong>
                            <span class="text-muted small">{{ $ticket->updated_at?->diffForHumans() }}</span>
                        </div>
                        <div class="text-truncate">{{ $ticket->subject }}</div>
                        <div class="small text-muted">
                            {{ $ticket->status?->name }}@if ($ticket->priority) · P{{ $ticket->priority->level }} {{ $ticket->priority->name }}@endif
                        </div>
                    </a>
                @empty
                    <div class="text-muted small">No open tickets for this client.</div>
                @endforelse
            </div>
        @else
            <div class="text-muted small">Select a client to see open tickets before creating a new one.</div>
        @endif
    </x-card.default>
@endsection
