@extends('layouts.default_tech')

@section('title', 'Inventory Settings')

@section('sidebar')
    <x-nav.storage-menu />
@endsection

@section('pageHeader')
    <div class="d-flex align-items-center justify-content-between gap-3">
        <h1 class="mb-0">Inventory Settings</h1>
        <x-buttons.back :url="route('tech.storage.index')" class="mb-0">
            Back
        </x-buttons.back>
    </div>
@endsection

@section('content')
    @php
        $showWarehouseModal = old('_warehouse_form') === '1' || $errors->any();
    @endphp

    <div class="container-fluid">
        {{-- Warehouse administration lives outside the daily inventory work queue. --}}
        <div class="card">
            <div class="card-header d-flex align-items-center justify-content-between gap-3">
                <div>
                    <h2 class="h6 mb-0">Warehouses</h2>
                    <div class="small text-muted">{{ $warehouses->count() }} configured</div>
                </div>
                <button type="button" class="btn btn-sm btn-primary bi bi-plus" data-bs-toggle="modal" data-bs-target="#storageAddWarehouseModal">
                    Add Warehouse
                </button>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                    <tr>
                        <th>Name</th>
                        <th>Code</th>
                        <th>Address</th>
                        <th class="text-end">Items</th>
                        <th class="text-end">Boxes</th>
                        <th>Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($warehouses as $warehouse)
                        <tr>
                            <td class="fw-semibold">{{ $warehouse->name }}</td>
                            <td>{{ $warehouse->code ?: '—' }}</td>
                            <td>
                                @if($warehouse->address)
                                    {{ $warehouse->address }}
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td class="text-end">{{ $warehouse->items_count }}</td>
                            <td class="text-end">{{ $warehouse->boxes_count }}</td>
                            <td>
                                @if($warehouse->is_active)
                                    <span class="badge text-bg-success">Active</span>
                                @else
                                    <span class="badge text-bg-secondary">Inactive</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-5">
                                No warehouses have been configured yet.
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Warehouse creation is an inventory administration action. --}}
    <div class="modal fade" id="storageAddWarehouseModal" tabindex="-1" aria-labelledby="storageAddWarehouseModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST" action="{{ route('tech.admin.settings.storage.inventory.warehouses.store') }}">
                    @csrf
                    <input type="hidden" name="_warehouse_form" value="1">

                    <div class="modal-header">
                        <h5 class="modal-title" id="storageAddWarehouseModalLabel">Add Warehouse</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="warehouse_name" class="form-label">Name</label>
                            <input type="text" id="warehouse_name" name="name" class="form-control @error('name') is-invalid @enderror"
                                   value="{{ old('name') }}" required autofocus>
                            @error('name')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="warehouse_code" class="form-label">Code</label>
                            <input type="text" id="warehouse_code" name="code" class="form-control @error('code') is-invalid @enderror"
                                   value="{{ old('code') }}" placeholder="MAIN">
                            @error('code')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="warehouse_address" class="form-label">Address</label>
                            <textarea id="warehouse_address" name="address" rows="2" class="form-control @error('address') is-invalid @enderror">{{ old('address') }}</textarea>
                            @error('address')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div>
                            <label for="warehouse_notes" class="form-label">Notes</label>
                            <textarea id="warehouse_notes" name="notes" rows="3" class="form-control @error('notes') is-invalid @enderror">{{ old('notes') }}</textarea>
                            @error('notes')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-sm btn-primary">Create Warehouse</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    @if($showWarehouseModal)
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                const modalElement = document.getElementById('storageAddWarehouseModal');

                if (modalElement && window.bootstrap) {
                    window.bootstrap.Modal.getOrCreateInstance(modalElement).show();
                }
            });
        </script>
    @endif
@endsection
