@php
    $smartInboxDomSuffix = ((int) $conversationId).'-'.((int) $selectedPlacementId);
    $smartInboxTriggerId = 'smart-inbox-review-trigger-'.$smartInboxDomSuffix;
    $smartInboxResultsId = 'smart-inbox-review-results-'.$smartInboxDomSuffix;
@endphp

{{-- The trigger stays above the reader while the controlled review region is teleported below it. --}}
<div
    @class([
        'mail-smart-inbox-trigger border-bottom px-3 py-2 bg-body-tertiary' => $showSurface,
        'd-none' => ! $showSurface,
    ])
    @if (! $showSurface) aria-hidden="true" @endif
>
    @if ($showSurface)
        <button
            type="button"
            id="{{ $smartInboxTriggerId }}"
            class="btn btn-sm btn-outline-primary d-flex w-100 align-items-center justify-content-between gap-2 text-start"
            wire:click="toggleReview"
            wire:loading.attr="disabled"
            wire:target="toggleReview,closeReview"
            data-smart-inbox-trigger
            aria-expanded="{{ $reviewOpen ? 'true' : 'false' }}"
            aria-controls="{{ $smartInboxResultsId }}"
            x-on:mail-smart-inbox-closed.window="
                if ($event.detail.elementId === $el.id) {
                    $nextTick(() => $el.focus());
                }
            "
        >
            <span class="fw-semibold">
                <i class="bi bi-stars me-1" aria-hidden="true"></i>
                {{ $reviewOpen ? 'Hide Smart Inbox' : 'Smart Inbox' }}
            </span>
            <span class="d-flex align-items-center gap-2">
                @if ($conversationPendingCount > 0)
                    <span
                        class="badge rounded-pill text-bg-light border text-secondary"
                        aria-label="{{ $conversationPendingCount }} Smart Inbox {{ Str::plural('suggestion', $conversationPendingCount) }} to review in this conversation"
                    >
                        {{ $conversationPendingCount }} to review
                    </span>
                @endif
                <i class="bi {{ $reviewOpen ? 'bi-chevron-up' : 'bi-chevron-down' }}" aria-hidden="true"></i>
            </span>
        </button>

        @teleport('#smart-inbox-review-results-slot')
            <section
                id="{{ $smartInboxResultsId }}"
                class="p-3 border-top"
                data-smart-inbox-results
                data-smart-inbox-default-state="collapsed"
                role="region"
                aria-labelledby="{{ $smartInboxTriggerId }}"
                tabindex="-1"
                @if (! $reviewOpen) hidden @endif
                x-on:mail-smart-inbox-opened.window="
                    if ($event.detail.elementId === $el.id) {
                        $nextTick(() => {
                            $el.scrollIntoView({ block: 'nearest' });
                            $el.focus({ preventScroll: true });
                        });
                    }
                "
            >
                <div class="card shadow-sm">
                    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2 py-2">
                        <h2 class="h6 mb-0">
                            <i class="bi bi-stars me-1" aria-hidden="true"></i>Smart Inbox results
                        </h2>
                        <button
                            type="button"
                            class="btn btn-sm btn-outline-secondary btn-icon"
                            wire:click="closeReview"
                            wire:loading.attr="disabled"
                            wire:target="toggleReview,closeReview"
                            title="Close Smart Inbox results"
                        >
                            <i class="bi bi-x-lg" aria-hidden="true"></i>
                            <span class="visually-hidden">Close Smart Inbox results</span>
                        </button>
                    </div>

                    <div class="card-body p-0">
                @if ($analysisAvailable)
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 border-bottom px-3 py-2">
                        <span class="small text-secondary">Suggestions are read-only until you choose an available action.</span>
                        <button
                            type="button"
                            class="btn btn-sm btn-outline-primary"
                            wire:click="analyze"
                            wire:loading.attr="disabled"
                            wire:target="analyze"
                            aria-label="Analyze the selected Mail conversation for Smart Inbox suggestions"
                        >
                            <span wire:loading.remove wire:target="analyze">
                                <i class="bi bi-stars me-1" aria-hidden="true"></i>Analyze
                            </span>
                            <span wire:loading wire:target="analyze" role="status">
                                <span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Analyzing…
                            </span>
                        </button>
                    </div>
                @endif

        {{-- Safe action feedback without suggestion or mailbox identifiers --}}
        @if ($feedbackMessage)
            @php
                $feedbackClass = in_array($feedbackType, ['success', 'danger', 'warning', 'info'], true)
                    ? $feedbackType
                    : 'info';
            @endphp
            <div
                class="alert alert-{{ $feedbackClass }} rounded-0 border-start-0 border-end-0 mb-0 py-2 px-3 small"
                role="{{ $feedbackClass === 'danger' ? 'alert' : 'status' }}"
            >
                {{ $feedbackMessage }}
            </div>
        @endif

        {{-- Sanitized per-item outcomes from one fixed cleanup selection --}}
        @if ($batchResults !== [])
            <div class="border-bottom px-3 py-2" role="status" aria-label="Smart Inbox cleanup batch results">
                <ul class="list-unstyled small mb-0">
                    @foreach ($batchResults as $result)
                        <li class="d-flex align-items-start gap-2 mb-1">
                            <i
                                class="bi {{ $result['status'] === 'succeeded' ? 'bi-check-circle-fill text-success' : 'bi-exclamation-triangle-fill text-danger' }} mt-1"
                                aria-hidden="true"
                            ></i>
                            <span><span class="fw-semibold">{{ $result['label'] }}:</span> {{ $result['message'] }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($suggestions->isEmpty())
            <div class="p-3 text-secondary small">
                No current review suggestions.
                @if ($analysisAvailable)
                    Analysis is manual and never changes Mail or PSA records by itself.
                @endif
            </div>
        @else
            @if ($selectedBatchCount > 0)
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 border-bottom bg-light px-3 py-2">
                    <span class="small text-secondary">
                        {{ $selectedBatchCount }} cleanup {{ Str::plural('suggestion', $selectedBatchCount) }} selected
                    </span>
                    <button
                        type="button"
                        class="btn btn-sm btn-primary"
                        wire:click="applySelected"
                        wire:confirm="Apply the selected provider cleanup actions? Each item is rechecked independently and may move mail in its provider mailbox."
                        wire:loading.attr="disabled"
                        wire:target="applySelected"
                    >
                        <span wire:loading.remove wire:target="applySelected">
                            <i class="bi bi-check2-all me-1" aria-hidden="true"></i>Apply selected
                        </span>
                        <span wire:loading wire:target="applySelected" role="status">
                            <span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Applying…
                        </span>
                    </button>
                </div>
            @endif

            {{-- Bounded, current-user suggestion list --}}
            <div class="list-group list-group-flush">
                @foreach ($suggestions as $suggestion)
                    <article
                        class="list-group-item px-3 py-3"
                        wire:key="smart-inbox-suggestion-{{ $suggestion['id'] }}"
                        aria-labelledby="smart-inbox-effect-{{ $suggestion['id'] }}"
                    >
                        <div class="d-flex align-items-start justify-content-between gap-2">
                            <div class="min-w-0">
                                @if ($suggestion['can_batch_select'])
                                    <div class="form-check mb-2">
                                        <input
                                            class="form-check-input"
                                            type="checkbox"
                                            value="{{ $suggestion['id'] }}"
                                            id="smart-inbox-select-{{ $suggestion['id'] }}"
                                            wire:model.live="selectedSuggestionIds"
                                        >
                                        <label class="form-check-label small" for="smart-inbox-select-{{ $suggestion['id'] }}">
                                            Select for reviewed cleanup batch
                                        </label>
                                    </div>
                                @endif
                                <h3 class="h6 mb-1" id="smart-inbox-effect-{{ $suggestion['id'] }}">
                                    <i class="bi {{ $suggestion['effect_icon'] }} me-1" aria-hidden="true"></i>
                                    {{ $suggestion['effect_label'] }}
                                </h3>
                                <p class="text-secondary small mb-2" id="smart-inbox-impact-{{ $suggestion['id'] }}">
                                    {{ $suggestion['impact'] }}
                                </p>
                            </div>
                            <span class="badge {{ $suggestion['status_class'] }} flex-shrink-0">
                                {{ $suggestion['status_label'] }}
                            </span>
                        </div>

                        @if ($suggestion['details'] !== [])
                            <dl class="row g-1 small mb-2">
                                @foreach ($suggestion['details'] as $detail)
                                    <dt class="col-4 text-secondary fw-normal">{{ $detail['label'] }}</dt>
                                    <dd class="col-8 mb-1 text-break">{{ $detail['value'] }}</dd>
                                @endforeach
                            </dl>
                        @endif

                        @if ($suggestion['explanation'])
                            <p class="small mb-2 text-break">
                                <span class="text-secondary">Reason:</span>
                                {{ $suggestion['explanation'] }}
                            </p>
                        @endif

                        <div class="d-flex flex-wrap align-items-center gap-2 small text-secondary mb-2">
                            <span title="Governed AI agent">
                                <i class="bi bi-shield-check me-1" aria-hidden="true"></i>{{ $suggestion['agent'] }}
                            </span>
                            @if ($suggestion['model'])
                                <span title="AI model">{{ $suggestion['model'] }}</span>
                            @endif
                            @if ($suggestion['policy_revision'] !== null)
                                <span title="AI policy revision">Policy r{{ $suggestion['policy_revision'] }}</span>
                            @endif
                            @if ($suggestion['confidence'] !== null)
                                <span title="Suggestion confidence">{{ $suggestion['confidence'] }}% confidence</span>
                            @endif
                            @if ($suggestion['generated_at'])
                                <time title="Generated time">{{ $suggestion['generated_at'] }}</time>
                            @endif
                            <span>{{ $suggestion['source_count'] }} source {{ Str::plural('message', $suggestion['source_count']) }}</span>
                        </div>

                        @if ($suggestion['applied_label'])
                            <div class="small {{ $suggestion['applied_class'] }} mb-2">
                                <i class="bi {{ $suggestion['applied_icon'] }} me-1" aria-hidden="true"></i>{{ $suggestion['applied_label'] }}
                            </div>
                        @endif

                        @if ($suggestion['can_correct'] || $suggestion['can_apply'] || $suggestion['can_dismiss'] || $suggestion['can_always_do_this'])
                            <div
                                class="d-flex flex-wrap gap-2"
                                aria-describedby="smart-inbox-impact-{{ $suggestion['id'] }}"
                            >
                                @if ($suggestion['can_correct'])
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-outline-secondary"
                                        wire:click="beginCorrection({{ $suggestion['id'] }})"
                                        wire:loading.attr="disabled"
                                        aria-label="Correct {{ $suggestion['effect_label'] }} suggestion"
                                    >
                                        <i class="bi bi-pencil me-1" aria-hidden="true"></i>Correct
                                    </button>
                                @endif

                                @if ($suggestion['can_apply'])
                                    @if ($suggestion['can_batch_select'])
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-primary"
                                            wire:click="apply({{ $suggestion['id'] }})"
                                            wire:confirm="Apply this provider cleanup action? It may move the selected message in its provider mailbox."
                                            wire:loading.attr="disabled"
                                            wire:target="apply({{ $suggestion['id'] }})"
                                            aria-label="Apply {{ $suggestion['effect_label'] }} suggestion"
                                        >
                                            <i class="bi bi-check2 me-1" aria-hidden="true"></i>Apply
                                        </button>
                                    @else
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-primary"
                                            wire:click="apply({{ $suggestion['id'] }})"
                                            wire:loading.attr="disabled"
                                            wire:target="apply({{ $suggestion['id'] }})"
                                            aria-label="Apply {{ $suggestion['effect_label'] }} suggestion"
                                        >
                                            <i class="bi bi-check2 me-1" aria-hidden="true"></i>Apply
                                        </button>
                                    @endif
                                @endif

                                @if ($suggestion['can_always_do_this'])
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-outline-primary"
                                        wire:click="alwaysDoThis({{ $suggestion['id'] }})"
                                        wire:loading.attr="disabled"
                                        wire:target="alwaysDoThis({{ $suggestion['id'] }})"
                                        aria-label="Prepare a rule draft from {{ $suggestion['effect_label'] }}"
                                    >
                                        <i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>Always do this
                                    </button>
                                @endif

                                @if ($suggestion['can_dismiss'])
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-outline-secondary"
                                        wire:click="dismiss({{ $suggestion['id'] }})"
                                        wire:confirm="Dismiss this Smart Inbox suggestion?"
                                        wire:loading.attr="disabled"
                                        aria-label="Dismiss {{ $suggestion['effect_label'] }} suggestion"
                                    >
                                        <i class="bi bi-x-lg me-1" aria-hidden="true"></i>Dismiss
                                    </button>
                                @endif
                            </div>
                        @endif

                        {{-- Effect-specific human correction form --}}
                        @if ($suggestion['is_correcting'])
                            <form class="border-top mt-3 pt-3" wire:submit="saveCorrection">
                                @if ($suggestion['effect_type'] === \App\Modules\Email\Models\EmailSmartInboxSuggestion::EFFECT_REVIEW_SUMMARY)
                                    <div class="mb-2">
                                        <label class="form-label small fw-semibold" for="smart-inbox-summary-{{ $suggestion['id'] }}">Summary</label>
                                        <textarea
                                            class="form-control form-control-sm @error('correctionSummary') is-invalid @enderror"
                                            id="smart-inbox-summary-{{ $suggestion['id'] }}"
                                            rows="3"
                                            wire:model="correctionSummary"
                                        ></textarea>
                                        @error('correctionSummary') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    </div>
                                    <div class="row g-2 mb-2">
                                        <div class="col-sm-6">
                                            <label class="form-label small fw-semibold" for="smart-inbox-urgency-{{ $suggestion['id'] }}">Urgency</label>
                                            <select
                                                class="form-select form-select-sm @error('correctionUrgency') is-invalid @enderror"
                                                id="smart-inbox-urgency-{{ $suggestion['id'] }}"
                                                wire:model="correctionUrgency"
                                            >
                                                <option value="unknown">Unknown</option>
                                                <option value="low">Low</option>
                                                <option value="normal">Normal</option>
                                                <option value="high">High</option>
                                            </select>
                                            @error('correctionUrgency') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                        </div>
                                        <div class="col-sm-6 d-flex align-items-end pb-1">
                                            <div class="form-check">
                                                <input
                                                    class="form-check-input"
                                                    type="checkbox"
                                                    id="smart-inbox-reply-needed-{{ $suggestion['id'] }}"
                                                    wire:model="correctionReplyNeeded"
                                                >
                                                <label class="form-check-label small" for="smart-inbox-reply-needed-{{ $suggestion['id'] }}">Reply needed</label>
                                            </div>
                                        </div>
                                    </div>
                                @elseif ($suggestion['effect_type'] === \App\Modules\Email\Models\EmailSmartInboxSuggestion::EFFECT_CREATE_TASK)
                                    <div class="mb-2">
                                        <label class="form-label small fw-semibold" for="smart-inbox-task-title-{{ $suggestion['id'] }}">Task title</label>
                                        <input
                                            type="text"
                                            class="form-control form-control-sm @error('correctionTaskTitle') is-invalid @enderror"
                                            id="smart-inbox-task-title-{{ $suggestion['id'] }}"
                                            wire:model="correctionTaskTitle"
                                        >
                                        @error('correctionTaskTitle') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    </div>
                                    <div class="row g-2 mb-2">
                                        <div class="col-sm-6">
                                            <label class="form-label small" for="smart-inbox-owner-hint-{{ $suggestion['id'] }}">Owner hint</label>
                                            <input type="text" class="form-control form-control-sm" id="smart-inbox-owner-hint-{{ $suggestion['id'] }}" wire:model="correctionOwnerHint">
                                        </div>
                                        <div class="col-sm-6">
                                            <label class="form-label small" for="smart-inbox-due-hint-{{ $suggestion['id'] }}">Due hint</label>
                                            <input type="text" class="form-control form-control-sm" id="smart-inbox-due-hint-{{ $suggestion['id'] }}" wire:model="correctionDueHint">
                                        </div>
                                    </div>
                                @elseif ($suggestion['effect_type'] === \App\Modules\Email\Models\EmailSmartInboxSuggestion::EFFECT_APPLY_CATEGORY)
                                    <div class="mb-2">
                                        <label class="form-label small fw-semibold" for="smart-inbox-category-{{ $suggestion['id'] }}">Existing active Email category</label>
                                        <select
                                            class="form-select form-select-sm @error('correctionCategoryId') is-invalid @enderror"
                                            id="smart-inbox-category-{{ $suggestion['id'] }}"
                                            wire:model="correctionCategoryId"
                                        >
                                            <option value="">Select category</option>
                                            @foreach ($categoryOptions as $option)
                                                <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                                            @endforeach
                                        </select>
                                        @error('correctionCategoryId') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    </div>
                                @elseif ($suggestion['effect_type'] === \App\Modules\Email\Models\EmailSmartInboxSuggestion::EFFECT_APPLY_TAG)
                                    <div class="mb-2">
                                        <label class="form-label small fw-semibold" for="smart-inbox-tag-{{ $suggestion['id'] }}">Existing active tag</label>
                                        <select
                                            class="form-select form-select-sm @error('correctionTagId') is-invalid @enderror"
                                            id="smart-inbox-tag-{{ $suggestion['id'] }}"
                                            wire:model="correctionTagId"
                                        >
                                            <option value="">Select tag</option>
                                            @foreach ($tagOptions as $option)
                                                <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                                            @endforeach
                                        </select>
                                        @error('correctionTagId') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    </div>
                                @elseif (in_array($suggestion['effect_type'], [
                                    \App\Modules\Email\Models\EmailSmartInboxSuggestion::EFFECT_ARCHIVE_MAIL,
                                    \App\Modules\Email\Models\EmailSmartInboxSuggestion::EFFECT_MOVE_TO_FOLDER,
                                ], true))
                                    <div class="mb-2">
                                        <label class="form-label small fw-semibold" for="smart-inbox-folder-{{ $suggestion['id'] }}">
                                            Provider destination folder
                                        </label>
                                        <select
                                            class="form-select form-select-sm @error('correctionTargetFolderId') is-invalid @enderror"
                                            id="smart-inbox-folder-{{ $suggestion['id'] }}"
                                            wire:model="correctionTargetFolderId"
                                        >
                                            <option value="">Select folder</option>
                                            @foreach ($folderOptions as $option)
                                                @continue(
                                                    $suggestion['effect_type'] === \App\Modules\Email\Models\EmailSmartInboxSuggestion::EFFECT_ARCHIVE_MAIL
                                                    && $option['role'] !== \App\Modules\Email\Models\EmailFolder::ROLE_ARCHIVE
                                                )
                                                <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                                            @endforeach
                                        </select>
                                        @error('correctionTargetFolderId') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                        <div class="form-text">
                                            The target is revalidated against the current mailbox before the correction or cleanup can be applied.
                                        </div>
                                    </div>
                                @endif

                                <div class="mb-2">
                                    <label class="form-label small" for="smart-inbox-explanation-{{ $suggestion['id'] }}">Review explanation</label>
                                    <textarea
                                        class="form-control form-control-sm @error('correctionExplanation') is-invalid @enderror"
                                        id="smart-inbox-explanation-{{ $suggestion['id'] }}"
                                        rows="2"
                                        wire:model="correctionExplanation"
                                    ></textarea>
                                    @error('correctionExplanation') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>

                                <div class="mb-2">
                                    <label class="form-label small" for="smart-inbox-confidence-{{ $suggestion['id'] }}">Confidence (0–1)</label>
                                    <input
                                        type="number"
                                        class="form-control form-control-sm @error('correctionConfidence') is-invalid @enderror"
                                        id="smart-inbox-confidence-{{ $suggestion['id'] }}"
                                        min="0"
                                        max="1"
                                        step="0.01"
                                        wire:model="correctionConfidence"
                                    >
                                    @error('correctionConfidence') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>

                                <div class="d-flex flex-wrap gap-2">
                                    <button type="submit" class="btn btn-sm btn-primary" wire:loading.attr="disabled" wire:target="saveCorrection">
                                        Save correction
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="cancelCorrection">
                                        Cancel
                                    </button>
                                </div>
                            </form>
                        @endif
                    </article>
                @endforeach
            </div>
        @endif
            </div>
                </div>
            </section>
        @endteleport
    @endif
</div>
