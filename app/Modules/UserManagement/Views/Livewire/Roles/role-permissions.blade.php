<div>
    <div class="card shadow-sm border-0 mt-4">
        <div class="card-header bg-light d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Permissions for {{ $role->name }}</h5>
            <div class="w-25">
                <input type="text" wire:model.live="search" class="form-control form-control-sm" placeholder="Search permissions...">
            </div>
        </div>
        <div class="card-body">
            @if (session()->has('message'))
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    {{ session('message') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            @endif

            <div class="row">
                @foreach($groupedPermissions as $group => $permissions)
                    <div class="col-md-4 mb-4">
                        <h6 class="text-primary border-bottom pb-2">{{ ucfirst($group) }}</h6>
                        @foreach($permissions as $permission)
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input"
                                       type="checkbox"
                                       id="perm_{{ $permission->id }}"
                                       wire:click="togglePermission('{{ $permission->name }}')"
                                       @if(in_array($permission->name, $selectedPermissions)) checked @endif>
                                <label class="form-check-label text-muted small" for="perm_{{ $permission->id }}">
                                    {{ $permission->name }}
                                </label>
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>

            @if($groupedPermissions->isEmpty())
                <div class="text-center py-4">
                    <p class="text-muted">No permissions found matching your search.</p>
                </div>
            @endif
        </div>
    </div>
</div>
