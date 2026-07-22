@if(collect($workflowSteps ?? [])->isNotEmpty())
    <!-- Compact workflow rail: focus or hover a step to inspect its evaluated requirements. -->
    <nav
        class="ticket-workflow flex-grow-1 min-w-0"
        aria-label="{{ $ticket->workflow?->name ?? 'Ticket workflow' }}"
        title="{{ $ticket->workflow?->name ?? 'Ticket workflow' }}{{ $ticket->workflowVersion ? ' - version '.$ticket->workflowVersion->version : '' }}"
    >
        <div class="ticket-workflow__track" role="list">
            @foreach($workflowSteps as $stepIndex => $step)
                @php
                    $stepRequirements = collect($step['requirements'] ?? []);
                    $requirementRows = $stepRequirements->map(function (array $requirement): string {
                        $passed = (bool) ($requirement['passed'] ?? false);
                        $text = $passed
                            ? ($requirement['label'] ?? 'Requirement satisfied')
                            : ($requirement['reason'] ?? $requirement['label'] ?? 'Requirement missing');

                        return sprintf(
                            '<div class="d-flex gap-2"><span class="%s">%s</span><span>%s</span></div>',
                            $passed ? 'text-success' : 'text-danger',
                            $passed ? '&#10003;' : '&#10005;',
                            e($text),
                        );
                    })->all();
                    $requirementsHtml = $requirementRows === []
                        ? '<div class="text-muted">No requirements configured for this step.</div>'
                        : implode('', $requirementRows);
                    $stepState = match (true) {
                        (bool) ($step['is_current'] ?? false) => 'is-current',
                        (bool) ($step['is_visited'] ?? false) => 'is-complete',
                        (bool) ($step['is_available'] ?? false) => 'is-available',
                        default => 'is-upcoming',
                    };
                    $stepStateLabel = match ($stepState) {
                        'is-current' => 'Current step',
                        'is-complete' => 'Completed step',
                        'is-available' => 'Available next step',
                        default => 'Upcoming step',
                    };
                    $markerIcon = match ($stepState) {
                        'is-current' => 'bi-circle-fill',
                        'is-complete' => 'bi-check-lg',
                        'is-available' => 'bi-arrow-right-short',
                        default => null,
                    };
                @endphp

                @if($stepIndex > 0)
                    <span
                        class="ticket-workflow__connector {{ ($step['is_current'] ?? false) || ($step['is_visited'] ?? false) ? 'is-reached' : '' }}"
                        aria-hidden="true"
                    ></span>
                @endif

                <button
                    type="button"
                    role="listitem"
                    class="ticket-workflow__step {{ $stepState }}"
                    data-ticket-workflow-step
                    data-bs-toggle="popover"
                    data-bs-trigger="hover focus"
                    data-bs-placement="bottom"
                    data-bs-html="true"
                    data-bs-title="{{ $step['name'] }}"
                    data-bs-content="{{ $requirementsHtml }}"
                    @if($step['is_current'] ?? false) aria-current="step" @endif
                >
                    <span class="ticket-workflow__marker" aria-hidden="true">
                        @if($markerIcon)
                            <i class="bi {{ $markerIcon }}"></i>
                        @endif
                    </span>
                    <span class="ticket-workflow__label">{{ $step['name'] }}</span>
                    @if(! ($step['requirements_passed'] ?? true))
                        <span class="ticket-workflow__warning" aria-hidden="true">
                            <i class="bi bi-exclamation-triangle-fill"></i>
                        </span>
                        <span class="visually-hidden">Requirements are missing.</span>
                    @endif
                    <span class="visually-hidden">{{ $stepStateLabel }}.</span>
                </button>
            @endforeach
        </div>
    </nav>
@endif
