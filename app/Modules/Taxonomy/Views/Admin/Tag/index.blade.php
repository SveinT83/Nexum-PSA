@extends('layouts.default_tech')

@section('title', 'Tag Management')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center">
        <h1>Tag Management</h1>
        <x-buttons.back url="{{ route('tech.admin.index') }}">Back</x-buttons.back>
    </div>
@endsection

@section('sidebar')
    <x-nav.admin-menu group="system" />
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

    <div class="row">
        <div class="col-md-12">
            <x-card.default title="Existing Tags">
                <x-slot:headerActions>
                    <button type="button" class="btn btn-sm btn-primary bi bi-plus" data-bs-toggle="modal" data-bs-target="#createTagModal"> New Tag</button>
                </x-slot:headerActions>

                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>
                                <a href="{{ $sortLink('name') }}" class="text-decoration-none text-body">
                                    Name <i class="bi {{ $sortIcon('name') }}"></i>
                                </a>
                            </th>
                            <th>
                                <a href="{{ $sortLink('color') }}" class="text-decoration-none text-body">
                                    Color <i class="bi {{ $sortIcon('color') }}"></i>
                                </a>
                            </th>
                            <th>
                                <a href="{{ $sortLink('usages', 'desc') }}" class="text-decoration-none text-body">
                                    Usages <i class="bi {{ $sortIcon('usages') }}"></i>
                                </a>
                            </th>
                            <th>
                                <a href="{{ $sortLink('status', 'desc') }}" class="text-decoration-none text-body">
                                    Status <i class="bi {{ $sortIcon('status') }}"></i>
                                </a>
                            </th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($tags as $tag)
                            <tr>
                                <td>
                                    <span class="badge" style="background-color: {{ $tag->color ?? '#6c757d' }}">
                                        {{ $tag->name }}
                                    </span>
                                    @if($tag->description)
                                        <br><small class="text-muted">{{ $tag->description }}</small>
                                    @endif
                                </td>
                                <td><code>{{ $tag->color }}</code></td>
                                <td>{{ $tag->usages_count }}</td>
                                <td>
                                    @if($tag->active)
                                        <span class="badge bg-success">Active</span>
                                    @else
                                        <span class="badge bg-secondary">Inactive</span>
                                    @endif
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#editTagModal{{ $tag->id }}">
                                        Edit
                                    </button>
                                    <x-buttons.delete
                                        :url="route('tech.admin.system.tag.destroy', $tag)"
                                        :name="$tag->name"
                                        class="btn btn-sm btn-outline-danger bi bi-trash"
                                    >
                                        Delete
                                    </x-buttons.delete>
                                </td>
                            </tr>

                            <!-- Edit Modal -->
                            <div class="modal fade" id="editTagModal{{ $tag->id }}" tabindex="-1">
                                <div class="modal-dialog">
                                    <form action="{{ route('tech.admin.system.tag.update', $tag) }}" method="POST">
                                        @csrf
                                        @method('PUT')
                                        <div class="modal-content">
                                            <div class="modal-header">
                                                <h5 class="modal-title">Edit Tag: {{ $tag->name }}</h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-content-body p-3">
                                                <div class="mb-3">
                                                    <label class="form-label">Name</label>
                                                    <input type="text" name="name" class="form-control" value="{{ $tag->name }}" required>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label">Color (Hex)</label>
                                                    <input type="color" name="color" class="form-control form-control-color" value="{{ $tag->color ?? '#6c757d' }}">
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label">Description</label>
                                                    <textarea name="description" class="form-control">{{ $tag->description }}</textarea>
                                                </div>
                                                <div class="mb-3 form-check">
                                                    <input type="hidden" name="active" value="0">
                                                    <input type="checkbox" name="active" class="form-check-input" value="1" id="active{{ $tag->id }}" {{ $tag->active ? 'checked' : '' }}>
                                                    <label class="form-check-label" for="active{{ $tag->id }}">Active</label>
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                                <button type="submit" class="btn btn-primary">Update Tag</button>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        @endforeach
                    </tbody>
                </table>
            </x-card.default>
        </div>
    </div>

    <!-- Create Modal -->
    <div class="modal fade" id="createTagModal" tabindex="-1">
        <div class="modal-dialog">
            <form action="{{ route('tech.admin.system.tag.store') }}" method="POST">
                @csrf
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Create New Tag</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-content-body p-3">
                        <div class="mb-3">
                            <label class="form-label">Name</label>
                            <input type="text" name="name" class="form-control" placeholder="e.g. Urgent, Feedback" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Color</label>
                            <input type="color" name="color" class="form-control form-control-color" value="#6c757d">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control" placeholder="What is this tag for?"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-primary">Create Tag</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
@endsection

@section('rightbar')
    <x-card.default title="Usage Statistics">
        @if($usageStats->isEmpty())
            <p class="text-muted">No tags currently in use.</p>
        @else
            <ul class="list-group">
                @foreach($usageStats as $stat)
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        {{ class_basename($stat->taggable_type) }}
                        <span class="badge bg-primary rounded-pill">{{ $stat->total }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-card.default>
@endsection
