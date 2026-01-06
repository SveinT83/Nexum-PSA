@extends('layouts.default_tech')

@section('pageHeader')
	<div class="d-flex justify-content-between align-items-center py-3">
        <h2 class="h4 mb-0">New Client</h2>
        <div>
            <a href="{{ route('tech.clients.index') }}" class="btn btn-sm btn-outline-secondary">Back</a>
		</div>
	</div>
@endsection

@section('content')
    <form class="container-fluid" method="post" action="{{ route('tech.clients.store') }}" class="col-12 col-lg-10">
		@csrf

        <!-- ------------------------------------------------- -->
        <!-- Top Row: Client number, Name, Org No -->
        <!-- ------------------------------------------------- -->
        <div class="row border-bottom mb-3 pb-3">

            <!-- Client number, 5 digits required. Default ID from database row -->
            <div class="col-md-2 mb-3">
                <label class="form-label fw-bold">Client number</label>
                <input type="number" name="client_number" placeholder="00000" value="{{ old('client_number') ?? $suggestedClientNumber }}" required class="form-control @error('client_number') is-invalid @enderror">
                @error('client_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <!-- Name, Required -->
            <div class="col-md-7 mb-3">
                <label class="form-label fw-bold">Name *</label>
                <input type="text" name="name" value="{{ old('name') }}" required class="form-control @error('name') is-invalid @enderror">
                @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <!-- Org No 11 Numbers, Not Required -->
            <div class="col-md-3 mb-3">
                <label class="form-label fw-bold">Org No</label>
                <input type="text" name="org_no" placeholder="11 siffer" value="{{ old('org_no')}}" class="form-control @error('org_no') is-invalid @enderror">
                @error('org_no')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
        </div>

        <!-- ------------------------------------------------- -->
        <!-- Default site and user -->
        <!-- ------------------------------------------------- -->
        <div class="row border-bottom mt-3 mb-3 pt-3 pb-3">

            <!-- Default site -->
            <div class="col-md-3 mb-3">
                <label class="form-label fw-bold">Site name*</label>
                <input type="text" name="site_name" value="{{ old('site_name') ?? "General site" }}" required class="form-control @error('site_name') is-invalid @enderror">
                @error('site_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <!-- Default user -->
            <div class="col-md-3 mb-3">
                <label class="form-label fw-bold">User name*</label>
                <input type="text" name="user_name" value="{{ old('user_name') ?? "General user" }}" required class="form-control @error('user_name') is-invalid @enderror">
                @error('user_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <!-- Default user email, Not required -->
            <div class="col-md-3 mb-3">
                <label class="form-label fw-bold">User email*</label>
                <input type="email" name="user_email" placeholder="email@domain.com" value="{{ old('user_email') }}" required class="form-control @error('user_email') is-invalid @enderror">
                @error('user_email')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <!-- Default user phone, Not required -->
            <div class="col-md-3 mb-3">
                <label class="form-label fw-bold">User phone</label>
                <input type="tel" name="user_phone" value="{{ old('user_phone') }}" class="form-control @error('user_phone') is-invalid @enderror">
                @error('user_phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
        </div>

        <!-- ------------------------------------------------- -->
        <!-- Optional: User role selector -->
        <!-- ------------------------------------------------- -->
        <div class="row mb-3">
            <div class="col-md-3">
                <label class="form-label fw-bold">User role</label>
                <select name="user_role" class="form-select @error('user_role') is-invalid @enderror">
                    <option value="">Select role</option>
                    @foreach(($roles ?? []) as $role)
                        <option value="{{ $role }}" @selected(old('user_role') === $role)>{{ $role }}</option>
                    @endforeach
                </select>
                @error('user_role')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
        </div>
        
		<div class="mb-3 mt-3">
			<label class="form-label fw-bold">Billing Email</label>
			<input type="email" name="billing_email" value="{{ old('billing_email') }}" class="form-control @error('billing_email') is-invalid @enderror">
			@error('billing_email')<div class="invalid-feedback">{{ $message }}</div>@enderror
		</div>
		<div class="mb-3">
			<label class="form-label fw-bold">Notes</label>
			<textarea name="notes" rows="4" class="form-control @error('notes') is-invalid @enderror">{{ old('notes') }}</textarea>
			@error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
		</div>
		<div class="form-check mb-3">
			<input class="form-check-input" type="checkbox" value="1" name="active" id="activeCheck" checked>
			<label class="form-check-label" for="activeCheck">Active</label>
		</div>
		<div class="mb-3">
			<button type="submit" class="btn btn-primary">Create Client</button>
		</div>
	</form>
@endsection

@section('sidebar')
	<div class="p-3 small text-muted">Help text (later)</div>
@endsection

@section('rightbar')
	<div class="p-3 small text-muted">Widgets (later)</div>
@endsection

