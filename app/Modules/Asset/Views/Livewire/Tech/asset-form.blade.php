<div>
    <form wire:submit="save">

        {{-- SECTION: Ownership & Location --}}
        <div class="row mb-4">
            <div class="col-md-12">
                <h5 class="mb-3 border-bottom pb-2">Ownership & Location</h5>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label for="client_id" class="form-label">Client</label>
                        <select wire:model.live="client_id" id="client_id" class="form-select @error('client_id') is-invalid @enderror">
                            <option value="">Internal asset</option>
                            @foreach($clients as $client)
                                <option value="{{ $client->id }}">{{ $client->name }}</option>
                            @endforeach
                        </select>
                        <div class="form-text">Leave blank for company-owned/internal assets.</div>
                        @error('client_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-6">
                        <label for="site_id" class="form-label">Site</label>
                        <select wire:model="site_id" id="site_id" class="form-select @error('site_id') is-invalid @enderror" {{ empty($sites) ? 'disabled' : '' }}>
                            <option value="">Select Site</option>
                            @foreach($sites as $site)
                                <option value="{{ $site->id }}">{{ $site->name }}</option>
                            @endforeach
                        </select>
                        @error('site_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <label for="user_id" class="form-label">User / Owner</label>
                        <select wire:model="user_id" id="user_id" class="form-select @error('user_id') is-invalid @enderror" {{ empty($users) ? 'disabled' : '' }}>
                            <option value="">Select User</option>
                            @foreach($users as $user)
                                <option value="{{ $user->id }}">{{ $user->name }} ({{ $user->email }})</option>
                            @endforeach
                        </select>
                        @error('user_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                </div>
            </div>
        </div>

        {{-- SECTION: Core Identification --}}
        <div class="row mb-4">
            <div class="col-md-12">
                <h5 class="mb-3 border-bottom pb-2">Identification</h5>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label for="name" class="form-label">Asset Name *</label>
                        <input type="text" wire:model="name" id="name" class="form-control @error('name') is-invalid @enderror" required placeholder="e.g. Finance PC 01">
                        @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-6">
                        <label for="type" class="form-label">Asset Type *</label>
                        <select wire:model="type" id="type" class="form-select @error('type') is-invalid @enderror" required>
                            @foreach($assetTypes as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('type') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                </div>

                <div class="row mb-3">
                    <div class="col-md-6">
                        <label for="vendor_id" class="form-label">Vendor (System)</label>
                        <select wire:model="vendor_id" id="vendor_id" class="form-select @error('vendor_id') is-invalid @enderror">
                            <option value="">Select Vendor</option>
                            @foreach($vendors as $v)
                                <option value="{{ $v->id }}">{{ $v->name }}</option>
                            @endforeach
                        </select>
                        @error('vendor_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-6">
                        <label for="model" class="form-label">Model</label>
                        <input type="text" wire:model="model" id="model" class="form-control @error('model') is-invalid @enderror" placeholder="e.g. Latitude 5420">
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <label for="serial_number" class="form-label">Serial Number</label>
                        <input type="text" wire:model="serial_number" id="serial_number" class="form-control @error('serial_number') is-invalid @enderror" placeholder="S/N or Service Tag">
                    </div>
                </div>
            </div>
        </div>

        {{-- SECTION: Classification --}}
        <div class="row mb-4">
            <div class="col-md-12">
                <h5 class="mb-3 border-bottom pb-2">Classification</h5>
                <div class="row">
                    <div class="col-md-6">
                        <label for="sensitivity_level" class="form-label">Sensitivity Rating</label>
                        <select wire:model="sensitivity_level" id="sensitivity_level" class="form-select @error('sensitivity_level') is-invalid @enderror">
                            <option value="">Not Set</option>
                            <option value="low">Low</option>
                            <option value="medium">Medium</option>
                            <option value="high">High</option>
                            <option value="ultra">Ultra</option>
                        </select>
                        @error('sensitivity_level') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-6">
                        <label for="criticality_level" class="form-label">Criticality Rating</label>
                        <select wire:model="criticality_level" id="criticality_level" class="form-select @error('criticality_level') is-invalid @enderror">
                            <option value="">Not Set</option>
                            <option value="low">Low</option>
                            <option value="medium">Medium</option>
                            <option value="high">High</option>
                            <option value="critical">Critical</option>
                        </select>
                        @error('criticality_level') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                </div>
            </div>
        </div>

        {{-- SECTION: Network Configuration --}}
        <div class="row mb-4">
            <div class="col-md-12">
                <h5 class="mb-3 border-bottom pb-2">Network Details</h5>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label for="hostname" class="form-label">Hostname</label>
                        <input type="text" wire:model="hostname" id="hostname" class="form-control @error('hostname') is-invalid @enderror" placeholder="e.g. DESKTOP-ABC123">
                    </div>
                    <div class="col-md-6">
                        <label for="mac_address" class="form-label">MAC Address</label>
                        <input type="text" wire:model="mac_address" id="mac_address" class="form-control @error('mac_address') is-invalid @enderror" placeholder="00:11:22:33:44:55">
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <label for="ip_address" class="form-label">IP Address</label>
                        <div class="input-group">
                            <input type="text" wire:model="ip_address" id="ip_address" class="form-control @error('ip_address') is-invalid @enderror" placeholder="192.168.1.10">
                            <select wire:model="ip_type" class="form-select flex-grow-0" style="width: 100px;">
                                <option value="dhcp">DHCP</option>
                                <option value="fixed">Fixed</option>
                            </select>
                        </div>
                        @error('ip_address') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                    </div>
                </div>
            </div>
        </div>

        {{-- SECTION: Submission --}}
        <div class="mt-4 border-top pt-3">
            <button type="submit" class="btn btn-primary">
                <span wire:loading wire:target="save" class="spinner-border spinner-border-sm" role="status"></span>
                {{ $asset && $asset->exists ? 'Update Asset' : 'Create Asset' }}
            </button>

            @if($asset && $asset->exists)
                <a href="{{ route('tech.assets.show', $asset->id) }}" class="btn btn-outline-secondary">Cancel</a>
            @else
                <a href="{{ route('tech.assets.index') }}" class="btn btn-outline-secondary">Cancel</a>
            @endif
        </div>
    </form>
</div>
