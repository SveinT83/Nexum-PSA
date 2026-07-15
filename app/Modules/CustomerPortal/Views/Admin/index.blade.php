@extends('layouts.default_tech')

@section('title', 'Customer Portal')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center gap-2">
        <h1 class="h4 mb-0">Customer Portal</h1>
        <x-buttons.back :url="route('tech.admin.index')" class="mb-0">Back</x-buttons.back>
    </div>
@endsection

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Customer Portal Summary -->
    <!-- ------------------------------------------------- -->
    <div class="row g-3 mb-3">
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-body">
                    <div class="small text-muted">Accounts</div>
                    <div class="h4 mb-0">{{ $stats['accounts'] }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-body">
                    <div class="small text-muted">Active memberships</div>
                    <div class="h4 mb-0">{{ $stats['active_memberships'] }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-body">
                    <div class="small text-muted">Pending invitations</div>
                    <div class="h4 mb-0">{{ $stats['pending_invitations'] }}</div>
                </div>
            </div>
        </div>
    </div>

    <!-- ------------------------------------------------- -->
    <!-- Portal Invitation Form -->
    <!-- ------------------------------------------------- -->
    <x-card.default title="Invite customer contact">
        <form method="POST" action="{{ route('tech.admin.system.customer-portal.invitations.store') }}">
            @csrf
            <div class="row g-3 align-items-end">
                <div class="col-lg-4">
                    <label for="contact_id" class="form-label">Contact</label>
                    <select id="contact_id" name="contact_id" class="form-select @error('contact_id') is-invalid @enderror" required>
                        <option value="">Select contact</option>
                        @foreach($contacts as $contact)
                            @php $contactEmail = $contact->emails->sortByDesc('is_primary')->first()?->email; @endphp
                            <option value="{{ $contact->id }}" @selected((string) old('contact_id') === (string) $contact->id)>
                                {{ $contact->display_name }}{{ $contactEmail ? ' - '.$contactEmail : '' }}
                            </option>
                        @endforeach
                    </select>
                    @error('contact_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="col-lg-3">
                    <label for="client_id" class="form-label">Client</label>
                    <select id="client_id" name="client_id" class="form-select @error('client_id') is-invalid @enderror" required>
                        <option value="">Select client</option>
                        @foreach($clients as $client)
                            <option value="{{ $client->id }}" @selected((string) old('client_id') === (string) $client->id)>{{ $client->name }}</option>
                        @endforeach
                    </select>
                    @error('client_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="col-lg-3">
                    <label for="site_id" class="form-label">Site</label>
                    <select id="site_id" name="site_id" class="form-select @error('site_id') is-invalid @enderror">
                        <option value="">All sites</option>
                        @foreach($sites as $site)
                            <option value="{{ $site->id }}" @selected((string) old('site_id') === (string) $site->id)>{{ $site->client?->name }} - {{ $site->name }}</option>
                        @endforeach
                    </select>
                    @error('site_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="col-lg-2">
                    <label for="role" class="form-label">Role</label>
                    <select id="role" name="role" class="form-select @error('role') is-invalid @enderror" required>
                        @foreach($roles as $value => $label)
                            <option value="{{ $value }}" @selected(old('role', 'viewer') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('role') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="col-lg-8">
                    <label for="email" class="form-label">Invitation email override</label>
                    <input type="email" id="email" name="email" value="{{ old('email') }}" class="form-control @error('email') is-invalid @enderror" placeholder="Uses the Contact primary email when empty">
                    @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="col-lg-4 d-flex justify-content-end">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-envelope-paper me-1" aria-hidden="true"></i>
                        Send invitation
                    </button>
                </div>
            </div>
        </form>
    </x-card.default>

    <!-- ------------------------------------------------- -->
    <!-- Portal Accounts -->
    <!-- ------------------------------------------------- -->
    <x-card.default title="Portal accounts">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Contact</th>
                        <th>User</th>
                        <th>Memberships</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($accounts as $account)
                        <tr>
                            <td>
                                <div class="fw-semibold">{{ $account->contact?->display_name }}</div>
                                <div class="small text-muted">Contact #{{ $account->contact_id }}</div>
                            </td>
                            <td>
                                <div>{{ $account->user?->email }}</div>
                                <div class="small text-muted">Last portal access: {{ $account->last_login_at?->diffForHumans() ?: 'Never' }}</div>
                            </td>
                            <td>
                                <div class="d-grid gap-1">
                                    @foreach($account->memberships as $membership)
                                        <div class="d-flex align-items-center justify-content-between gap-2 border rounded px-2 py-1">
                                            <span class="small">{{ $membership->client?->name }} &middot; {{ $membership->site?->name ?: 'All sites' }} &middot; {{ $membership->roleLabel() }}</span>
                                            <span class="badge {{ $membership->isActive() ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $membership->status }}</span>
                                        </div>
                                    @endforeach
                                </div>
                            </td>
                            <td><span class="badge text-bg-light border">{{ $account->status }}</span></td>
                            <td class="text-end">
                                @foreach($account->memberships->where('status', \App\Modules\CustomerPortal\Models\CustomerPortalMembership::STATUS_ACTIVE) as $membership)
                                    <form method="POST" action="{{ route('tech.admin.system.customer-portal.memberships.disable', $membership) }}" class="d-inline">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Disable</button>
                                    </form>
                                @endforeach
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">No customer portal accounts exist yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card.default>
@endsection

@section('sidebar')
    <x-nav.admin-menu group="customer-portal" />
@endsection

@section('rightbar')
    <x-card.default title="Pending invitations">
        <div class="d-grid gap-2">
            @forelse($invitations as $invitation)
                <div class="border rounded p-2">
                    <div class="fw-semibold small">{{ $invitation->contact?->display_name ?: $invitation->email }}</div>
                    <div class="small text-muted">{{ $invitation->client?->name }} &middot; {{ $invitation->site?->name ?: 'All sites' }}</div>
                    <div class="small text-muted">Expires {{ $invitation->expires_at?->diffForHumans() }}</div>
                    @if($invitation->accepted_at)
                        <span class="badge text-bg-success">Accepted</span>
                    @elseif($invitation->revoked_at)
                        <span class="badge text-bg-secondary">Revoked</span>
                    @elseif($invitation->expires_at?->isPast())
                        <span class="badge text-bg-warning">Expired</span>
                    @else
                        <form method="POST" action="{{ route('tech.admin.system.customer-portal.invitations.revoke', $invitation) }}" class="mt-2">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-outline-secondary w-100">Revoke</button>
                        </form>
                    @endif
                </div>
            @empty
                <p class="small text-muted mb-0">No portal invitations have been created yet.</p>
            @endforelse
        </div>
    </x-card.default>
@endsection
