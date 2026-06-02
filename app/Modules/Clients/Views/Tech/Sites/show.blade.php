@extends('layouts.default_tech')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center">
        <h1>{{ $site->name }}</h1>
        <div>
            <x-buttons.back url="{{ route('tech.clients.show', $client->id) }}" class="mb-0">Back</x-buttons.back>
        </div>
    </div>
@endsection

@section('content')
    @php
        $availableTabs = ['assets', 'users', 'custom-fields'];
        $activeSiteTab = in_array(request('tab'), $availableTabs, true) ? request('tab') : 'assets';
        if ($activeSiteTab === 'custom-fields' && ($customFields ?? collect())->isEmpty()) {
            $activeSiteTab = 'assets';
        }
        $formatCustomFieldValue = function ($value) {
            if (is_array($value)) {
                return $value === [] ? '—' : implode(', ', $value);
            }

            if (is_bool($value)) {
                return $value ? 'Yes' : 'No';
            }

            return filled($value) ? $value : '—';
        };
    @endphp

    <!-- ------------------------------------------------- -->
    <!-- Sites Info -->
    <!-- ------------------------------------------------- -->
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h2 class="h5 mb-0">Site Profile</h2>
            <x-buttons.editlink url="{{ route('tech.clients.sites.edit', [$site, $client]) }}" class="mb-0">Edit Site</x-buttons.editlink>
        </div>

        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <div class="text-muted small">Client</div>
                    <a href="{{ route('tech.clients.show', $client->id) }}">{{ $client->name }}</a>
                </div>

                <div class="col-md-3">
                    <div class="text-muted small">Street</div>
                    <div>{{ $site->address ?: '—' }}</div>
                </div>

                <div class="col-md-3">
                    <div class="text-muted small">CO Street</div>
                    <div>{{ $site->co_address ?: '—' }}</div>
                </div>

                <div class="col-md-3">
                    <div class="text-muted small">Zip</div>
                    <div>{{ $site->zip ?: '—' }}</div>
                </div>

                <div class="col-md-3">
                    <div class="text-muted small">City</div>
                    <div>{{ $site->city ?: '—' }}</div>
                </div>

                <div class="col-md-3">
                    <div class="text-muted small">County</div>
                    <div>{{ $site->county ?: '—' }}</div>
                </div>

                <div class="col-md-3">
                    <div class="text-muted small">Country</div>
                    <div>{{ $site->country ?: '—' }}</div>
                </div>
            </div>
        </div>
    </div>

    <!-- ------------------------------------------------- -->
    <!-- Site Workspace Tabs -->
    <!-- ------------------------------------------------- -->
    <ul class="nav nav-tabs border-bottom border-secondary-subtle" id="siteWorkspaceTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link {{ $activeSiteTab === 'assets' ? 'active ' : '' }}text-body border border-bottom-0" id="site-assets-tab" data-bs-toggle="tab" data-bs-target="#site-assets-pane" type="button" role="tab" aria-controls="site-assets-pane" aria-selected="{{ $activeSiteTab === 'assets' ? 'true' : 'false' }}">
                Assets
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link {{ $activeSiteTab === 'users' ? 'active ' : '' }}text-body border border-bottom-0" id="site-users-tab" data-bs-toggle="tab" data-bs-target="#site-users-pane" type="button" role="tab" aria-controls="site-users-pane" aria-selected="{{ $activeSiteTab === 'users' ? 'true' : 'false' }}">
                Users <span class="badge text-bg-light border ms-1">{{ $users->count() }}</span>
            </button>
        </li>
        @if(($customFields ?? collect())->isNotEmpty())
            <li class="nav-item" role="presentation">
                <button class="nav-link {{ $activeSiteTab === 'custom-fields' ? 'active ' : '' }}text-body border border-bottom-0" id="site-custom-fields-tab" data-bs-toggle="tab" data-bs-target="#site-custom-fields-pane" type="button" role="tab" aria-controls="site-custom-fields-pane" aria-selected="{{ $activeSiteTab === 'custom-fields' ? 'true' : 'false' }}">
                    Custom Fields <span class="badge text-bg-light border ms-1">{{ $customFields->count() }}</span>
                </button>
            </li>
        @endif
    </ul>

    <div class="tab-content pt-3" id="siteWorkspaceTabsContent">
        <div @class(['tab-pane fade', 'show active' => $activeSiteTab === 'assets']) id="site-assets-pane" role="tabpanel" aria-labelledby="site-assets-tab" tabindex="0">
            <x-tech.assets.list-card :site="$site" />
        </div>

        <div @class(['tab-pane fade', 'show active' => $activeSiteTab === 'users']) id="site-users-pane" role="tabpanel" aria-labelledby="site-users-tab" tabindex="0">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div class="d-flex align-items-center gap-2">
                        <h2 class="h5 mb-0">Users</h2>
                        <span class="badge text-bg-secondary">{{ $users->count() }}</span>
                    </div>
                    <x-buttons.addlink url="{{ route('tech.clients.user.create', $client) }}" class="mb-0">New User</x-buttons.addlink>
                </div>

                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light">
                        <tr>
                            <th>Name</th>
                            <th>Role</th>
                            <th>E-mail</th>
                            <th>Phone</th>
                        </tr>
                        </thead>
                        <tbody>

                        <!-- ------------------------------------------------- -->
                        <!-- For each user_management -->
                        <!-- ------------------------------------------------- -->
                        @forelse($users as $user)
                            <tr class="cursor-pointer" data-href="{{ route('tech.clients.user.show', $user) }}" onclick="window.location.href = this.dataset.href">
                                <td>{{ $user->name }}</td>
                                <td>{{ $user->role ?: '—' }}</td>
                                <td>
                                    @if($user->email)
                                        <a href="mailto:{{ $user->email }}" onclick="event.stopPropagation()">{{ $user->email }}</a>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td>
                                    @if($user->phone)
                                        <a href="tel:{{ $user->phone }}" onclick="event.stopPropagation()">{{ $user->phone }}</a>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="text-muted">No users found.</td>
                            </tr>
                        @endforelse

                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        @if(($customFields ?? collect())->isNotEmpty())
            <div @class(['tab-pane fade', 'show active' => $activeSiteTab === 'custom-fields']) id="site-custom-fields-pane" role="tabpanel" aria-labelledby="site-custom-fields-tab" tabindex="0">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <div class="d-flex align-items-center gap-2">
                            <h2 class="h5 mb-0">Custom Fields</h2>
                            <span class="badge text-bg-light border">{{ $customFields->count() }}</span>
                        </div>
                        <span class="small text-muted">Visible site fields</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Field</th>
                                    <th>Value</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($customFields as $field)
                                    @php
                                        $modalId = 'siteCustomFieldValueModal'.$field['definition']->id;
                                        $canEditCustomField = (bool) $field['can_edit'];
                                    @endphp
                                    <tr @class(['cursor-pointer' => $canEditCustomField]) @if($canEditCustomField) data-bs-toggle="modal" data-bs-target="#{{ $modalId }}" @endif>
                                        <td>
                                            <div class="fw-semibold">{{ $field['label'] }}</div>
                                            @if(filled($field['help_text']))
                                                <div class="small text-muted">{{ $field['help_text'] }}</div>
                                            @endif
                                        </td>
                                        <td @class(['text-muted' => blank($field['value']) && $field['value'] !== false])>
                                            {{ $formatCustomFieldValue($field['value']) }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        @endif
    </div>

    @foreach(($customFields ?? collect()) as $field)
        @continue(! $field['can_edit'])
        @php $modalId = 'siteCustomFieldValueModal'.$field['definition']->id; @endphp
        <div class="modal fade" id="{{ $modalId }}" tabindex="-1" aria-labelledby="{{ $modalId }}Label" aria-hidden="true">
            <div class="modal-dialog">
                <form method="POST" action="{{ route('tech.clients.sites.custom-fields.update', [$site, $field['definition']]) }}" class="modal-content">
                    @csrf
                    @method('PATCH')
                    <div class="modal-header">
                        <h2 class="modal-title fs-5" id="{{ $modalId }}Label">{{ $field['label'] }}</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <label class="form-label" for="{{ $modalId }}Value">Value</label>
                        @include('customfield::components.value-input', [
                            'field' => $field,
                            'inputName' => 'value',
                            'inputId' => $modalId.'Value',
                        ])
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save value</button>
                    </div>
                </form>
            </div>
        </div>
    @endforeach

@endsection

@section('sidebar')
    @if(isset($sidebarMenuItems))
        <x-nav.side-bar :items="$sidebarMenuItems" title="Client workspace" />
    @endif
@endsection

@section('rightbar')
@endsection
