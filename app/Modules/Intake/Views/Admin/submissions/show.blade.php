@extends('layouts.default_tech')

@section('title', 'Intake submission')

@php
    $normalized = $submission->normalized_payload ?: [];
    $rawFields = data_get($submission->raw_payload, 'fields', []);
    $statusBadge = match ($submission->status) {
        'routed' => 'text-bg-success',
        'new' => 'text-bg-primary',
        'routing_skipped' => 'text-bg-warning',
        'spam' => 'text-bg-secondary',
        'reviewed' => 'text-bg-light border',
        default => 'text-bg-light border',
    };
    $target = $submission->target;
@endphp

@section('pageHeader')
    <div class="col">
        <h1 class="h4 mb-0">Submission #{{ $submission->id }}</h1>
    </div>
    <div class="col-auto d-flex flex-wrap gap-2">
        @if(! $submission->reviewed_at)
            <form method="POST" action="{{ route('tech.admin.system.intake.submissions.reviewed', $submission) }}">
                @csrf
                <button type="submit" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-check2-square" aria-hidden="true"></i>
                    Mark reviewed
                </button>
            </form>
        @endif
        @if($submission->status !== 'spam' && ! ($target instanceof \App\Modules\Sales\Models\SalesOpportunity))
            <form method="POST" action="{{ route('tech.admin.system.intake.submissions.route-sales', $submission) }}">
                @csrf
                <button type="submit" class="btn btn-sm btn-primary">
                    <i class="bi bi-graph-up-arrow" aria-hidden="true"></i>
                    Route to Sales
                </button>
            </form>
        @endif
        <a href="{{ route('tech.admin.system.intake.index') }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left" aria-hidden="true"></i>
            Back
        </a>
    </div>
@endsection

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Intake Submission Detail -->
    <!-- ------------------------------------------------- -->
    <div class="row g-3">
        <div class="col-xl-8">
            <div class="card shadow-sm mb-3">
                <div class="card-header bg-body d-flex align-items-center justify-content-between gap-2">
                    <h2 class="h6 mb-0">Submitted data</h2>
                    <span class="badge {{ $statusBadge }}">{{ ucfirst(str_replace('_', ' ', $submission->status)) }}</span>
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        @forelse($normalized as $key => $value)
                            <dt class="col-md-4">{{ ucfirst(str_replace('_', ' ', $key)) }}</dt>
                            <dd class="col-md-8">
                                @if(is_array($value))
                                    {{ implode(', ', $value) }}
                                @elseif($key === 'message')
                                    {!! nl2br(e($value)) !!}
                                @else
                                    {{ $value !== '' ? $value : '-' }}
                                @endif
                            </dd>
                        @empty
                            <dt class="col-md-4">Payload</dt>
                            <dd class="col-md-8 text-muted">No normalized fields.</dd>
                        @endforelse
                    </dl>
                </div>
            </div>

            <div class="card shadow-sm mb-3">
                <div class="card-header bg-body">
                    <h2 class="h6 mb-0">Raw fields</h2>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <tbody>
                            @forelse($rawFields as $key => $value)
                                <tr>
                                    <th class="w-25">{{ $key }}</th>
                                    <td>
                                        @if(is_array($value))
                                            {{ implode(', ', $value) }}
                                        @else
                                            {!! nl2br(e($value)) !!}
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td class="text-muted py-3">No raw fields.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card shadow-sm mb-3">
                <div class="card-header bg-body">
                    <h2 class="h6 mb-0">Attachments</h2>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>File</th>
                                <th>Field</th>
                                <th>Type</th>
                                <th class="text-end">Size</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($submission->attachments as $attachment)
                                <tr>
                                    <td>
                                        <div class="fw-semibold">{{ $attachment->original_filename ?: $attachment->filename }}</div>
                                        <div class="small text-muted">{{ $attachment->checksum_sha1 }}</div>
                                    </td>
                                    <td>{{ $attachment->field?->label ?? '-' }}</td>
                                    <td>{{ $attachment->content_type ?: '-' }}</td>
                                    <td class="text-end">{{ $attachment->size_bytes ? number_format($attachment->size_bytes / 1024, 1) : '-' }} KB</td>
                                    <td class="text-end">
                                        <a href="{{ route('tech.admin.system.intake.attachments.download', [$submission, $attachment]) }}" class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-download" aria-hidden="true"></i>
                                            Download
                                        </a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-4">No attachments.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card shadow-sm">
                <div class="card-header bg-body">
                    <h2 class="h6 mb-0">Events</h2>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Time</th>
                                <th>Type</th>
                                <th>Actor</th>
                                <th>Message</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($submission->events as $event)
                                <tr>
                                    <td class="small">{{ $event->created_at?->format('Y-m-d H:i') }}</td>
                                    <td>{{ ucfirst(str_replace('_', ' ', $event->type)) }}</td>
                                    <td>{{ $event->actor?->name ?? 'System' }}</td>
                                    <td>{{ $event->message }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-4">No events.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="card shadow-sm mb-3">
                <div class="card-header bg-body">
                    <h2 class="h6 mb-0">Context</h2>
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-5">Form</dt>
                        <dd class="col-7">{{ $submission->form?->name ?? 'Deleted form' }}</dd>
                        <dt class="col-5">Submitted</dt>
                        <dd class="col-7">{{ $submission->submitted_at?->format('Y-m-d H:i') }}</dd>
                        <dt class="col-5">Client</dt>
                        <dd class="col-7">{{ $submission->matchedClient?->name ?? '-' }}</dd>
                        <dt class="col-5">Site</dt>
                        <dd class="col-7">{{ $submission->matchedSite?->name ?? '-' }}</dd>
                        <dt class="col-5">Contact</dt>
                        <dd class="col-7">{{ $submission->matchedClientUser?->name ?? $submission->matchedContact?->display_name ?? '-' }}</dd>
                        <dt class="col-5">Reviewed</dt>
                        <dd class="col-7">{{ $submission->reviewed_at?->format('Y-m-d H:i') ?? '-' }}</dd>
                    </dl>
                </div>
            </div>

            <div class="card shadow-sm mb-3">
                <div class="card-header bg-body">
                    <h2 class="h6 mb-0">Routing</h2>
                </div>
                <div class="card-body">
                    @if($target instanceof \App\Modules\Sales\Models\SalesOpportunity)
                        <a href="{{ route('tech.sales.show', $target) }}" class="btn btn-sm btn-outline-primary mb-3">
                            <i class="bi bi-graph-up-arrow" aria-hidden="true"></i>
                            {{ $target->opportunity_key }}
                        </a>
                    @else
                        <div class="text-muted small mb-3">No target record.</div>
                    @endif

                    <pre class="small bg-body-tertiary border rounded p-2 mb-0">{{ json_encode($submission->routing_result ?: [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                </div>
            </div>

            <div class="card shadow-sm">
                <div class="card-header bg-body">
                    <h2 class="h6 mb-0">Request metadata</h2>
                </div>
                <div class="card-body">
                    <dl class="mb-0 small">
                        <dt>IP</dt>
                        <dd>{{ $submission->ip_address ?: '-' }}</dd>
                        <dt>Referrer</dt>
                        <dd class="text-break">{{ $submission->referrer ?: '-' }}</dd>
                        <dt>User agent</dt>
                        <dd class="text-break">{{ $submission->user_agent ?: '-' }}</dd>
                    </dl>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('sidebar')
    <x-nav.admin-menu group="intake" />
@endsection

@section('rightbar')
@endsection
