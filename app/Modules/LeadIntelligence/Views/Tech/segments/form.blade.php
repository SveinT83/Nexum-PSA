@extends('layouts.default_tech')

@section('title', $segment->exists ? 'Edit Lead Segment' : 'New Lead Segment')

@php
    $leadNav = [
        ['name' => 'Segments', 'route' => 'tech.lead-intelligence.segments.index', 'pattern' => 'tech.lead-intelligence.segments.*', 'icon' => 'bi-funnel'],
        ['name' => 'Research Runs', 'route' => 'tech.lead-intelligence.runs.index', 'pattern' => 'tech.lead-intelligence.runs.*', 'icon' => 'bi-search'],
        ['name' => 'Scan Ledger', 'route' => 'tech.lead-intelligence.scan-ledger.index', 'pattern' => 'tech.lead-intelligence.scan-ledger.*', 'icon' => 'bi-clock-history'],
        ['name' => 'Settings', 'route' => 'tech.admin.settings.lead-intelligence', 'pattern' => 'tech.admin.settings.lead-intelligence*', 'icon' => 'bi-sliders'],
    ];
    $listText = function (string $input, string $column) use ($segment): string {
        $old = old($input);

        if (is_array($old)) {
            return implode(PHP_EOL, $old);
        }

        if (is_string($old)) {
            return $old;
        }

        return implode(PHP_EOL, (array) $segment->{$column});
    };
    $settingsJson = old('settings_json', $segment->settings_json ? json_encode($segment->settings_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '');
    $selectedMarketingLists = array_map('intval', (array) old('marketing_list_ids', $segment->marketing_list_ids_json ?? []));
    $selectedWeekdays = array_map('intval', (array) old('schedule_weekdays', $segment->schedule_weekdays_json ?? []));
@endphp

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center gap-2">
        <h1 class="h4 mb-0">{{ $segment->exists ? 'Edit Lead Segment' : 'New Lead Segment' }}</h1>
        <div class="d-flex align-items-center gap-2">
            @if($segment->exists)
                <form method="POST" action="{{ route('tech.lead-intelligence.segments.run-now', $segment) }}" class="mb-0">
                    @csrf
                    <button type="submit" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-play-fill me-1" aria-hidden="true"></i>Run Now
                    </button>
                </form>
            @endif
            <x-buttons.back :url="route('tech.lead-intelligence.segments.index')" class="mb-0">Back</x-buttons.back>
        </div>
    </div>
@endsection

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Lead segment form -->
    <!-- ------------------------------------------------- -->
    <form method="POST" action="{{ $segment->exists ? route('tech.lead-intelligence.segments.update', $segment) : route('tech.lead-intelligence.segments.store') }}" class="d-grid gap-3">
        @csrf
        @if($segment->exists)
            @method('PUT')
        @endif

        @if(session('success'))
            <div class="alert alert-success py-2">{{ session('success') }}</div>
        @endif

        <div class="card shadow-sm">
            <div class="card-header d-flex align-items-center justify-content-between gap-2">
                <h2 class="h6 mb-0">Segment</h2>
                <button class="btn btn-sm btn-outline-secondary px-2" type="button" data-bs-toggle="collapse" data-bs-target="#leadSegmentAiPanel" aria-expanded="false" aria-controls="leadSegmentAiPanel" title="{{ $aiDraftAvailable ? 'AI segment draft' : 'No active AI agent is configured for Lead Intelligence.' }}" aria-label="AI segment draft">
                    <i class="bi bi-stars" aria-hidden="true"></i>
                </button>
            </div>
            <div class="card-body">
                <div class="collapse mb-3" id="leadSegmentAiPanel">
                    <div class="border rounded p-2" data-lead-segment-ai>
                        <label for="lead_segment_ai_prompt" class="form-label">AI Prompt</label>
                        <div class="input-group input-group-sm">
                            <textarea id="lead_segment_ai_prompt" class="form-control" rows="2" data-lead-segment-ai-prompt @disabled(! $aiDraftAvailable)></textarea>
                            <button type="button" class="btn btn-outline-primary" data-lead-segment-ai-button data-ai-url="{{ route('tech.lead-intelligence.segments.ai-draft') }}" data-segment-id="{{ $segment->exists ? $segment->id : '' }}" @disabled(! $aiDraftAvailable)>
                                <i class="bi bi-stars" aria-hidden="true"></i>
                                Draft
                            </button>
                        </div>
                        <div class="form-text" data-lead-segment-ai-status>
                            @unless($aiDraftAvailable)
                                No active AI agent is configured for Lead Intelligence.
                            @endunless
                        </div>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-lg-8">
                        <label for="name" class="form-label">Name</label>
                        <input type="text" id="name" name="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $segment->name) }}" required data-segment-name>
                        @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-lg-4 d-flex align-items-end">
                        <input type="hidden" name="enabled" value="0">
                        <div class="form-check mb-2">
                            <input type="checkbox" id="enabled" name="enabled" value="1" class="form-check-input" @checked(old('enabled', $segment->enabled ?? true))>
                            <label for="enabled" class="form-check-label">Enabled</label>
                        </div>
                    </div>
                    <div class="col-12">
                        <label for="description" class="form-label">Goal Prompt</label>
                        <textarea id="description" name="description" rows="3" class="form-control @error('description') is-invalid @enderror" data-segment-description>{{ old('description', $segment->description) }}</textarea>
                        @error('description') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header">
                <h2 class="h6 mb-0">Targeting</h2>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    @foreach([
                        'geography' => ['label' => 'Geography', 'column' => 'geography_json'],
                        'industries' => ['label' => 'Industries', 'column' => 'industries_json'],
                        'nace_codes' => ['label' => 'NACE codes', 'column' => 'nace_codes_json'],
                        'keywords' => ['label' => 'Keywords', 'column' => 'keywords_json'],
                        'excluded_keywords' => ['label' => 'Excluded keywords', 'column' => 'excluded_keywords_json'],
                        'target_roles' => ['label' => 'Target roles', 'column' => 'target_roles_json'],
                    ] as $input => $field)
                        <div class="col-lg-6">
                            <label for="{{ $input }}" class="form-label">{{ $field['label'] }}</label>
                            <textarea id="{{ $input }}" name="{{ $input }}" rows="4" class="form-control @error($input) is-invalid @enderror" data-segment-list="{{ $input }}">{{ $listText($input, $field['column']) }}</textarea>
                            @error($input) <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header">
                <h2 class="h6 mb-0">Schedule And Budget</h2>
            </div>
            <div class="card-body">
                <div class="row g-3 align-items-start">
                    <div class="col-lg-4">
                        <input type="hidden" name="schedule_enabled" value="0">
                        <div class="form-check mt-4">
                            <input type="checkbox" id="schedule_enabled" name="schedule_enabled" value="1" class="form-check-input" @checked(old('schedule_enabled', $segment->schedule_enabled)) data-segment-schedule-enabled>
                            <label for="schedule_enabled" class="form-check-label">Enable schedule</label>
                        </div>
                    </div>
                    <div class="col-lg-4">
                        <label for="schedule_period" class="form-label">Goal period</label>
                        <select id="schedule_period" name="schedule_period" class="form-select @error('schedule_period') is-invalid @enderror" data-segment-schedule-period>
                            @foreach($schedulePeriods as $value => $label)
                                <option value="{{ $value }}" @selected(old('schedule_period', $segment->schedule_period ?: 'weekly') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('schedule_period') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-lg-2">
                        <label for="schedule_time" class="form-label">Run time</label>
                        <input type="time" id="schedule_time" name="schedule_time" class="form-control @error('schedule_time') is-invalid @enderror" value="{{ old('schedule_time', $segment->schedule_time ?: '08:00') }}" data-segment-schedule-time>
                        @error('schedule_time') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-lg-2">
                        <label for="run_interval_days" class="form-label">Run every days</label>
                        <input type="number" min="1" max="365" id="run_interval_days" name="run_interval_days" class="form-control @error('run_interval_days') is-invalid @enderror" value="{{ old('run_interval_days', $segment->run_interval_days ?: 1) }}" data-segment-run-interval-days>
                        @error('run_interval_days') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-12">
                        <div class="row g-2">
                            @foreach($weekdays as $day => $label)
                                <div class="col-6 col-md-3 col-xl">
                                    <div class="form-check">
                                        <input type="checkbox" id="schedule_weekday_{{ $day }}" name="schedule_weekdays[]" value="{{ $day }}" class="form-check-input" @checked(in_array((int) $day, $selectedWeekdays, true)) data-segment-weekday="{{ $day }}">
                                        <label for="schedule_weekday_{{ $day }}" class="form-check-label">{{ $label }}</label>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                    <div class="col-lg-3">
                        <label for="target_new_leads_per_period" class="form-label">New leads target</label>
                        <input type="number" min="1" id="target_new_leads_per_period" name="target_new_leads_per_period" class="form-control @error('target_new_leads_per_period') is-invalid @enderror" value="{{ old('target_new_leads_per_period', $segment->target_new_leads_per_period) }}" data-segment-target-new-leads>
                        @error('target_new_leads_per_period') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-lg-3">
                        <label for="max_runs_per_period" class="form-label">Max runs per period</label>
                        <input type="number" min="1" id="max_runs_per_period" name="max_runs_per_period" class="form-control @error('max_runs_per_period') is-invalid @enderror" value="{{ old('max_runs_per_period', $segment->max_runs_per_period) }}" data-segment-max-runs>
                        @error('max_runs_per_period') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-lg-3">
                        <label for="token_budget_per_period" class="form-label">Token budget per period</label>
                        <input type="number" min="1" id="token_budget_per_period" name="token_budget_per_period" class="form-control @error('token_budget_per_period') is-invalid @enderror" value="{{ old('token_budget_per_period', $segment->token_budget_per_period) }}" data-segment-token-budget>
                        @error('token_budget_per_period') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-lg-3">
                        <input type="hidden" name="token_budget_unlimited" value="0">
                        <div class="form-check mt-4">
                            <input type="checkbox" id="token_budget_unlimited" name="token_budget_unlimited" value="1" class="form-check-input" @checked(old('token_budget_unlimited', $segment->token_budget_unlimited)) data-segment-token-unlimited>
                            <label for="token_budget_unlimited" class="form-check-label">Unlimited tokens</label>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <dl class="row small mb-0">
                            <dt class="col-5">Next run</dt>
                            <dd class="col-7 text-end">{{ $segment->next_run_at?->format('Y-m-d H:i') ?? '-' }}</dd>
                            <dt class="col-5">Last run</dt>
                            <dd class="col-7 text-end">{{ $segment->last_run_at?->format('Y-m-d H:i') ?? '-' }}</dd>
                        </dl>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header">
                <h2 class="h6 mb-0">Marketing List Targets</h2>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-lg-6">
                        <label for="marketing_list_ids" class="form-label">Marketing lists</label>
                        <select id="marketing_list_ids" name="marketing_list_ids[]" class="form-select @error('marketing_list_ids') is-invalid @enderror" multiple size="8">
                            @foreach($marketingLists as $list)
                                <option value="{{ $list->id }}" @selected(in_array((int) $list->id, $selectedMarketingLists, true))>{{ $list->name }} @if($list->status)({{ $list->status }})@endif</option>
                            @endforeach
                        </select>
                        @error('marketing_list_ids') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-lg-6">
                        <label for="settings_json" class="form-label">Segment overrides JSON</label>
                        <textarea id="settings_json" name="settings_json" rows="8" class="form-control font-monospace @error('settings_json') is-invalid @enderror">{{ $settingsJson }}</textarea>
                        @error('settings_json') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex justify-content-end gap-2">
            <a href="{{ route('tech.lead-intelligence.segments.index') }}" class="btn btn-outline-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">{{ $segment->exists ? 'Save Segment' : 'Create Segment' }}</button>
        </div>
    </form>
@endsection

@section('sidebar')
    <x-nav.side-bar title="Lead Intelligence" :items="$leadNav" />
    <x-nav.sales-menu />
@endsection

@section('scripts')
    <script>
        (function () {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
            const tokenUnlimited = document.querySelector('[data-segment-token-unlimited]');
            const tokenBudget = document.querySelector('[data-segment-token-budget]');

            function syncTokenBudget() {
                if (!tokenUnlimited || !tokenBudget) {
                    return;
                }

                tokenBudget.disabled = tokenUnlimited.checked;
                if (tokenUnlimited.checked) {
                    tokenBudget.value = '';
                }
            }

            tokenUnlimited?.addEventListener('change', syncTokenBudget);
            syncTokenBudget();

            function setValue(selector, value) {
                const input = document.querySelector(selector);
                if (input && value !== undefined && value !== null) {
                    input.value = Array.isArray(value) ? value.join("\n") : value;
                }
            }

            function setCheckbox(selector, checked) {
                const input = document.querySelector(selector);
                if (input) {
                    input.checked = Boolean(checked);
                }
            }

            function setWeekdays(days) {
                document.querySelectorAll('[data-segment-weekday]').forEach(function (input) {
                    input.checked = Array.isArray(days) && days.map(Number).includes(Number(input.value));
                });
            }

            document.querySelector('[data-lead-segment-ai-button]')?.addEventListener('click', async function (event) {
                const button = event.currentTarget;
                const prompt = document.querySelector('[data-lead-segment-ai-prompt]')?.value.trim() || '';
                const status = document.querySelector('[data-lead-segment-ai-status]');
                const originalHtml = button.innerHTML;

                if (!prompt) {
                    if (status) {
                        status.className = 'form-text text-danger';
                        status.textContent = 'Prompt is required.';
                    }
                    return;
                }

                const controller = new AbortController();
                const timeout = window.setTimeout(function () {
                    controller.abort();
                }, 120000);

                button.disabled = true;
                button.innerHTML = '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Drafting';

                if (status) {
                    status.className = 'form-text text-muted';
                    status.textContent = 'AI is drafting segment fields. This should take less than two minutes.';
                }

                try {
                    const response = await fetch(button.dataset.aiUrl, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                        body: JSON.stringify({
                            prompt: prompt,
                            lead_segment_id: button.dataset.segmentId || null,
                        }),
                        signal: controller.signal,
                    });
                    const payload = await response.json().catch(function () { return {}; });

                    if (!response.ok) {
                        throw new Error(payload.message || 'AI draft failed.');
                    }

                    setValue('[data-segment-name]', payload.name);
                    setValue('[data-segment-description]', payload.description);
                    ['geography', 'industries', 'nace_codes', 'keywords', 'excluded_keywords', 'target_roles'].forEach(function (key) {
                        setValue('[data-segment-list="' + key + '"]', payload[key]);
                    });
                    setValue('[data-segment-schedule-period]', payload.schedule_period);
                    setValue('[data-segment-schedule-time]', payload.schedule_time);
                    setValue('[data-segment-run-interval-days]', payload.run_interval_days);
                    setValue('[data-segment-target-new-leads]', payload.target_new_leads_per_period);
                    setValue('[data-segment-token-budget]', payload.token_budget_per_period);
                    setValue('[data-segment-max-runs]', payload.max_runs_per_period);
                    setCheckbox('[data-segment-token-unlimited]', payload.token_budget_unlimited);
                    setWeekdays(payload.schedule_weekdays);
                    syncTokenBudget();

                    if (status) {
                        status.className = 'form-text text-success';
                        status.textContent = 'Draft inserted. Review and save the segment.';
                    }
                } catch (error) {
                    if (status) {
                        status.className = 'form-text text-danger';
                        status.textContent = error.name === 'AbortError'
                            ? 'AI draft timed out. Try a shorter prompt or a faster model.'
                            : (error.message || 'AI draft failed.');
                    }
                } finally {
                    window.clearTimeout(timeout);
                    button.disabled = false;
                    button.innerHTML = originalHtml;
                }
            });
        })();
    </script>
@endsection
