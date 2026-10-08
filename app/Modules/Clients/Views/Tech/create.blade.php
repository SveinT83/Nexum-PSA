{{--
    Client Creation Form

    This form is used to register a new client. It automatically:
    1. Suggests a new client number.
    2. Creates a default site for the client.
    3. Creates a default primary contact (user) for that site.
--}}
@extends('layouts.default_tech')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center">
        <h1>New Client</h1>
        <div>
            <x-buttons.back url="{{ route('tech.clients.index') }}" class="mb-0">Back</x-buttons.back>
        </div>
    </div>
@endsection

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Client creation form -->
    <!-- ------------------------------------------------- -->
    <div class="card">
        <div class="card-header">
            <h2 class="h5 mb-0">Create Client</h2>
        </div>
        <div class="card-body">
            <form method="post" action="{{ route('tech.clients.store') }}">
                <fieldset @disabled($numberError !== null)>
                @csrf
                @if($numberError)
                    <div class="alert alert-warning" role="alert">{{ $numberError }}</div>
                @endif
                @if($tripletexMode)
                    @include('integration::Tech.Admin.System.Integrations.tripletex.customer-picker')
                @endif
                <input type="hidden" name="suggested_client_number"
                       value="{{ old('suggested_client_number', $suggestedClientNumber) }}">

                <!-- ------------------------------------------------- -->
                <!-- Top Row: Client number, Name, Org No, Format -->
                <!-- ------------------------------------------------- -->
                <div class="row border-bottom mb-3 pb-3">

                    <!-- Client number, 5 digits required. Default ID from database row -->
                    <div class="col-md-2 mb-3">
                        <label class="form-label fw-bold">Client number</label>
                        <input type="number" name="client_number" placeholder="00000"
                               value="{{ old('client_number') ?? $suggestedClientNumber }}" required @readonly($tripletexMode)
                               class="form-control @error('client_number') is-invalid @enderror">
                        @error('client_number')
                        <div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

            <!-- Name, Required -->
            <div class="col-md-5 mb-3">
                <label class="form-label fw-bold">Name *</label>
                <input type="text" name="name" value="{{ old('name') }}" required
                       class="form-control @error('name') is-invalid @enderror">
                @error('name')
                <div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <!-- Org No 11 Numbers, Not Required -->
            <div class="col-md-3 mb-3">
                <label class="form-label fw-bold">Org No</label>
                <input type="text" name="org_no" placeholder="Organization number" value="{{ old('org_no')}}"
                       class="form-control @error('org_no') is-invalid @enderror">
                @error('org_no')
                <div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <!-- Client format, configured in Admin > Sales settings -->
            <div class="col-md-2 mb-3">
                <label class="form-label fw-bold">Format</label>
                <select name="client_format_id" class="form-select @error('client_format_id') is-invalid @enderror">
                    <option value="">Select format</option>
                    @foreach(($clientFormats ?? []) as $format)
                        <option value="{{ $format->id }}" @selected(old('client_format_id') == $format->id)>{{ $format->code }}</option>
                    @endforeach
                </select>
                @error('client_format_id')
                <div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
        </div>

        <!-- ------------------------------------------------- -->
        <!-- Default site and primary contact -->
        <!-- ------------------------------------------------- -->
        <div class="row border-bottom mt-3 mb-3 pt-3 pb-3">

            <!-- Default sites -->
            <div class="col-md-3 mb-3">
                <label class="form-label fw-bold">Site name*</label>
                <input type="text" name="site_name" value="{{ old('site_name') ?? "General sites" }}" required
                       class="form-control @error('site_name') is-invalid @enderror">
                @error('site_name')
                <div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <!-- Primary contact -->
            <div class="col-md-3 mb-3">
                <label class="form-label fw-bold">Primary contact name*</label>
                <input type="text" name="user_name" value="{{ old('user_name') ?? "General contact" }}" required
                       class="form-control @error('user_name') is-invalid @enderror">
                @error('user_name')
                <div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <!-- Primary contact email -->
            <div class="col-md-3 mb-3">
                <label class="form-label fw-bold">Primary contact email*</label>
                <input type="email" name="user_email" placeholder="email@domain.com" value="{{ old('user_email') }}"
                       required class="form-control @error('user_email') is-invalid @enderror">
                @error('user_email')
                <div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <!-- Primary contact phone -->
            <div class="col-md-3 mb-3">
                <label class="form-label fw-bold">Primary contact phone</label>
                <input type="tel" name="user_phone" value="{{ old('user_phone') }}"
                       class="form-control @error('user_phone') is-invalid @enderror">
                @error('user_phone')
                <div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
        </div>

        {{-- Address belongs to the default Site; contact details remain separate. --}}
        <div class="row mb-3">
            @foreach(['site_address' => ['Street address', 4, 255], 'site_co_address' => ['Address line 2', 4, 255],
                'site_zip' => ['Postal code', 2, 20], 'site_city' => ['City', 2, 100],
                'site_country' => ['Country (ISO code)', 2, 2]] as $field => [$label, $width, $length])
                <div class="col-md-{{ $width }} mb-3">
                    <label class="form-label fw-bold" for="{{ $field }}">{{ $label }}</label>
                    <input type="text" id="{{ $field }}" name="{{ $field }}" maxlength="{{ $length }}"
                        value="{{ old($field) }}" class="form-control @error($field) is-invalid @enderror">
                    @error($field)<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            @endforeach
        </div>

        <!-- ------------------------------------------------- -->
        <!-- Optional: primary contact role selector -->
        <!-- ------------------------------------------------- -->
        <div class="row mb-3">
            <div class="col-md-3">
                <label class="form-label fw-bold">Primary contact role</label>
                <select name="user_role" class="form-select @error('user_role') is-invalid @enderror">
                    <option value="">Select role</option>
                    @foreach(($roles ?? []) as $role)
                        <option value="{{ $role }}" @selected(old('user_role') === $role)>{{ $role }}</option>
                    @endforeach
                </select>
                @error('user_role')
                <div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
        </div>

        <div class="mb-3 mt-3">
            <label class="form-label fw-bold">Billing Email</label>
            <input type="email" name="billing_email" value="{{ old('billing_email') }}"
                   class="form-control @error('billing_email') is-invalid @enderror">
            @error('billing_email')
            <div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="mb-3">
            <label class="form-label fw-bold">Notes</label>
            <textarea name="notes" rows="4"
                      class="form-control @error('notes') is-invalid @enderror">{{ old('notes') }}</textarea>
            @error('notes')
            <div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" value="1" name="active" id="activeCheck" checked>
            <label class="form-check-label" for="activeCheck">Active</label>
        </div>

        @if($nableActive ?? false)
            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" value="1" name="create_in_rmm"
                       id="rmmCheck" {{ old('create_in_rmm') ? 'checked' : '' }}>
                <label class="form-check-label" for="rmmCheck">
                    Create in N-able RMM
                    <span class="small text-muted">(Format: {client_number} - {name})</span>
                </label>
            </div>
        @endif

                <div class="mb-0">
                    <button type="submit" class="btn btn-primary">Create Client</button>
                </div>
            </fieldset>
            </form>
        </div>
    </div>
@endsection

@section('sidebar')
    @if(isset($sidebarMenuItems))
        <x-nav.side-bar :items="$sidebarMenuItems" title="Client workspace" />
    @endif
@endsection

@section('rightbar')
@endsection
