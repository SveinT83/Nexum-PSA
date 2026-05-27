@extends('layouts.default_tech')

@section('title', 'Edit ' . $item->sku)

@section('sidebar')
    <x-nav.storage-menu />
@endsection

@section('pageHeader')
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="{{ route('tech.storage.index') }}">Storage</a></li>
            <li class="breadcrumb-item"><a href="{{ route('tech.storage.items.show', $item) }}">{{ $item->sku }}</a></li>
            <li class="breadcrumb-item active" aria-current="page">Edit</li>
        </ol>
    </nav>
    <h1>Edit {{ $item->sku }}</h1>
@endsection

@section('content')
    <div class="container-fluid">
        <form method="POST" action="{{ route('tech.storage.items.update', $item) }}">
            @csrf
            @method('PATCH')

            <div class="card mb-4">
                <div class="card-header"><h5 class="mb-0">General</h5></div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label for="sku" class="form-label">SKU</label>
                            <input type="text" id="sku" name="sku" class="form-control @error('sku') is-invalid @enderror" value="{{ old('sku', $item->sku) }}" required>
                            @error('sku')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-8">
                            <label for="name" class="form-label">Name</label>
                            <input type="text" id="name" name="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $item->name) }}" required>
                            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label for="warehouse_id" class="form-label">Warehouse</label>
                            <select id="warehouse_id" name="warehouse_id" class="form-select @error('warehouse_id') is-invalid @enderror" required>
                                <option value="">Select warehouse</option>
                                @foreach($warehouses as $warehouse)
                                    <option value="{{ $warehouse->id }}" @selected((string) old('warehouse_id', $item->warehouse_id) === (string) $warehouse->id)>{{ $warehouse->name }}</option>
                                @endforeach
                            </select>
                            @error('warehouse_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label for="box_id" class="form-label">Box</label>
                            <select id="box_id" name="box_id" class="form-select @error('box_id') is-invalid @enderror">
                                <option value="">Unboxed</option>
                                @foreach($boxes as $box)
                                    <option value="{{ $box->id }}" @selected((string) old('box_id', $item->box_id) === (string) $box->id)>
                                        {{ $box->code_human ?: 'Box #' . $box->id }} - {{ $box->warehouse->name }}
                                    </option>
                                @endforeach
                            </select>
                            @error('box_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label for="ean_number" class="form-label">EAN / Barcode</label>
                            <input type="text" id="ean_number" name="ean_number" class="form-control @error('ean_number') is-invalid @enderror" value="{{ old('ean_number', $item->ean_number) }}">
                            @error('ean_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label for="status" class="form-label">Status</label>
                            <select id="status" name="status" class="form-select @error('status') is-invalid @enderror">
                                <option value="active" @selected(old('status', $item->status) === 'active')>Active</option>
                                <option value="inactive" @selected(old('status', $item->status) === 'inactive')>Inactive</option>
                            </select>
                            @error('status')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12">
                            <label for="short_description" class="form-label">Short Description</label>
                            <textarea id="short_description" name="short_description" class="form-control @error('short_description') is-invalid @enderror" rows="2">{{ old('short_description', $item->short_description) }}</textarea>
                            @error('short_description')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12">
                            <label for="long_description" class="form-label">Long Description</label>
                            <textarea id="long_description" name="long_description" class="form-control @error('long_description') is-invalid @enderror" rows="4">{{ old('long_description', $item->long_description) }}</textarea>
                            @error('long_description')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header"><h5 class="mb-0">Vendor & Supplier</h5></div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="manufacturer_vendor_id" class="form-label">Vendor / Manufacturer</label>
                            <select id="manufacturer_vendor_id" name="manufacturer_vendor_id" class="form-select @error('manufacturer_vendor_id') is-invalid @enderror">
                                <option value="">Select existing</option>
                                @foreach($manufacturers as $manufacturer)
                                    <option value="{{ $manufacturer->id }}" @selected((string) old('manufacturer_vendor_id', $item->manufacturer_vendor_id) === (string) $manufacturer->id)>{{ $manufacturer->name }}</option>
                                @endforeach
                            </select>
                            @error('manufacturer_vendor_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text">
                                <a href="{{ route('tech.documentations.vendors.create') }}" target="_blank" rel="noopener">New vendor</a>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label for="manufacturer_part_number" class="form-label">Manufacturer Part No.</label>
                            <input type="text" id="manufacturer_part_number" name="manufacturer_part_number" class="form-control @error('manufacturer_part_number') is-invalid @enderror" value="{{ old('manufacturer_part_number', $item->manufacturer_part_number) }}">
                            @error('manufacturer_part_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-md-4">
                            <label for="primary_vendor_id" class="form-label">Supplier</label>
                            <select id="primary_vendor_id" name="primary_vendor_id" class="form-select @error('primary_vendor_id') is-invalid @enderror">
                                <option value="">Select existing</option>
                                @foreach($suppliers as $supplier)
                                    <option value="{{ $supplier->id }}" @selected((string) old('primary_vendor_id', $item->primary_vendor_id) === (string) $supplier->id)>{{ $supplier->name }}</option>
                                @endforeach
                            </select>
                            @error('primary_vendor_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text">
                                <a href="{{ route('tech.documentations.suppliers.create') }}" target="_blank" rel="noopener">New supplier</a>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label for="supplier_sku" class="form-label">Supplier SKU</label>
                            <input type="text" id="supplier_sku" name="supplier_sku" class="form-control @error('supplier_sku') is-invalid @enderror" value="{{ old('supplier_sku', $primarySupplierLine?->vendor_sku) }}">
                            @error('supplier_sku')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-md-8">
                            <label for="supplier_purchase_url" class="form-label">Purchase URL</label>
                            <input type="url" id="supplier_purchase_url" name="supplier_purchase_url" class="form-control @error('supplier_purchase_url') is-invalid @enderror" value="{{ old('supplier_purchase_url', $primarySupplierLine?->purchase_url) }}" placeholder="https://">
                            @error('supplier_purchase_url')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-2">
                            <label for="supplier_currency" class="form-label">Currency</label>
                            <input type="text" id="supplier_currency" name="supplier_currency" class="form-control @error('supplier_currency') is-invalid @enderror" value="{{ old('supplier_currency', $primarySupplierLine?->currency ?? 'NOK') }}" maxlength="3">
                            @error('supplier_currency')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-2">
                            <label for="supplier_lead_time_days" class="form-label">Lead Time</label>
                            <input type="number" id="supplier_lead_time_days" name="supplier_lead_time_days" class="form-control @error('supplier_lead_time_days') is-invalid @enderror" value="{{ old('supplier_lead_time_days', $primarySupplierLine?->lead_time_days ?? $item->lead_time_days) }}" min="0">
                            @error('supplier_lead_time_days')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-2">
                            <label for="supplier_moq" class="form-label">Supplier MOQ</label>
                            <input type="number" id="supplier_moq" name="supplier_moq" class="form-control @error('supplier_moq') is-invalid @enderror" value="{{ old('supplier_moq', $primarySupplierLine?->moq ?? $item->moq) }}" min="1">
                            @error('supplier_moq')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-2">
                            <label for="supplier_pack_size" class="form-label">Pack Size</label>
                            <input type="number" id="supplier_pack_size" name="supplier_pack_size" class="form-control @error('supplier_pack_size') is-invalid @enderror" value="{{ old('supplier_pack_size', $primarySupplierLine?->pack_size ?? 1) }}" min="1">
                            @error('supplier_pack_size')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header"><h5 class="mb-0">Stock & Pricing</h5></div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label for="reorder_point" class="form-label">Reorder Point</label>
                            <input type="number" id="reorder_point" name="reorder_point" class="form-control @error('reorder_point') is-invalid @enderror" value="{{ old('reorder_point', $item->reorder_point) }}" min="0">
                            @error('reorder_point')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-3">
                            <label for="target_level" class="form-label">Target Level</label>
                            <input type="number" id="target_level" name="target_level" class="form-control @error('target_level') is-invalid @enderror" value="{{ old('target_level', $item->target_level) }}" min="0">
                            @error('target_level')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-3">
                            <label for="moq" class="form-label">MOQ</label>
                            <input type="number" id="moq" name="moq" class="form-control @error('moq') is-invalid @enderror" value="{{ old('moq', $item->moq) }}" min="1">
                            @error('moq')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-3">
                            <label for="lead_time_days" class="form-label">Lead Time Days</label>
                            <input type="number" id="lead_time_days" name="lead_time_days" class="form-control @error('lead_time_days') is-invalid @enderror" value="{{ old('lead_time_days', $item->lead_time_days) }}" min="0">
                            @error('lead_time_days')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-3">
                            <label for="purchase_price" class="form-label">Purchase Price</label>
                            <input type="number" step="0.01" id="purchase_price" name="purchase_price" class="form-control @error('purchase_price') is-invalid @enderror" value="{{ old('purchase_price', $item->purchase_price) }}" min="0">
                            @error('purchase_price')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-3">
                            <label for="markup_percent" class="form-label">Markup %</label>
                            <input type="number" step="0.01" id="markup_percent" name="markup_percent" class="form-control @error('markup_percent') is-invalid @enderror" value="{{ old('markup_percent', $item->markup_percent) }}" min="0">
                            @error('markup_percent')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-3">
                            <label for="sale_price" class="form-label">Sale Price</label>
                            <input type="number" step="0.01" id="sale_price" name="sale_price" class="form-control @error('sale_price') is-invalid @enderror" value="{{ old('sale_price', $item->sale_price) }}" min="0">
                            @error('sale_price')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-3">
                            <label for="vat_rate" class="form-label">VAT Rate</label>
                            <input type="number" step="0.01" id="vat_rate" name="vat_rate" class="form-control @error('vat_rate') is-invalid @enderror" value="{{ old('vat_rate', $item->vat_rate ?? $defaultVatRate) }}" min="0">
                            @error('vat_rate')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <div class="form-check form-switch mt-4">
                                <input class="form-check-input" type="checkbox" id="has_serials" name="has_serials" value="1" @checked(old('has_serials', $item->has_serials))>
                                <label class="form-check-label" for="has_serials">Require serials on withdrawal/sale</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check form-switch mt-4">
                                <input class="form-check-input" type="checkbox" id="should_order" name="should_order" value="1" @checked(old('should_order', $item->should_order))>
                                <label class="form-check-label" for="should_order">Manual should-order flag</label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">Save Item</button>
                <a href="{{ route('tech.storage.items.show', $item) }}" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
@endsection

@section('rightbar')
    @include('storage::Tech.Storage.items.partials.documentation-card')
@endsection
