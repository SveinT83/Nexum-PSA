@extends('layouts.default_tech')

@section('title', 'New Storage Box')

@section('sidebar')
    <x-nav.storage-menu />
@endsection

@section('pageHeader')
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="{{ route('tech.storage.index') }}">Storage</a></li>
            <li class="breadcrumb-item active" aria-current="page">New Box</li>
        </ol>
    </nav>
    <h1>New Storage Box</h1>
@endsection

@section('content')
    <div class="container-fluid">
        <form method="POST" action="{{ route('tech.storage.boxes.store') }}">
            @csrf
            <div class="card mb-4">
                <div class="card-header"><h5 class="mb-0">Identity & Location</h5></div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="warehouse_id" class="form-label">Warehouse</label>
                            <select id="warehouse_id" name="warehouse_id" class="form-select" required>
                                <option value="">Select warehouse</option>
                                @foreach($warehouses as $warehouse)
                                    <option value="{{ $warehouse->id }}" @selected((string) old('warehouse_id', $defaultWarehouse?->id) === (string) $warehouse->id)>{{ $warehouse->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="code_human" class="form-label">Human Code</label>
                            <input type="text" id="code_human" name="code_human" class="form-control" value="{{ old('code_human') }}" placeholder="VAN-01">
                        </div>
                        <div class="col-md-8">
                            <label for="name" class="form-label">Name</label>
                            <input type="text" id="name" name="name" class="form-control" value="{{ old('name') }}">
                        </div>
                        <div class="col-md-4">
                            <label for="status" class="form-label">Status</label>
                            <select id="status" name="status" class="form-select">
                                <option value="in_stock">In stock</option>
                                <option value="in_transit">In transit</option>
                                <option value="loaned">Loaned</option>
                                <option value="at_customer">At customer</option>
                                <option value="lost">Lost</option>
                                <option value="retired">Retired</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="barcode_value" class="form-label">Barcode Value</label>
                            <input type="text" id="barcode_value" name="barcode_value" class="form-control" value="{{ old('barcode_value') }}" placeholder="Assigned after save if blank">
                        </div>
                        <div class="col-md-6">
                            <label for="barcode_type" class="form-label">Barcode Type</label>
                            <select id="barcode_type" name="barcode_type" class="form-select">
                                <option value="QR">QR</option>
                                <option value="EAN13">EAN13</option>
                                <option value="CODE128">CODE128</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label for="placement_note" class="form-label">Placement Note</label>
                            <textarea id="placement_note" name="placement_note" class="form-control" rows="3">{{ old('placement_note') }}</textarea>
                        </div>
                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" checked>
                                <label class="form-check-label" for="is_active">Active</label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">Save Box</button>
                <a href="{{ route('tech.storage.index') }}" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
@endsection

@section('rightbar')
    <div class="card">
        <div class="card-header"><h5 class="mb-0">Label</h5></div>
        <div class="card-body small text-muted">
            Barcode value defaults to the database box ID when left blank.
        </div>
    </div>
@endsection
