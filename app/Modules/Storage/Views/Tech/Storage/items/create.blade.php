@extends('layouts.default_tech')

@section('title', 'New Storage Item')

@section('sidebar')
    <x-nav.storage-menu />
@endsection

@section('pageHeader')
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="{{ route('tech.storage.index') }}">Storage</a></li>
            <li class="breadcrumb-item active" aria-current="page">New Item</li>
        </ol>
    </nav>
    <h1>New Storage Item</h1>
@endsection

@section('content')
    <div class="container-fluid">
        <form method="POST" action="{{ route('tech.storage.items.store') }}">
            @csrf
            <div class="card mb-4">
                <div class="card-header"><h5 class="mb-0">General</h5></div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label for="sku" class="form-label">SKU</label>
                            <input type="text" id="sku" name="sku" class="form-control" value="{{ old('sku') }}" required>
                        </div>
                        <div class="col-md-8">
                            <label for="name" class="form-label">Name</label>
                            <input type="text" id="name" name="name" class="form-control" value="{{ old('name') }}" required>
                        </div>
                        <div class="col-md-6">
                            <label for="warehouse_id" class="form-label">Warehouse</label>
                            <select id="warehouse_id" name="warehouse_id" class="form-select" required>
                                <option value="">Select warehouse</option>
                                @foreach($warehouses as $warehouse)
                                    <option value="{{ $warehouse->id }}" @selected(old('warehouse_id') == $warehouse->id)>{{ $warehouse->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="box_id" class="form-label">Box</label>
                            <select id="box_id" name="box_id" class="form-select">
                                <option value="">Unboxed</option>
                                @foreach($boxes as $box)
                                    <option value="{{ $box->id }}" @selected(old('box_id') == $box->id)>
                                        {{ $box->code_human ?: 'Box #' . $box->id }} - {{ $box->warehouse->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="ean_number" class="form-label">EAN / Barcode</label>
                            <input type="text" id="ean_number" name="ean_number" class="form-control" value="{{ old('ean_number') }}">
                        </div>
                        <div class="col-md-6">
                            <label for="status" class="form-label">Status</label>
                            <select id="status" name="status" class="form-select">
                                <option value="active" @selected(old('status', 'active') === 'active')>Active</option>
                                <option value="inactive" @selected(old('status') === 'inactive')>Inactive</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label for="short_description" class="form-label">Short Description</label>
                            <textarea id="short_description" name="short_description" class="form-control" rows="2">{{ old('short_description') }}</textarea>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header"><h5 class="mb-0">Stock & Pricing</h5></div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label for="initial_quantity" class="form-label">Initial Quantity</label>
                            <input type="number" id="initial_quantity" name="initial_quantity" class="form-control" value="{{ old('initial_quantity', 0) }}" min="0">
                        </div>
                        <div class="col-md-3">
                            <label for="reorder_point" class="form-label">Reorder Point</label>
                            <input type="number" id="reorder_point" name="reorder_point" class="form-control" value="{{ old('reorder_point', 0) }}" min="0">
                        </div>
                        <div class="col-md-3">
                            <label for="target_level" class="form-label">Target Level</label>
                            <input type="number" id="target_level" name="target_level" class="form-control" value="{{ old('target_level', 0) }}" min="0">
                        </div>
                        <div class="col-md-3">
                            <label for="moq" class="form-label">MOQ</label>
                            <input type="number" id="moq" name="moq" class="form-control" value="{{ old('moq', 1) }}" min="1">
                        </div>
                        <div class="col-md-4">
                            <label for="purchase_price" class="form-label">Purchase Price</label>
                            <input type="number" step="0.01" id="purchase_price" name="purchase_price" class="form-control" value="{{ old('purchase_price') }}" min="0">
                        </div>
                        <div class="col-md-4">
                            <label for="markup_percent" class="form-label">Markup %</label>
                            <input type="number" step="0.01" id="markup_percent" name="markup_percent" class="form-control" value="{{ old('markup_percent') }}" min="0">
                        </div>
                        <div class="col-md-4">
                            <label for="sale_price" class="form-label">Sale Price</label>
                            <input type="number" step="0.01" id="sale_price" name="sale_price" class="form-control" value="{{ old('sale_price') }}" min="0">
                        </div>
                        <div class="col-md-6">
                            <div class="form-check form-switch mt-4">
                                <input class="form-check-input" type="checkbox" id="has_serials" name="has_serials" value="1" @checked(old('has_serials'))>
                                <label class="form-check-label" for="has_serials">Require serials on withdrawal/sale</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check form-switch mt-4">
                                <input class="form-check-input" type="checkbox" id="should_order" name="should_order" value="1" @checked(old('should_order'))>
                                <label class="form-check-label" for="should_order">Manual should-order flag</label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">Save Item</button>
                <a href="{{ route('tech.storage.index') }}" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
@endsection

@section('rightbar')
    <div class="card">
        <div class="card-header"><h5 class="mb-0">Rules</h5></div>
        <div class="card-body small text-muted">
            Initial quantity creates an immutable stock movement. Available stock is on-hand minus reserved, clamped to zero in the UI.
        </div>
    </div>
@endsection
