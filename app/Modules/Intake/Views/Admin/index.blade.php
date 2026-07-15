@extends('layouts.default_tech')

@section('title', 'Intake')

@php
    $statusBadge = static function (string $status): string {
        return match ($status) {
            'active', 'routed' => 'text-bg-success',
            'new' => 'text-bg-primary',
            'routing_skipped' => 'text-bg-warning',
            'spam', 'archived' => 'text-bg-secondary',
            'reviewed' => 'text-bg-light border',
            default => 'text-bg-light border',
        };
    };
@endphp

@section('pageHeader')
    <div class="col">
        <h1 class="h4 mb-0">Intake</h1>
    </div>
    <div class="col-auto">
        <a href="{{ route('tech.admin.system.intake.forms.create') }}" class="btn btn-sm btn-primary">
            <i class="bi bi-plus-circle" aria-hidden="true"></i>
            New form
        </a>
    </div>
@endsection

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Intake Admin Overview -->
    <!-- ------------------------------------------------- -->
    <div class="row g-3 mb-3">
        <div class="col-md-4">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-body">
                    <h2 class="h6 mb-0">Open submissions</h2>
                </div>
                <div class="card-body">
                    <div class="display-6">{{ $openSubmissionCount }}</div>
                    <div class="text-muted small">New or routing-skipped submissions awaiting review.</div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-body">
                    <h2 class="h6 mb-0">Forms</h2>
                </div>
                <div class="card-body">
                    <div class="display-6">{{ $forms->count() }}</div>
                    <div class="text-muted small">Public intake forms configured in this environment.</div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-body">
                    <h2 class="h6 mb-0">Active forms</h2>
                </div>
                <div class="card-body">
                    <div class="display-6">{{ $forms->where('status', 'active')->count() }}</div>
                    <div class="text-muted small">Forms that can receive public submissions.</div>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm mb-3">
        <div class="card-header bg-body d-flex align-items-center justify-content-between gap-2">
            <h2 class="h6 mb-0">Forms</h2>
            <a href="{{ route('tech.admin.system.intake.forms.create') }}" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-plus-circle" aria-hidden="true"></i>
                New form
            </a>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Name</th>
                        <th>Status</th>
                        <th>Target</th>
                        <th class="text-end">Submissions</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($forms as $form)
                        <tr>
                            <td>
                                <a href="{{ route('tech.admin.system.intake.forms.edit', $form) }}" class="fw-semibold text-decoration-none">{{ $form->name }}</a>
                                <div class="small text-muted">/intake/forms/{{ $form->slug }}</div>
                            </td>
                            <td><span class="badge {{ $statusBadge($form->status) }}">{{ ucfirst($form->status) }}</span></td>
                            <td>{{ ucfirst(str_replace('_', ' ', $form->target_type)) }}</td>
                            <td class="text-end">{{ $form->submissions_count }}</td>
                            <td class="text-end">
                                <div class="d-inline-flex gap-1">
                                    @if($form->isActive())
                                        <a href="{{ route('intake.forms.show', $form) }}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary" title="Open public form">
                                            <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>
                                        </a>
                                    @endif
                                    <a href="{{ route('tech.admin.system.intake.forms.edit', $form) }}" class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-pencil" aria-hidden="true"></i>
                                    </a>
                                    <form method="POST" action="{{ route('tech.admin.system.intake.forms.toggle', $form) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-outline-secondary">
                                            {{ $form->isActive() ? 'Disable' : 'Enable' }}
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">No intake forms.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header bg-body">
            <h2 class="h6 mb-0">Latest submissions</h2>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Submitted</th>
                        <th>Form</th>
                        <th>Status</th>
                        <th>Company / subject</th>
                        <th>Match</th>
                        <th class="text-end">Files</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($submissions as $submission)
                        @php($normalized = $submission->normalized_payload ?: [])
                        <tr>
                            <td class="small">{{ $submission->submitted_at?->format('Y-m-d H:i') }}</td>
                            <td>{{ $submission->form?->name ?? 'Deleted form' }}</td>
                            <td><span class="badge {{ $statusBadge($submission->status) }}">{{ ucfirst(str_replace('_', ' ', $submission->status)) }}</span></td>
                            <td>
                                <a href="{{ route('tech.admin.system.intake.submissions.show', $submission) }}" class="fw-semibold text-decoration-none">
                                    {{ $normalized['subject'] ?? $normalized['company_name'] ?? 'Submission #'.$submission->id }}
                                </a>
                                <div class="small text-muted">{{ $normalized['company_name'] ?? $normalized['contact_email'] ?? '' }}</div>
                            </td>
                            <td>{{ $submission->matchedClient?->name ?? 'No match' }}</td>
                            <td class="text-end">{{ $submission->attachments_count }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">No submissions yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection

@section('sidebar')
    <x-nav.admin-menu group="intake" />
@endsection

@section('rightbar')
@endsection
