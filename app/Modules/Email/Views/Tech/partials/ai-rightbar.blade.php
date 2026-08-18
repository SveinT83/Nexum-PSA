@php
    $mailAiAvailability = app(\App\Modules\Email\Services\MailAiAgentRuntime::class)->availability(auth()->user());
    $mailAiAgent = $mailAiAvailability['agent'] ?? null;
    $mailAiModel = $mailAiAvailability['model'] ?? null;
@endphp

<!-- Mail AI runtime status -->
<div class="card mb-3">
    <div class="card-header py-2" id="mail-ai-runtime-rightbar-heading">
        <button
            class="btn btn-sm btn-link text-body text-decoration-none p-0 w-100 d-flex align-items-center justify-content-between gap-2"
            type="button"
            data-bs-toggle="collapse"
            data-bs-target="#mail-ai-runtime-rightbar-body"
            aria-expanded="false"
            aria-controls="mail-ai-runtime-rightbar-body"
        >
            <span class="fw-semibold small">
                <i class="bi bi-stars me-1" aria-hidden="true"></i>Mail AI
            </span>
            <span class="d-flex align-items-center gap-2">
                @if($mailAiAvailability['available'])
                    <span class="badge text-bg-success">Ready</span>
                @else
                    <span class="badge text-bg-light border">Off</span>
                @endif
                <i class="bi bi-chevron-down" aria-hidden="true"></i>
            </span>
        </button>
    </div>
    <div id="mail-ai-runtime-rightbar-body" class="collapse" aria-labelledby="mail-ai-runtime-rightbar-heading">
        <div class="card-body py-2">
            <div class="small fw-semibold text-truncate">{{ $mailAiAgent?->name ?: 'No Email agent' }}</div>
            <div class="small text-muted text-truncate">
                {{ $mailAiAvailability['available'] ? ($mailAiModel ?: 'Model ready') : ($mailAiAvailability['reason'] ?: 'Not ready') }}
            </div>

            @if(Route::has('tech.admin.settings.email.config') && auth()->user()?->can('email.account_manage'))
                <a href="{{ route('tech.admin.settings.email.config') }}" class="btn btn-sm btn-outline-secondary w-100 mt-2">
                    Settings
                </a>
            @endif
        </div>
    </div>
</div>
