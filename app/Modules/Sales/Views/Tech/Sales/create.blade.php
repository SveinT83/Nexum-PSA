@extends('layouts.default_tech')

@section('title', 'New Sales Opportunity')

@section('sidebar')
    <x-nav.sales-menu />
@endsection

@section('pageHeader')
    <div>
        <h1 class="mb-0">New Opportunity</h1>
        <p class="text-muted mb-0">Create an active sales process for an existing client.</p>
    </div>
@endsection

@section('content')
    <div class="container-fluid">
        <form method="POST" action="{{ route('tech.sales.store') }}" class="card">
            @csrf
            <div class="card-body">
                <div class="row g-3">
                    <!-- ------------------------------------------------- -->
                    <!-- Client selection with inline quick-create modal trigger -->
                    <!-- ------------------------------------------------- -->
                    <div class="col-md-6">
                        <div class="d-flex align-items-center justify-content-between gap-2">
                            <label for="client_id" class="form-label mb-0">Client</label>
                            <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none" data-bs-toggle="modal" data-bs-target="#quickClientModal">
                                New client
                            </button>
                        </div>
                        <select id="client_id" name="client_id" class="form-select" required>
                            <option value="">Select client</option>
                            @foreach($clients as $client)
                                <option value="{{ $client->id }}" @selected(old('client_id') == $client->id)>
                                    {{ $client->name }}@if($client->client_number) ({{ $client->client_number }})@endif
                                </option>
                            @endforeach
                        </select>
                        <div class="form-text">Create the client here when the prospect is not already registered.</div>
                    </div>
                    <div class="col-md-6">
                        <div class="d-flex align-items-center justify-content-between gap-2">
                            <label for="primary_contact_id" class="form-label mb-0">Sales contact</label>
                            <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none" id="quickContactButton" data-bs-toggle="modal" data-bs-target="#quickContactModal" disabled>
                                New contact
                            </button>
                        </div>
                        <select id="primary_contact_id" name="primary_contact_id" class="form-select">
                            <option value="">Select client first</option>
                        </select>
                        <div class="form-text">This contact receives quote email by default.</div>
                    </div>
                    <div class="col-md-6">
                        <label for="owner_id" class="form-label">Owner</label>
                        <select id="owner_id" name="owner_id" class="form-select">
                            @foreach($owners as $owner)
                                <option value="{{ $owner->id }}" @selected(old('owner_id', auth()->id()) == $owner->id)>{{ $owner->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-8">
                        <label for="title" class="form-label">Title</label>
                        <input type="text" id="title" name="title" class="form-control" value="{{ old('title') }}" required>
                    </div>
                    <div class="col-md-4">
                        <label for="type" class="form-label">Type</label>
                        <select id="type" name="type" class="form-select">
                            @foreach($types as $key => $label)
                                <option value="{{ $key }}" @selected(old('type') === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label for="expected_close_date" class="form-label">Expected close</label>
                        <input type="date" id="expected_close_date" name="expected_close_date" class="form-control" value="{{ old('expected_close_date') }}">
                    </div>
                    <div class="col-md-4">
                        <label for="next_follow_up_at" class="form-label">Next follow-up</label>
                        <input type="datetime-local" id="next_follow_up_at" name="next_follow_up_at" class="form-control" value="{{ old('next_follow_up_at') }}">
                    </div>
                    <div class="col-md-4">
                        <label for="next_follow_up_type" class="form-label">Next action</label>
                        <select id="next_follow_up_type" name="next_follow_up_type" class="form-select">
                            <option value="">No action selected</option>
                            @foreach($nextActions as $key => $label)
                                <option value="{{ $key }}" @selected(old('next_follow_up_type', 'call') === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="employee_count_estimate" class="form-label">Employees</label>
                        <input type="number" id="employee_count_estimate" name="employee_count_estimate" class="form-control" min="0" value="{{ old('employee_count_estimate') }}">
                    </div>
                    <div class="col-md-3">
                        <label for="user_count_estimate" class="form-label">Users</label>
                        <input type="number" id="user_count_estimate" name="user_count_estimate" class="form-control" min="0" value="{{ old('user_count_estimate') }}">
                    </div>
                    <div class="col-md-3">
                        <label for="workstation_count_estimate" class="form-label">Workstations</label>
                        <input type="number" id="workstation_count_estimate" name="workstation_count_estimate" class="form-control" min="0" value="{{ old('workstation_count_estimate') }}">
                    </div>
                    <div class="col-md-3">
                        <label for="server_count_estimate" class="form-label">Servers</label>
                        <input type="number" id="server_count_estimate" name="server_count_estimate" class="form-control" min="0" value="{{ old('server_count_estimate') }}">
                    </div>
                    <div class="col-12">
                        <label for="needs" class="form-label">Needs</label>
                        <textarea id="needs" name="needs" class="form-control" rows="4">{{ old('needs') }}</textarea>
                    </div>
                    <div class="col-12">
                        <label for="next_follow_up_note" class="form-label">Follow-up note</label>
                        <textarea id="next_follow_up_note" name="next_follow_up_note" class="form-control" rows="2">{{ old('next_follow_up_note') }}</textarea>
                    </div>
                </div>
            </div>
            <div class="card-footer d-flex justify-content-end gap-2">
                <a href="{{ route('tech.sales.index') }}" class="btn btn-outline-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary">Create Opportunity</button>
            </div>
        </form>

        <!-- ------------------------------------------------- -->
        <!-- Quick client modal -->
        <!-- ------------------------------------------------- -->
        <div class="modal fade" id="quickClientModal" tabindex="-1" aria-labelledby="quickClientModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <form class="modal-content" id="quickClientForm" data-store-url="{{ route('tech.sales.clients.quick-store') }}">
                    @csrf
                    <div class="modal-header">
                        <h2 class="modal-title h5" id="quickClientModalLabel">New Client</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-danger d-none" id="quickClientErrors"></div>

                        <!-- ------------------------------------------------- -->
                        <!-- Company details -->
                        <!-- ------------------------------------------------- -->
                        <div class="row g-3">
                            <div class="col-md-3">
                                <label for="quick_client_number" class="form-label">Client number</label>
                                <input type="text" id="quick_client_number" name="client_number" class="form-control" value="{{ $suggestedClientNumber }}" inputmode="numeric" pattern="\d{5}">
                            </div>
                            <div class="col-md-5">
                                <label for="quick_client_name" class="form-label">Name</label>
                                <input type="text" id="quick_client_name" name="name" class="form-control" required>
                            </div>
                            <div class="col-md-3">
                                <label for="quick_org_no" class="form-label">Org No</label>
                                <input type="text" id="quick_org_no" name="org_no" class="form-control">
                            </div>
                            <div class="col-md-4">
                                <label for="quick_client_format_id" class="form-label">Format</label>
                                <select id="quick_client_format_id" name="client_format_id" class="form-select">
                                    <option value="">Select format</option>
                                    @foreach($clientFormats as $format)
                                        <option value="{{ $format->id }}">{{ $format->code }} - {{ $format->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="quick_billing_email" class="form-label">Billing email</label>
                                <input type="email" id="quick_billing_email" name="billing_email" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label for="quick_site_name" class="form-label">Site name</label>
                                <input type="text" id="quick_site_name" name="site_name" class="form-control" value="General sites" required>
                            </div>
                        </div>

                        <!-- ------------------------------------------------- -->
                        <!-- Primary contact -->
                        <!-- ------------------------------------------------- -->
                        <div class="border-top mt-3 pt-3">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label for="quick_user_name" class="form-label">Primary contact</label>
                                    <input type="text" id="quick_user_name" name="user_name" class="form-control" required>
                                </div>
                                <div class="col-md-4">
                                    <label for="quick_user_email" class="form-label">Contact email</label>
                                    <input type="email" id="quick_user_email" name="user_email" class="form-control" required>
                                </div>
                                <div class="col-md-4">
                                    <label for="quick_user_phone" class="form-label">Contact phone</label>
                                    <input type="tel" id="quick_user_phone" name="user_phone" class="form-control">
                                </div>
                                <div class="col-md-4">
                                    <label for="quick_user_role" class="form-label">Contact role</label>
                                    <select id="quick_user_role" name="user_role" class="form-select">
                                        <option value="">Select role</option>
                                        @foreach($clientContactRoles as $role)
                                            <option value="{{ $role }}">{{ $role }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-8">
                                    <label for="quick_notes" class="form-label">Notes</label>
                                    <input type="text" id="quick_notes" name="notes" class="form-control">
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="quickClientSubmit">Create Client</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ------------------------------------------------- -->
        <!-- Quick contact modal -->
        <!-- ------------------------------------------------- -->
        <div class="modal fade" id="quickContactModal" tabindex="-1" aria-labelledby="quickContactModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <form class="modal-content" id="quickContactForm" data-store-url-template="{{ route('tech.sales.clients.contacts.quick-store', ['client' => '__CLIENT__']) }}">
                    @csrf
                    <div class="modal-header">
                        <h2 class="modal-title h5" id="quickContactModalLabel">New Contact</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-danger d-none" id="quickContactErrors"></div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="quick_contact_name" class="form-label">Name</label>
                                <input type="text" id="quick_contact_name" name="name" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label for="quick_contact_email" class="form-label">Email</label>
                                <input type="email" id="quick_contact_email" name="email" class="form-control" required>
                            </div>
                            <div class="col-md-4">
                                <label for="quick_contact_phone" class="form-label">Phone</label>
                                <input type="tel" id="quick_contact_phone" name="phone" class="form-control">
                            </div>
                            <div class="col-md-4">
                                <label for="quick_contact_role" class="form-label">Role</label>
                                <select id="quick_contact_role" name="role" class="form-select">
                                    <option value="">Select role</option>
                                    @foreach($clientContactRoles as $role)
                                        <option value="{{ $role }}">{{ $role }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label for="quick_contact_site_id" class="form-label">Site</label>
                                <select id="quick_contact_site_id" name="client_site_id" class="form-select">
                                    <option value="">Default site</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="quickContactSubmit">Create Contact</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@section('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const clientContactData = @json($clientContactData);
            const form = document.getElementById('quickClientForm');
            const contactForm = document.getElementById('quickContactForm');
            const clientSelect = document.getElementById('client_id');
            const contactSelect = document.getElementById('primary_contact_id');
            const quickContactButton = document.getElementById('quickContactButton');
            const contactSiteSelect = document.getElementById('quick_contact_site_id');
            const errorBox = document.getElementById('quickClientErrors');
            const contactErrorBox = document.getElementById('quickContactErrors');
            const submitButton = document.getElementById('quickClientSubmit');
            const contactSubmitButton = document.getElementById('quickContactSubmit');
            const modalElement = document.getElementById('quickClientModal');
            const contactModalElement = document.getElementById('quickContactModal');
            const modal = window.bootstrap ? window.bootstrap.Modal.getOrCreateInstance(modalElement) : null;
            const contactModal = window.bootstrap ? window.bootstrap.Modal.getOrCreateInstance(contactModalElement) : null;

            if (!form || !clientSelect) {
                return;
            }

            const showErrors = (messages) => {
                errorBox.classList.remove('d-none');
                errorBox.innerHTML = '';
                messages.forEach((message) => {
                    const line = document.createElement('div');
                    line.textContent = message;
                    errorBox.appendChild(line);
                });
            };

            const showContactErrors = (messages) => {
                contactErrorBox.classList.remove('d-none');
                contactErrorBox.innerHTML = '';
                messages.forEach((message) => {
                    const line = document.createElement('div');
                    line.textContent = message;
                    contactErrorBox.appendChild(line);
                });
            };

            const syncContactOptions = (selectedContactId = '') => {
                const clientId = clientSelect.value;
                const data = clientContactData[clientId] || { contacts: [], sites: [] };

                contactSelect.innerHTML = '';
                contactSiteSelect.innerHTML = '<option value="">Default site</option>';

                if (!clientId) {
                    contactSelect.add(new Option('Select client first', ''));
                    quickContactButton.disabled = true;
                    return;
                }

                quickContactButton.disabled = false;
                contactSelect.add(new Option(data.contacts.length ? 'Select sales contact' : 'No contacts yet', ''));

                data.contacts.forEach((contact) => {
                    contactSelect.add(new Option(contact.label, contact.id, false, String(selectedContactId) === String(contact.id)));
                });

                data.sites.forEach((site) => {
                    contactSiteSelect.add(new Option(site.name, site.id));
                });
            };

            clientSelect.addEventListener('change', () => syncContactOptions());
            syncContactOptions(@json(old('primary_contact_id')));

            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                errorBox.classList.add('d-none');
                errorBox.innerHTML = '';
                submitButton.disabled = true;
                submitButton.textContent = 'Creating...';

                try {
                    const response = await fetch(form.dataset.storeUrl, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': form.querySelector('input[name="_token"]').value,
                        },
                        body: new FormData(form),
                    });

                    const payload = await response.json();

                    if (!response.ok) {
                        const messages = payload.errors
                            ? Object.values(payload.errors).flat()
                            : [payload.message || 'Client could not be created.'];
                        showErrors(messages);
                        return;
                    }

                    const option = new Option(payload.client.label, payload.client.id, true, true);
                    clientSelect.add(option);
                    clientContactData[payload.client.id] = {
                        sites: payload.sites || [],
                        contacts: payload.contacts || [],
                    };
                    syncContactOptions(payload.contacts?.[0]?.id || '');
                    form.reset();

                    const numberInput = document.getElementById('quick_client_number');
                    if (numberInput && payload.client.client_number) {
                        numberInput.value = String(Number(payload.client.client_number) + 1).padStart(5, '0');
                    }

                    if (modal) {
                        modal.hide();
                    }
                } catch (error) {
                    showErrors(['Client could not be created. Please try again.']);
                } finally {
                    submitButton.disabled = false;
                    submitButton.textContent = 'Create Client';
                }
            });

            contactForm?.addEventListener('submit', async (event) => {
                event.preventDefault();
                contactErrorBox.classList.add('d-none');
                contactErrorBox.innerHTML = '';
                contactSubmitButton.disabled = true;
                contactSubmitButton.textContent = 'Creating...';

                try {
                    const clientId = clientSelect.value;
                    const response = await fetch(contactForm.dataset.storeUrlTemplate.replace('__CLIENT__', clientId), {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': contactForm.querySelector('input[name="_token"]').value,
                        },
                        body: new FormData(contactForm),
                    });

                    const payload = await response.json();

                    if (!response.ok) {
                        const messages = payload.errors
                            ? Object.values(payload.errors).flat()
                            : [payload.message || 'Contact could not be created.'];
                        showContactErrors(messages);
                        return;
                    }

                    clientContactData[clientId] ||= { sites: [], contacts: [] };
                    clientContactData[clientId].contacts.push(payload.contact);
                    syncContactOptions(payload.contact.id);
                    contactForm.reset();

                    if (contactModal) {
                        contactModal.hide();
                    }
                } catch (error) {
                    showContactErrors(['Contact could not be created. Please try again.']);
                } finally {
                    contactSubmitButton.disabled = false;
                    contactSubmitButton.textContent = 'Create Contact';
                }
            });
        });
    </script>
@endsection
