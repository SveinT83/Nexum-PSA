@extends('layouts.default_tech')

{{--
    Risk Assessment Detail View

    Purpose: Provides a complete overview of a specific risk assessment.

    Core Components:
    - Overview: High-level status, total score, and description.
    - Approval Workflow: Allows finalizing the assessment only when all risks are addressed.
    - Risk Items Table: Lists individual identified risks with their current metrics.
    - Add Risk Modal: Enables quick creation of new risk items without page navigation.
    - Metadata Sidebar: Shows scope, timestamps, and client info.

    Logic:
    - Approving: Controller enforces the `is_approvable` attribute (no "open" items allowed).
    - Status badges: Automatically styled based on assessment/item health.
    - Item creation: The modal posts to the module route and StoreRiskItem
      creates both the current item snapshot and its initial history update.
--}}

@section('title', 'Risk Assessment: ' . $risk->title)

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="{{ route('tech.risk.index') }}">Risk Assessments</a></li>
                    <li class="breadcrumb-item active" aria-current="page">{{ $risk->title }}</li>
                </ol>
            </nav>
            <h1 class="h4 mb-0">{{ $risk->title }}</h1>
        </div>
        <div>
            <x-buttons.editlink url="{{ route('tech.risk.edit', $risk) }}" class="mb-0 me-2">Edit</x-buttons.editlink>
            <a href="{{ route('tech.risk.pdf', $risk) }}" target="_blank" class="btn btn-sm btn-outline-danger me-2">
                <i class="bi bi-file-earmark-pdf me-1"></i> Print PDF
            </a>
            <x-buttons.back url="{{ route('tech.risk.index') }}" class="mb-0">Back</x-buttons.back>
        </div>
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card mb-4">
                <div class="card-header bg-light d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Overview</h5>
                    <div class="d-flex align-items-center gap-2">
                        @if($risk->status === 'approved')
                            <span class="badge bg-success">
                                <i class="bi bi-check-circle me-1"></i> Approved
                            </span>
                        @elseif($risk->status === 'in_progress')
                            <span class="badge bg-info">
                                <i class="bi bi-gear me-1"></i> In Progress
                            </span>
                        @else
                            <span class="badge bg-primary">
                                <i class="bi bi-star me-1"></i> New
                            </span>
                        @endif

                        @if($risk->items->count() > 0)
                            <span class="badge {{ $risk->score_badge_class }}">
                                Total Score: {{ $risk->total_score }}
                            </span>
                        @endif
                    </div>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-9">
                            <p class="mb-3">
                                {!! nl2br(e($risk->description)) !!}
                            </p>
                        </div>
                        <div class="col-md-3 text-end">
                            @if($risk->status !== 'approved')
                                <form action="{{ route('tech.risk.approve', $risk) }}" method="POST">
                                    @csrf
                                    <button type="submit" class="btn btn-success w-100" {{ !$risk->is_approvable ? 'disabled' : '' }}>
                                        <i class="bi bi-check2-all me-1"></i> Approve Assessment
                                    </button>
                                    @if(!$risk->is_approvable)
                                        <div class="small text-muted mt-1 italic">
                                            Address all "Open" risks to approve.
                                        </div>
                                    @endif
                                </form>
                            @else
                                <div class="p-3 border rounded bg-light">
                                    <div class="small text-muted text-uppercase fw-bold mb-1">Approval Info</div>
                                    <div class="fw-bold">{{ $risk->approver->name ?? 'system' }}</div>
                                    <div class="small text-muted">{{ $risk->approved_at->format('d.m.Y H:i') }}</div>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-12">
                <div class="card-header bg-light d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Risk Items</h5>
                    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addRiskItemModal">Add Risk Item</button>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Risk Item</th>
                                    <th>Category</th>
                                    <th class="text-center">Likelihood</th>
                                    <th class="text-center">Impact</th>
                                    <th class="text-center">Score</th>
                                    <th>Status</th>
                                    <th>Next Review</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($groupedItems as $categoryName => $items)
                                    <tr class="table-light">
                                        <td colspan="8" class="fw-bold text-uppercase small text-muted">
                                            {{ $categoryName }}
                                        </td>
                                    </tr>
                                    @foreach($items as $item)
                                        <tr>
                                            <td>
                                                <a href="{{ route('tech.risk.items.show', $item) }}" class="fw-bold text-decoration-none">
                                                    {{ $item->title }}
                                                </a>
                                                @if($item->description)
                                                    <div class="small text-muted text-truncate" style="max-width: 300px;">{{ $item->description }}</div>
                                                @endif
                                            </td>
                                            <td>
                                                <span class="small text-muted">{{ $item->category->name ?? '-' }}</span>
                                            </td>
                                            <td class="text-center">{{ $item->likelihood }}</td>
                                            <td class="text-center">{{ $item->impact }}</td>
                                            <td class="text-center">
                                                <span class="badge {{ $item->score_badge_class }}">
                                                    {{ $item->score }}
                                                </span>
                                            </td>
                                            <td>
                                                @php
                                                    $statusClass = match($item->status) {
                                                        'open' => 'bg-danger',
                                                        'mitigated' => 'bg-success',
                                                        'accepted' => 'bg-info',
                                                        default => 'bg-secondary'
                                                    };
                                                @endphp
                                                <span class="badge {{ $statusClass }}">
                                                    {{ ucfirst($item->status) }}
                                                </span>
                                            </td>
                                            <td>
                                                @if($item->next_review_at)
                                                    <span class="small text-primary">
                                                        {{ $item->next_review_at->format('d.m.Y') }}
                                                    </span>
                                                @else
                                                    <span class="text-muted small italic">Not set</span>
                                                @endif
                                            </td>
                                            <td class="text-end">
                                                <a href="{{ route('tech.risk.items.show', $item) }}" class="btn btn-sm btn-outline-primary">View Detail</a>
                                            </td>
                                        </tr>
                                    @endforeach
                                @empty
                                    <tr>
                                        <td colspan="8" class="text-center py-4">
                                            No risk items added yet.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('sidebar')
    <x-nav.work-menu />

    <!-- Modal -->
    <div class="modal fade" id="addRiskItemModal" tabindex="-1" aria-labelledby="addRiskItemModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form action="{{ route('tech.risk.items.store', $risk) }}" method="POST">
                    @csrf
                    <div class="modal-header">
                        <h5 class="modal-title" id="addRiskItemModalLabel">Add Risk Item</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <x-forms.input_text name="title" labelName="Title" required="true" />
                            </div>
                            <div class="col-md-6">
                                <x-forms.select name="category_id" labelName="Category">
                                    <option value="">-- No Category --</option>
                                    @foreach($categories as $category)
                                        <option value="{{ $category->id }}">{{ $category->name }}</option>
                                    @endforeach
                                </x-forms.select>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <x-forms.select name="likelihood" labelName="Likelihood">
                                    @foreach([1 => 'Very Low', 2 => 'Low', 3 => 'Medium', 4 => 'High', 5 => 'Very High'] as $value => $label)
                                        <option value="{{ $value }}" @selected((int) old('likelihood', $riskItemDefaults['likelihood'] ?? 3) === $value)>{{ $value }} - {{ $label }}</option>
                                    @endforeach
                                </x-forms.select>
                            </div>
                            <div class="col-md-6">
                                <x-forms.select name="impact" labelName="Impact">
                                    @foreach([1 => 'Very Low', 2 => 'Low', 3 => 'Medium', 4 => 'High', 5 => 'Very High'] as $value => $label)
                                        <option value="{{ $value }}" @selected((int) old('impact', $riskItemDefaults['impact'] ?? 3) === $value)>{{ $value }} - {{ $label }}</option>
                                    @endforeach
                                </x-forms.select>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-12">
                                <x-forms.textarea name="description" labelName="Description" />
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-12">
                                <x-forms.textarea name="recommended_actions" labelName="Recommended Actions" />
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-12">
                                <x-forms.textarea name="conclusion" labelName="Conclusion" />
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-12">
                                <x-forms.select name="status" labelName="Status">
                                    <option value="open" @selected(old('status', $riskItemDefaults['status'] ?? 'open') === 'open')>Open</option>
                                    <option value="mitigated" @selected(old('status', $riskItemDefaults['status'] ?? 'open') === 'mitigated')>Mitigated</option>
                                    <option value="accepted" @selected(old('status', $riskItemDefaults['status'] ?? 'open') === 'accepted')>Accepted</option>
                                </x-forms.select>
                            </div>
                        </div>
                        <div class="row mt-3">
                            <div class="col-md-12">
                                <x-forms.input_text name="next_review_at" labelName="Next Review" type="date" value="{{ old('next_review_at', $riskItemDefaults['next_review_at'] ?? null) }}" />
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Risk Item</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@section('rightbar')
    <div class="col-md-12">
        <div class="card mb-4">
            <div class="card-header bg-light">
                <h5 class="mb-0">Assessment Details</h5>
            </div>
            <div class="card-body">
                <ul class="list-unstyled mb-0">
                    <li class="mb-2">
                        <strong>Scope:</strong>
                        @if($risk->client_id)
                            <span class="text-info">Client Specific</span>
                        @else
                            <span class="text-secondary">Internal</span>
                        @endif
                    </li>
                    @if($risk->client_id)
                        <li class="mb-2">
                            <strong>Client:</strong>
                            {{ $risk->client->name ?? 'Unknown' }}
                        </li>
                    @endif
                    <li class="mb-2">
                        <strong>Created:</strong>
                        {{ $risk->created_at->format('d.m.Y H:i') }}
                    </li>
                    <li>
                        <strong>Last Updated:</strong>
                        {{ $risk->updated_at->format('d.m.Y H:i') }}
                    </li>
                </ul>
            </div>
        </div>
    </div>
@endsection
