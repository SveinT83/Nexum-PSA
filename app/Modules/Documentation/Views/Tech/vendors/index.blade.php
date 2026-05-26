@extends('layouts.default_tech')

@php
    $isSupplierView = $role === 'suppliers';
    $pageTitle = $isSupplierView ? 'Suppliers' : 'Vendors';
    $createRoute = $isSupplierView ? route('tech.documentations.suppliers.create') : route('tech.documentations.vendors.create');
@endphp

@section('title', $pageTitle)

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center">
        <h1 class="mb-0">{{ $pageTitle }}</h1>
        <x-buttons.back url="{{ route('tech.documentations.index', ['cat' => 'all']) }}" class="mb-0">Back</x-buttons.back>
    </div>
@endsection

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Vendor and supplier search -->
    <!-- ------------------------------------------------- -->
    <form method="GET" action="{{ route('tech.documentations.index') }}" class="card mb-3">
        <div class="card-body">
            <label for="vendor_search" class="form-label text-muted small fw-bold text-uppercase">Search</label>
            <div class="input-group input-group-sm">
                <input id="vendor_search" type="search" name="q" value="{{ $search }}" class="form-control" placeholder="Name, code, org no, email, or website">
                <input type="hidden" name="cat" value="{{ $role }}">
                <button class="btn btn-outline-secondary" type="submit">Search</button>
            </div>
        </div>
    </form>

    <!-- ------------------------------------------------- -->
    <!-- Vendor and supplier master data list -->
    <!-- ------------------------------------------------- -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center gap-3">
            <div class="d-flex align-items-center gap-2">
                <h2 class="h6 mb-0">{{ $pageTitle }}</h2>
                <span class="badge text-bg-light border">{{ $vendors->total() }}</span>
            </div>
            <x-buttons.addlink :url="$createRoute" class="mb-0">
                New {{ $isSupplierView ? 'Supplier' : 'Vendor' }}
            </x-buttons.addlink>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Name</th>
                        <th>Code</th>
                        <th>Org No.</th>
                        <th>Roles</th>
                        <th>Email</th>
                        <th>Website</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($vendors as $vendor)
                        @php
                            $websiteUrl = $vendor->url && ! \Illuminate\Support\Str::startsWith($vendor->url, ['http://', 'https://'])
                                ? 'https://' . $vendor->url
                                : $vendor->url;
                        @endphp
                        <tr class="cursor-pointer" data-href="{{ route('tech.documentations.vendors.show', $vendor) }}" onclick="window.location.href = this.dataset.href">
                            <td>
                                <a href="{{ route('tech.documentations.vendors.show', $vendor) }}" class="fw-semibold text-decoration-none" onclick="event.stopPropagation()">
                                    {{ $vendor->name }}
                                </a>
                            </td>
                            <td>{{ $vendor->vendor_code ?: '—' }}</td>
                            <td>{{ $vendor->org_no ?: '—' }}</td>
                            <td>
                                <div class="d-flex flex-wrap gap-1">
                                    @if($vendor->is_vendor)
                                        <span class="badge text-bg-secondary">Vendor</span>
                                    @endif
                                    @if($vendor->is_manufacturer)
                                        <span class="badge text-bg-info">Manufacturer</span>
                                    @endif
                                    @if($vendor->is_supplier)
                                        <span class="badge text-bg-primary">Supplier</span>
                                    @endif
                                </div>
                            </td>
                            <td>{{ $vendor->email ?: '—' }}</td>
                            <td>
                                @if($vendor->url)
                                    <a href="{{ $websiteUrl }}" target="_blank" rel="noopener" onclick="event.stopPropagation()">{{ $vendor->url }}</a>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td>
                                @if($vendor->is_active)
                                    <span class="badge text-bg-success">Active</span>
                                @else
                                    <span class="badge text-bg-secondary">Inactive</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">No {{ strtolower($pageTitle) }} found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($vendors->hasPages())
            <div class="card-footer">
                {{ $vendors->links() }}
            </div>
        @endif
    </div>
@endsection

@section('sidebar')
    <x-nav.knowledge-menu />

    <hr class="my-3">

    <x-nav.side-bar :items="$sidebarMenuItems" title="Documentation categories" />
@endsection

@section('rightbar')
@endsection
