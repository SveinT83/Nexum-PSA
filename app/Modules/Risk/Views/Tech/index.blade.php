@extends('layouts.default_tech')

{{--
    Risk Assessments Index View

    Purpose: Displays a list of all risk assessments (internal and client-linked).
    Filtering: Uses the global session context (active client or internal scope)
               provided by the <x-context.selector /> component.

    Key Features:
    - Score badges: Visualizes risk severity using color-coded Bootstrap badges (Low to Critical).
    - Status tracking: Shows the lifecycle state (New, In Progress, Approved) with icons.
    - Contextual links: Directs user_management to the detailed assessment page.
    - Superuser-only delete action: the controller repeats the permission check
      so the UI hiding is convenience, not security.
--}}

@section('title', 'Risk Assessments')

@section('pageHeader')

        <h1>Risk & Compliance</h1>

@endsection

@section('content')

    <!-- -------------------------------------------------------------------------------------------------- -->
    <!-- Risk Assessments Table Card -->
    <!-- -------------------------------------------------------------------------------------------------- -->
    <div class="card mt-4">

        <!-- ------------------------------------------------- -->
        <!-- Card Header: Client selector and new risk Assessment button -->
        <!-- ------------------------------------------------- -->
        <div class="card-header">
            <div class="row">

                <!-- Client selector -->
                <div class="col-md-10">
                    <x-context.selector :clients="$clients" />
                </div>

                <!-- New risk Assessment button -->
                <div class="col-md-2 text-end">
                    <x-buttons.addlink url="{{ route('tech.risk.create') }}">New Risk Assessment</x-buttons.addlink>
                </div>

            </div>
        </div>

        <!-- ------------------------------------------------- -->
        <!-- Card Body: Risk assessments table -->
        <!-- ------------------------------------------------- -->
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Title</th>
                            <th>Client</th>
                            <th>Score</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($assessments as $assessment)
                            <tr>
                                <td>
                                    <div class="fw-bold">{{ $assessment->title }}</div>
                                    @if($assessment->description)
                                        <small class="text-muted text-truncate d-inline-block" style="max-width: 300px;">
                                            {{ $assessment->description }}
                                        </small>
                                    @endif
                                </td>
                                <td>
                                    @if($assessment->client_id)
                                        <span class="badge bg-info text-dark">
                                            Client: {{ $assessment->client->name ?? 'Unknown' }}
                                        </span>
                                    @else
                                        <span class="badge bg-secondary">Internal</span>
                                    @endif
                                </td>
                                <td>
                                    @if($assessment->items->count() > 0)
                                        <span class="badge {{ $assessment->score_badge_class }}">
                                            {{ $assessment->total_score }}
                                        </span>
                                    @else
                                        <span class="text-muted small">N/A</span>
                                    @endif
                                </td>
                                <td>
                                    @php
                                        $statusClass = match($assessment->status) {
                                            'approved' => 'success',
                                            'in_progress' => 'info',
                                            default => 'primary'
                                        };
                                        $statusIcon = match($assessment->status) {
                                            'approved' => 'bi-check-circle',
                                            'in_progress' => 'bi-gear',
                                            default => 'bi-star'
                                        };
                                    @endphp
                                    <span class="badge bg-{{ $statusClass }}">
                                        <i class="bi {{ $statusIcon }} me-1"></i>
                                        {{ ucfirst(str_replace('_', ' ', $assessment->status)) }}
                                    </span>
                                </td>
                                <td>{{ $assessment->created_at->format('d.m.Y H:i') }}</td>
                                <td class="text-end">
                                    <div class="d-inline-flex gap-1">
                                        <a href="{{ route('tech.risk.show', $assessment) }}" class="btn btn-sm btn-outline-primary">Open</a>
                                        <a href="{{ route('tech.risk.edit', $assessment) }}" class="btn btn-sm btn-outline-secondary">Edit</a>
                                        @if(auth()->user()->hasRole('Superuser'))
                                            <x-buttons.delete
                                                :url="route('tech.risk.destroy', $assessment)"
                                                :name="$assessment->title"
                                                class="btn btn-sm btn-outline-danger"
                                            />
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center py-4">
                                    No risk assessments found for this context.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-4">
                {{ $assessments->links() }}
            </div>
        </div>
    </div>
@endsection

@section('sidebar')
    <x-nav.work-menu />
@endsection

@section('rightbar')
    <div class="card mb-4">
        <div class="card-header bg-light">
            <h5 class="mb-0">Overview</h5>
        </div>
        <div class="card-body small">
            <p>Risk assessments help identify and mitigate potential threats to your business or client operations.</p>
            <ul class="mb-0 ps-3">
                <li><strong>New:</strong> Initial identification.</li>
                <li><strong>In Progress:</strong> Mitigation active.</li>
                <li><strong>Approved:</strong> Finalized and signed off.</li>
            </ul>
        </div>
    </div>
@endsection
