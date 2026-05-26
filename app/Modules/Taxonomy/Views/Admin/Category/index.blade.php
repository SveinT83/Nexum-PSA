@extends('layouts.default_tech')

@section('title', 'Category Management')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center">
        <h1>Admin - Category</h1>
        <x-buttons.back url="{{ route('tech.admin.index') }}">Back</x-buttons.back>
    </div>
@endsection

@section('content')
    @php
        $sortLink = function (string $column, string $defaultDirection = 'asc') use ($sort, $direction) {
            $nextDirection = $sort === $column && $direction === 'asc' ? 'desc' : ($sort === $column ? 'asc' : $defaultDirection);

            return request()->fullUrlWithQuery([
                'sort' => $column,
                'direction' => $nextDirection,
            ]);
        };
        $sortIcon = function (string $column) use ($sort, $direction) {
            if ($sort !== $column) {
                return 'bi-arrow-down-up';
            }

            return $direction === 'asc' ? 'bi-sort-alpha-down' : 'bi-sort-alpha-up';
        };
    @endphp

    <!-- -------------------------------------------------------------------------------------------------- -->
    <!-- Section: Alert Messages -->
    <!-- -------------------------------------------------------------------------------------------------- -->
    @if(session('error'))
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill"></i> {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    @endif

    <!-- -------------------------------------------------------------------------------------------------- -->
    <!-- Section: Category List -->
    <!-- -------------------------------------------------------------------------------------------------- -->
    <x-card.default title="System Categories">
        <x-slot:headerActions>
            <button type="button" class="btn btn-sm btn-primary bi bi-plus" data-bs-toggle="modal" data-bs-target="#createCategoryModal"> Add Category</button>
        </x-slot:headerActions>

        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th>
                            <a href="{{ $sortLink('name') }}" class="text-decoration-none text-body">
                                Name <i class="bi {{ $sortIcon('name') }}"></i>
                            </a>
                        </th>
                        <th>
                            <a href="{{ $sortLink('type') }}" class="text-decoration-none text-body">
                                Type <i class="bi {{ $sortIcon('type') }}"></i>
                            </a>
                        </th>
                        <th>
                            <a href="{{ $sortLink('parent') }}" class="text-decoration-none text-body">
                                Parent <i class="bi {{ $sortIcon('parent') }}"></i>
                            </a>
                        </th>
                        <th>
                            <a href="{{ $sortLink('slug') }}" class="text-decoration-none text-body">
                                Slug <i class="bi {{ $sortIcon('slug') }}"></i>
                            </a>
                        </th>
                        <th class="text-center">
                            <a href="{{ $sortLink('status', 'desc') }}" class="text-decoration-none text-body">
                                Status <i class="bi {{ $sortIcon('status') }}"></i>
                            </a>
                        </th>
                        <th class="text-center">
                            <span class="text-muted">Usage</span>
                            <span class="ms-1">
                                <a href="{{ $sortLink('templates', 'desc') }}" class="text-decoration-none text-body" title="Sort by documentation template usage">
                                    <i class="bi bi-file-earmark-text {{ $sort === 'templates' ? 'text-primary' : '' }}"></i>
                                </a>
                                <a href="{{ $sortLink('services', 'desc') }}" class="text-decoration-none text-body" title="Sort by service usage">
                                    <i class="bi bi-gear {{ $sort === 'services' ? 'text-primary' : '' }}"></i>
                                </a>
                                <a href="{{ $sortLink('children', 'desc') }}" class="text-decoration-none text-body" title="Sort by sub-category usage">
                                    <i class="bi bi-diagram-3 {{ $sort === 'children' ? 'text-primary' : '' }}"></i>
                                </a>
                            </span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($categories as $category)
                        <tr>
                            <td>
                                <a href="#"
                                   class="fw-bold text-decoration-none"
                                   data-bs-toggle="modal"
                                   data-bs-target="#editCategoryModal{{ $category->id }}">
                                    {{ $category->name }}
                                </a>
                                @if($category->description)
                                    <br><small class="text-muted">{{ Str::limit($category->description, 50) }}</small>
                                @endif
                            </td>
                            <td><span class="badge bg-info text-dark">{{ $category->type ?? 'General' }}</span></td>
                            <td>
                                @if($category->parent)
                                    <span class="badge bg-secondary">{{ $category->parent->name }}</span>
                                @else
                                    <span class="text-muted small">None</span>
                                @endif
                            </td>
                            <td><code>{{ $category->slug }}</code></td>
                            <td class="text-center">
                                @if($category->is_active)
                                    <span class="badge bg-success">Active</span>
                                @else
                                    <span class="badge bg-danger">Inactive</span>
                                @endif
                            </td>
                            <td class="text-center">
                                <span class="badge rounded-pill bg-primary" title="Documentation Templates">
                                    <i class="bi bi-file-earmark-text"></i> {{ $category->templates_count }}
                                </span>
                                <span class="badge rounded-pill bg-info text-dark" title="Services">
                                    <i class="bi bi-gear"></i> {{ $category->services_count }}
                                </span>
                                <span class="badge rounded-pill bg-secondary" title="Sub-categories">
                                    <i class="bi bi-diagram-3"></i> {{ $category->children_count }}
                                </span>
                            </td>
                        </tr>

                        <!-- Edit Category Modal -->
                        <div class="modal fade" id="editCategoryModal{{ $category->id }}" tabindex="-1" aria-hidden="true">
                            <div class="modal-dialog">
                                <form action="{{ route('tech.admin.system.category.update', $category) }}" method="POST">
                                    @csrf
                                    @method('PUT')
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Edit Category: {{ $category->name }}</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body">
                                            <div class="mb-3">
                                                <x-forms.input_text name="name" labelName="Category Name" :value="$category->name" layout="vertical" inputVar="required" />
                                            </div>
                                            <div class="mb-3">
                                                <x-forms.input_text name="type" labelName="Type (e.g., service, documentation)" :value="$category->type" layout="vertical" />
                                            </div>
                                            <div class="mb-3">
                                                <x-forms.select name="parent_id" labelName="Parent Category" layout="vertical">
                                                    <option value="">-- No Parent --</option>
                                                    @foreach($parentCategories as $parent)
                                                        @if($parent->id != $category->id)
                                                            <option value="{{ $parent->id }}" {{ $category->parent_id == $parent->id ? 'selected' : '' }}>
                                                                {{ $parent->name }}
                                                            </option>
                                                        @endif
                                                    @endforeach
                                                </x-forms.select>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label fw-bold">Description</label>
                                                <textarea name="description" class="form-control" rows="3">{{ $category->description }}</textarea>
                                            </div>
                                            <div class="mb-3">
                                                <div class="form-check form-switch">
                                                    <input class="form-check-input" type="checkbox" name="is_active" value="1" id="activeSwitch{{ $category->id }}" {{ $category->is_active ? 'checked' : '' }}>
                                                    <label class="form-check-label fw-bold" for="activeSwitch{{ $category->id }}">Active Status</label>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="modal-footer d-flex justify-content-between">
                                            <div>
                                                @php
                                                    $isUsed = $category->templates_count > 0 || $category->services_count > 0 || $category->children_count > 0;
                                                @endphp
                                                @if(!$isUsed)
                                                    <button type="button"
                                                            class="btn btn-outline-danger"
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#deleteCategoryModal{{ $category->id }}">
                                                        <i class="bi bi-trash"></i> Delete Category
                                                    </button>
                                                @else
                                                    <button type="button" class="btn btn-outline-danger disabled" title="Category is in use and cannot be deleted">
                                                        <i class="bi bi-trash"></i> Delete
                                                    </button>
                                                @endif
                                            </div>
                                            <div>
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                <button type="submit" class="btn btn-primary">Save Changes</button>
                                            </div>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <!-- Separate Delete Confirmation Modal to avoid nested modals issue -->
                        @if(!$isUsed)
                            <div class="modal fade" id="deleteCategoryModal{{ $category->id }}" tabindex="-1" aria-hidden="true">
                                <div class="modal-dialog">
                                    <form action="{{ route('tech.admin.system.category.destroy', $category) }}" method="POST">
                                        @csrf
                                        @method('DELETE')
                                        <div class="modal-content">
                                            <div class="modal-header">
                                                <h5 class="modal-title text-danger">Confirm Deletion</h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                            </div>
                                            <div class="modal-body">
                                                Are you sure you want to delete the category <strong>{{ $category->name }}</strong>?
                                                <br>
                                                <span class="small text-muted">This action cannot be undone and will remove the category from the system.</span>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                <button type="submit" class="btn btn-danger">Permanently Delete</button>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        @endif
                    @empty
                        <tr>
                            <td colspan="6" class="text-center py-4 text-muted">No categories found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card.default>

    <!-- Create Category Modal -->
    <div class="modal fade" id="createCategoryModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <form action="{{ route('tech.admin.system.category.store') }}" method="POST">
                @csrf
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Add New Category</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <x-forms.input_text name="name" labelName="Category Name" layout="vertical" placeholder="Enter category name" inputVar="required" />
                        </div>
                        <div class="mb-3">
                            <x-forms.input_text name="type" labelName="Type" layout="vertical" placeholder="e.g., service, documentation" />
                        </div>
                        <div class="mb-3">
                            <x-forms.select name="parent_id" labelName="Parent Category" layout="vertical">
                                <option value="">-- No Parent --</option>
                                @foreach($parentCategories as $parent)
                                    <option value="{{ $parent->id }}">{{ $parent->name }}</option>
                                @endforeach
                            </x-forms.select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Description</label>
                            <textarea name="description" class="form-control" rows="3" placeholder="Enter optional description"></textarea>
                        </div>
                        <div class="mb-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="is_active" value="1" id="createActiveSwitch" checked>
                                <label class="form-check-label fw-bold" for="createActiveSwitch">Active Status</label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Create Category</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
@endsection

@section('sidebar')
    <x-nav.admin-menu group="system" />
@endsection

@section('rightbar')
    <x-card.default title="Category Info">
        <p class="small text-muted">
            Categories are used to organize various entities across the system, such as:
        </p>
        <ul class="small">
            <li>Documentation Templates</li>
            <li>Client Documentation</li>
            <li>Services (Taxonomy)</li>
        </ul>
        <hr>
        <div class="d-grid gap-2">
            <a href="{{ route('tech.admin.index') }}" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Back to Admin
            </a>
        </div>
    </x-card.default>
@endsection
