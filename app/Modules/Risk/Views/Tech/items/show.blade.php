@extends('layouts.default_tech')

{{--
    Risk Item Detail View (History-Tracking Workflow)

    Purpose: Manages a "living" risk item by tracking its update history.

    Key Features:
    - Current State Sidebar: Quick snapshot of current score, status, and next review date.
    - History Timeline: Table of all updates, actions, and scoring shifts over time.
    - Add Update Modal: Interface to submit notes, change metrics, or set review dates.

    Logic:
    - History Persistence: Updates are never overwritten; a new record is saved for each action.
    - Status Snapshot: Parent RiskItem always shows the state of its *latest* update.
    - Polymorphic Links: Sidebar supports future linkage to Docs/Assets via `RiskItemLink`.
    - Critical fields: Likelihood, impact, and status are edited through the
      Add Update modal once history exists, not through the descriptive edit form.
--}}

@section('content')
    <div class="container-fluid">
        <div class="row mb-4">
            <div class="col-md-8">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="{{ route('tech.risk.index') }}">Risk Assessments</a></li>
                        <li class="breadcrumb-item"><a href="{{ route('tech.risk.show', $item->assessment) }}">{{ $item->assessment->title }}</a></li>
                        <li class="breadcrumb-item active">{{ $item->title }}</li>
                    </ol>
                </nav>
                <h1 class="h2">Risk Item: {{ $item->title }}</h1>
            </div>
            <div class="col-md-4 text-end">
                <a href="{{ route('tech.risk.show', $item->assessment) }}" class="btn btn-outline-secondary me-2">
                    <i class="bi bi-arrow-left me-1"></i> Back
                </a>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addUpdateModal">
                    <i class="bi bi-plus-circle me-1"></i> Add Update
                </button>
            </div>
        </div>

        <div class="row">
            <!-- Recommended Actions Section -->
            <div class="col-md-12 mb-4">
                <div class="card shadow-sm border-primary">
                    <div class="card-header bg-primary text-white py-3">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-lightning-charge me-2"></i>Recommended Actions
                        </h5>
                    </div>
                    <div class="card-body">
                        @if($item->recommended_actions)
                            <div style="white-space: pre-wrap;" class="fs-6">{{ $item->recommended_actions }}</div>
                        @else
                            <p class="text-muted mb-0 italic">No recommended actions provided yet.</p>
                        @endif
                    </div>
                </div>
            </div>

            <!-- History / Updates Timeline -->
            <div class="col-md-12">
                <div class="card shadow-sm">
                    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">Update History</h5>
                    </div>
                    <div class="card-body p-0">
                        @if($item->updates->count() > 0)
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th style="width: 150px;">Date</th>
                                            <th style="width: 150px;">By</th>
                                            <th style="width: 100px;">Status</th>
                                            <th style="width: 100px;">Score</th>
                                            <th>Notes</th>
                                            <th style="width: 50px;"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($item->updates as $update)
                                            <tr>
                                                <td class="small text-muted">
                                                    {{ $update->created_at->format('d.m.Y H:i') }}
                                                </td>
                                                <td>
                                                    <span class="small">{{ $update->creator ? $update->creator->name : 'system' }}</span>
                                                </td>
                                                <td>
                                                    @php
                                                        $upStatusClass = match($update->status) {
                                                            'open' => 'bg-danger',
                                                            'mitigated' => 'bg-success',
                                                            'accepted' => 'bg-info',
                                                            default => 'bg-secondary'
                                                        };
                                                    @endphp
                                                    <span class="badge {{ $upStatusClass }} small">{{ ucfirst($update->status) }}</span>
                                                </td>
                                                <td>
                                                    @if($update->score !== null)
                                                        <span class="badge {{ $update->score_badge_class }}">
                                                            {{ $update->score }}
                                                        </span>
                                                    @else
                                                        <span class="text-muted small">-</span>
                                                    @endif
                                                </td>
                                                <td style="white-space: pre-wrap;" class="small">{{ $update->note }}</td>
                                                <td class="text-end">
                                                    @if(auth()->user()->hasRole('Superuser') || auth()->id() === $update->created_by)
                                                        <x-buttons.delete
                                                            :url="route('tech.risk.updates.destroy', $update)"
                                                            name="this risk update"
                                                            class="btn btn-link text-danger p-0 m-0"
                                                        >
                                                            <i class="bi bi-trash"></i>
                                                        </x-buttons.delete>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @else
                            <div class="p-4 text-center">
                                <p class="text-muted mb-0">No updates recorded yet.</p>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Risk Item Modal -->
    <div class="modal fade" id="editRiskItemModal" tabindex="-1" aria-labelledby="editRiskItemModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form action="{{ route('tech.risk.items.update', $item) }}" method="POST">
                    @csrf
                    @method('PUT')
                    <div class="modal-header">
                        <h5 class="modal-title" id="editRiskItemModalLabel">Edit Risk Item</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        @if($item->updates->count() > 0)
                            <div class="alert alert-info py-2">
                                <i class="bi bi-info-circle me-2"></i>
                                Some fields are locked because this risk has an update history. Use "Add Update" to change likelihood, impact, or status.
                            </div>
                        @endif
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <x-forms.input_text name="title" labelName="Title" required="true" value="{{ $item->title }}" />
                            </div>
                            <div class="col-md-6">
                                <x-forms.select name="category_id" labelName="Category">
                                    <option value="">-- No Category --</option>
                                    @foreach($categories as $category)
                                        <option value="{{ $category->id }}" {{ $item->category_id == $category->id ? 'selected' : '' }}>{{ $category->name }}</option>
                                    @endforeach
                                </x-forms.select>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <x-forms.select name="likelihood" labelName="Likelihood" enabled="{{ $item->updates->count() > 0 ? 'disabled' : 'enabled' }}">
                                    @for($i = 1; $i <= 5; $i++)
                                        <option value="{{ $i }}" {{ $item->likelihood == $i ? 'selected' : '' }}>{{ $i }}</option>
                                    @endfor
                                </x-forms.select>
                                @if($item->updates->count() > 0)
                                    <input type="hidden" name="likelihood" value="{{ $item->likelihood }}">
                                @endif
                            </div>
                            <div class="col-md-6">
                                <x-forms.select name="impact" labelName="Impact" enabled="{{ $item->updates->count() > 0 ? 'disabled' : 'enabled' }}">
                                    @for($i = 1; $i <= 5; $i++)
                                        <option value="{{ $i }}" {{ $item->impact == $i ? 'selected' : '' }}>{{ $i }}</option>
                                    @endfor
                                </x-forms.select>
                                @if($item->updates->count() > 0)
                                    <input type="hidden" name="impact" value="{{ $item->impact }}">
                                @endif
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-12">
                                <x-forms.textarea name="description" labelName="Description">{{ $item->description }}</x-forms.textarea>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-12">
                                <x-forms.textarea name="recommended_actions" labelName="Recommended Actions">{{ $item->recommended_actions }}</x-forms.textarea>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-12">
                                <x-forms.textarea name="conclusion" labelName="Conclusion">{{ $item->conclusion }}</x-forms.textarea>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-12">
                                <x-forms.select name="status" labelName="Status" enabled="{{ $item->updates->count() > 0 ? 'disabled' : 'enabled' }}">
                                    <option value="open" {{ $item->status == 'open' ? 'selected' : '' }}>Open</option>
                                    <option value="mitigated" {{ $item->status == 'mitigated' ? 'selected' : '' }}>Mitigated</option>
                                    <option value="accepted" {{ $item->status == 'accepted' ? 'selected' : '' }}>Accepted</option>
                                </x-forms.select>
                                @if($item->updates->count() > 0)
                                    <input type="hidden" name="status" value="{{ $item->status }}">
                                @endif
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Risk Item</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Update Modal -->
    <div class="modal fade" id="addUpdateModal" tabindex="-1" aria-labelledby="addUpdateModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addUpdateModalLabel">Add Risk Update</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="{{ route('tech.risk.items.updates.store', $item) }}" method="POST">
                    @csrf
                    <div class="modal-body">
                        <div class="mb-3">
                            <x-forms.textarea name="note" labelName="What was done / Assessment note" required="true" />
                        </div>

                        <div class="row mb-3">
                            <div class="col-md-6">
                                <x-forms.select name="status" labelName="Status" required="true">
                                    <option value="open" {{ $item->status == 'open' ? 'selected' : '' }}>Open</option>
                                    <option value="mitigated" {{ $item->status == 'mitigated' ? 'selected' : '' }}>Mitigated</option>
                                    <option value="accepted" {{ $item->status == 'accepted' ? 'selected' : '' }}>Accepted</option>
                                </x-forms.select>
                            </div>
                            <div class="col-md-6">
                                <x-forms.input_text name="next_review_at" labelName="Next Review" type="date" value="{{ $item->next_review_at ? $item->next_review_at->format('Y-m-d') : '' }}" />
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <x-forms.select name="likelihood" labelName="Likelihood" required="true">
                                    @for($i = 1; $i <= 5; $i++)
                                        <option value="{{ $i }}" {{ $item->likelihood == $i ? 'selected' : '' }}>{{ $i }}</option>
                                    @endfor
                                </x-forms.select>
                            </div>
                            <div class="col-md-6">
                                <x-forms.select name="impact" labelName="Impact" required="true">
                                    @for($i = 1; $i <= 5; $i++)
                                        <option value="{{ $i }}" {{ $item->impact == $i ? 'selected' : '' }}>{{ $i }}</option>
                                    @endfor
                                </x-forms.select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Update</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@section('rightbar')
    <!-- Current State Card -->
    <div class="col-md-12">
        <div class="card mb-4 shadow-sm">
            <div class="card-header bg-white py-3">
                <h5 class="card-title mb-0">Current State</h5>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="small text-muted text-uppercase fw-bold">Status</label>
                    <div class="mt-1">
                        @php
                            $statusClass = match($item->status) {
                                'open' => 'bg-danger',
                                'mitigated' => 'bg-success',
                                'accepted' => 'bg-info',
                                default => 'bg-secondary'
                            };
                        @endphp
                        <span class="badge {{ $statusClass }} fs-6">{{ ucfirst($item->status) }}</span>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="small text-muted text-uppercase fw-bold">Category</label>
                    <div class="mt-1">
                        <span class="text-dark">{{ $item->category->name ?? 'N/A' }}</span>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="small text-muted text-uppercase fw-bold">Original Likelihood & Impact</label>
                    <div class="mt-1">
                        @if($item->original_state)
                            <span class="text-muted fw-bold">{{ $item->original_state->likelihood }} × {{ $item->original_state->impact }}</span>
                            <span class="badge text-bg-secondary ms-1">Score: {{ $item->original_state->score }}</span>
                        @else
                            <span class="text-muted small">N/A</span>
                        @endif
                    </div>
                </div>
                <div class="mb-3">
                    <label class="small text-muted text-uppercase fw-bold">Current Score</label>
                    <div class="mt-1">
                                <span class="badge {{ $item->score_badge_class }} fs-5">
                                    {{ $item->score }}
                                    <small class="ms-1" style="font-size: 0.7em">({{ $item->likelihood }} × {{ $item->impact }})</small>
                                </span>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="small text-muted text-uppercase fw-bold">Next Review</label>
                    <div class="mt-1">
                        @if($item->next_review_at)
                            <span class="text-primary fw-bold">
                                        <i class="bi bi-calendar-event me-1"></i>
                                        {{ $item->next_review_at->format('d.m.Y') }}
                                    </span>
                        @else
                            <span class="text-muted italic">Not scheduled</span>
                        @endif
                    </div>
                </div>
                @if($item->conclusion)
                    <hr>
                    <div class="mb-3">
                        <label class="small text-muted text-uppercase fw-bold">Conclusion</label>
                        <p class="mt-2 mb-0" style="white-space: pre-wrap;">{{ $item->conclusion }}</p>
                    </div>
                @endif
                <hr>
                <div class="mb-0">
                    <label class="small text-muted text-uppercase fw-bold">Description</label>
                    <p class="mt-2 mb-0" style="white-space: pre-wrap;">{{ $item->description ?: 'No description provided.' }}</p>
                </div>
                <hr>
                <div class="d-flex justify-content-between align-items-center">
                    @php
                        $creatorId = $item->original_state?->created_by;
                        $canEdit = auth()->user()->hasRole('Superuser') || ($creatorId && auth()->id() === $creatorId);
                    @endphp
                    <div>
                        @if($canEdit)
                            <button type="button" class="btn btn-sm btn-outline-primary me-2" data-bs-toggle="modal" data-bs-target="#editRiskItemModal">
                                <i class="bi bi-pencil me-1"></i> Edit
                            </button>
                        @endif
                    </div>
                    <div>
                        @if(auth()->user()->hasRole('Superuser'))
                            <x-buttons.delete
                                :url="route('tech.risk.items.destroy', $item)"
                                :name="$item->title"
                                class="btn btn-sm btn-outline-danger"
                            >
                                <i class="bi bi-trash me-1"></i> Delete
                            </x-buttons.delete>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <!-- Links Card (Placeholder for now) -->
        <div class="card shadow-sm">
            <div class="card-header bg-white py-3">
                <h5 class="card-title mb-0">Linked Entities</h5>
            </div>
            <div class="card-body">
                @if($item->links && $item->links->count() > 0)
                    <ul class="list-group list-group-flush">
                        @foreach($item->links as $link)
                            <li class="list-group-item px-0">
                                <i class="bi bi-link-45deg me-2"></i>
                                {{ class_basename($link->linkable_type) }}: #{{ $link->linkable_id }}
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="text-muted mb-0 italic small">No links established yet.</p>
                @endif
            </div>
        </div>
    </div>
@endsection
