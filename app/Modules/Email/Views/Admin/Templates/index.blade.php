@extends('layouts.default_tech')

@section('title', 'Email Templates')

<!-- -------------------------------------------------------------------------------------------------- -->
<!-- Page header -->
<!-- Lists outbound email templates managed from the global Templates hub. -->
<!-- -------------------------------------------------------------------------------------------------- -->
@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center">
        <h1 class="mb-0">Email Templates</h1>
        <x-buttons.addlink url="{{ route('tech.admin.system.templatesManagement.email.create') }}">Create Template</x-buttons.addlink>
    </div>
@endsection

@section('content')
    <!-- -------------------------------------------------------------------------------------------------- -->
    <!-- Filters -->
    <!-- Scope filtering keeps ticket/system/sales templates understandable as the template list grows. -->
    <!-- -------------------------------------------------------------------------------------------------- -->
    <x-card.default title="Filters">
        <form method="GET" action="{{ route('tech.admin.system.templatesManagement.email.index') }}" class="row align-items-end">
            <div class="col-md-8">
                <label for="scope" class="form-label">Scope</label>
                <select id="scope" name="scope" class="form-select">
                    <option value="">All scopes</option>
                    @foreach ($scopes as $value => $label)
                        <option value="{{ $value }}" @selected($selectedScope === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-secondary w-100">Filter</button>
            </div>
        </form>
    </x-card.default>

    <!-- -------------------------------------------------------------------------------------------------- -->
    <!-- Template list -->
    <!-- Shows reusable outbound templates and their operational status/default flags. -->
    <!-- -------------------------------------------------------------------------------------------------- -->
    <x-card.default title="Templates">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Scope</th>
                        <th>Key</th>
                        <th>Subject</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($templates as $template)
                        <tr>
                            <td>{{ $template->name }}</td>
                            <td>{{ $scopes[$template->scope] ?? $template->scope }}</td>
                            <td><code>{{ $template->key }}</code></td>
                            <td>{{ $template->subject }}</td>
                            <td>
                                @if ($template->is_active)
                                    <span class="badge text-bg-success">Active</span>
                                @else
                                    <span class="badge text-bg-secondary">Inactive</span>
                                @endif
                                @if ($template->is_default)
                                    <span class="badge text-bg-primary">Default</span>
                                @endif
                            </td>
                            <td class="text-end">
                                <x-buttons.editlink url="{{ route('tech.admin.system.templatesManagement.email.edit', $template) }}">Edit</x-buttons.editlink>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-muted">No email templates found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($templates->hasPages())
            <x-slot:footer>
                {{ $templates->links() }}
            </x-slot:footer>
        @endif
    </x-card.default>
@endsection

@section('sidebar')
    @if(isset($sidebarMenuItems))
        <x-nav.side-bar :items="$sidebarMenuItems" />
    @endif
@endsection

@section('rightbar')
    <x-card.default title="Template variables">
        <p class="small text-muted">
            Variables use double braces, for example <code>@{{ ticket_key }}</code> and <code>@{{ contact_name }}</code>.
        </p>
        <p class="small text-muted mb-0">
            Default seed templates include ticket replies, ticket creation confirmation, and system notifications.
        </p>
    </x-card.default>
@endsection
