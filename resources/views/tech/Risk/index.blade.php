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
--}}

{{--
    Documentation Index View

    Displays a sortable/filterable list of all documentation records.
    Filtering is based on:
    1. Category (passed via 'cat' query parameter).
    2. Session-based Context (Active Client, Sites, or Internal Scope).
--}}

@section('title', 'Risk Assessments')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center py-3">
        <h1 class="h4 mb-0">
            Risk & Compliance
        </h1>

        <!-- Active Client Selector -->
        <div class="d-flex align-items-center">
            <x-context.selector :clients="$clients" />
        </div>

        <div>
            <a href="{{ route('tech.risk.create') }}" class="btn btn-sm btn-primary">New Risk Assessment</a>
        </div>
    </div>
@endsection

@section('content')
    <div class="card mt-4">
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
                                    <a href="{{ route('tech.risk.show', $assessment) }}" class="btn btn-sm btn-outline-primary">Open</a>
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
