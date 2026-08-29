@props([
    'id',
    'storageKey',
    'registeredMinutes' => 0,
    'startUrl' => null,
    'startVisible' => true,
    'actionAllowed' => true,
    'actionReason' => null,
    'modalId',
    'minutesInputId',
    'workDateInputId',
    'resetFormId',
])

{{-- Shared stopwatch presentation and browser-draft behavior; each domain owns persistence. --}}
<div
    id="{{ $id }}"
    data-nexum-stopwatch
    data-storage-key="{{ $storageKey }}"
    data-start-url="{{ $startUrl }}"
    data-action-allowed="{{ $actionAllowed ? '1' : '0' }}"
    data-modal-id="{{ $modalId }}"
    data-minutes-input-id="{{ $minutesInputId }}"
    data-work-date-input-id="{{ $workDateInputId }}"
    data-reset-form-id="{{ $resetFormId }}">
    <div class="text-center border rounded bg-light px-2 py-3">
        <div data-stopwatch-display class="fw-semibold font-monospace" style="font-size: 1.75rem;">00:00:00</div>
        <div data-stopwatch-state class="small text-muted mt-1">Not running</div>
    </div>

    <div data-stopwatch-start-group class="d-grid gap-2 mt-3">
        @if($startVisible)
            <button
                data-stopwatch-start
                type="button"
                class="btn btn-sm btn-primary"
                @disabled(! $actionAllowed)
                title="{{ $actionReason }}">
                <i class="bi bi-play-fill" aria-hidden="true"></i>
                Start
            </button>
        @endif
    </div>

    <div data-stopwatch-controls class="row g-2 mt-3 d-none">
        <div class="col-6">
            <button data-stopwatch-toggle type="button" class="btn btn-sm btn-outline-secondary w-100" disabled>
                <i class="bi bi-pause-fill" aria-hidden="true"></i>
                Pause
            </button>
        </div>
        <div class="col-6">
            <button data-stopwatch-stop type="button" class="btn btn-sm btn-outline-primary w-100" disabled>
                <i class="bi bi-stop-fill" aria-hidden="true"></i>
                Stop
            </button>
        </div>
    </div>

    <div class="small text-muted mt-2">
        Registered total: {{ (int) $registeredMinutes }} min
    </div>
</div>

@once
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('[data-nexum-stopwatch]').forEach(function (root) {
                const stopwatchDisplay = root.querySelector('[data-stopwatch-display]');
                const stopwatchState = root.querySelector('[data-stopwatch-state]');
                const stopwatchStartGroup = root.querySelector('[data-stopwatch-start-group]');
                const stopwatchControls = root.querySelector('[data-stopwatch-controls]');
                const stopwatchStart = root.querySelector('[data-stopwatch-start]');
                const stopwatchToggle = root.querySelector('[data-stopwatch-toggle]');
                const stopwatchStop = root.querySelector('[data-stopwatch-stop]');
                const stopwatchStorageKey = root.dataset.storageKey;
                const addTimeModal = document.getElementById(root.dataset.modalId);
                const timeMinutes = document.getElementById(root.dataset.minutesInputId);
                const timeWorkDate = document.getElementById(root.dataset.workDateInputId);
                const addTimeForm = document.getElementById(root.dataset.resetFormId);
                const defaultStopwatchState = {
                    elapsedMs: 0,
                    startedAt: null,
                    running: false,
                };
                let stopwatch = { ...defaultStopwatchState };

                const loadStopwatch = function () {
                    try {
                        stopwatch = { ...defaultStopwatchState, ...(JSON.parse(localStorage.getItem(stopwatchStorageKey)) || {}) };
                    } catch (error) {
                        stopwatch = { ...defaultStopwatchState };
                    }
                };

                const saveStopwatch = function () {
                    localStorage.setItem(stopwatchStorageKey, JSON.stringify(stopwatch));
                };

                const currentElapsedMs = function () {
                    if (! stopwatch.running || ! stopwatch.startedAt) {
                        return stopwatch.elapsedMs;
                    }

                    return stopwatch.elapsedMs + Math.max(0, Date.now() - stopwatch.startedAt);
                };

                const formatElapsed = function (milliseconds) {
                    const totalSeconds = Math.floor(milliseconds / 1000);
                    const hours = String(Math.floor(totalSeconds / 3600)).padStart(2, '0');
                    const minutes = String(Math.floor((totalSeconds % 3600) / 60)).padStart(2, '0');
                    const seconds = String(totalSeconds % 60).padStart(2, '0');

                    return `${hours}:${minutes}:${seconds}`;
                };

                const syncStopwatchUi = function () {
                    const elapsed = currentElapsedMs();
                    stopwatchDisplay.textContent = formatElapsed(elapsed);
                    stopwatchState.textContent = stopwatch.running
                        ? 'Running'
                        : (elapsed > 0 ? 'Paused' : 'Not running');

                    stopwatchStartGroup?.classList.toggle('d-none', elapsed > 0);
                    stopwatchControls?.classList.toggle('d-none', elapsed <= 0);

                    if (stopwatchStart) {
                        stopwatchStart.disabled = root.dataset.actionAllowed !== '1' || stopwatch.running;
                        stopwatchStart.innerHTML = '<i class="bi bi-play-fill" aria-hidden="true"></i> Start';
                    }

                    stopwatchToggle.disabled = elapsed <= 0;
                    stopwatchToggle.innerHTML = stopwatch.running
                        ? '<i class="bi bi-pause-fill" aria-hidden="true"></i> Pause'
                        : '<i class="bi bi-play" aria-hidden="true"></i> Resume';
                    stopwatchStop.disabled = elapsed <= 0;
                };

                const openTimeModalFromStopwatch = function (elapsedMs) {
                    const minutes = Math.max(1, Math.ceil(elapsedMs / 60000));
                    const today = new Date().toISOString().slice(0, 10);

                    if (timeMinutes) {
                        timeMinutes.value = minutes;
                    }

                    if (timeWorkDate) {
                        timeWorkDate.value = today;
                    }

                    if (window.bootstrap && addTimeModal) {
                        window.bootstrap.Modal.getOrCreateInstance(addTimeModal).show();
                    }
                };

                loadStopwatch();
                syncStopwatchUi();
                window.setInterval(syncStopwatchUi, 1000);

                stopwatchStart?.addEventListener('click', async function () {
                    if (root.dataset.actionAllowed !== '1') {
                        return;
                    }

                    this.disabled = true;

                    try {
                        let payload = {};
                        if (root.dataset.startUrl) {
                            const response = await fetch(root.dataset.startUrl, {
                                method: 'POST',
                                headers: {
                                    'Accept': 'application/json',
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                                },
                                body: JSON.stringify({}),
                            });
                            payload = await response.json().catch(() => ({}));

                            if (! response.ok) {
                                const validationMessage = Object.values(payload.errors || {}).flat()[0];
                                throw new Error(validationMessage || payload.message || 'The timer could not be started.');
                            }
                        }

                        stopwatch = {
                            elapsedMs: 0,
                            startedAt: Date.now(),
                            running: true,
                        };
                        saveStopwatch();
                        syncStopwatchUi();

                        if (payload.data?.transitioned) {
                            window.location.reload();
                        }
                    } catch (error) {
                        window.alert(error.message || 'The timer could not be started.');
                        syncStopwatchUi();
                    }
                });

                stopwatchToggle?.addEventListener('click', function () {
                    if (currentElapsedMs() <= 0) {
                        return;
                    }

                    if (stopwatch.running) {
                        stopwatch.elapsedMs = currentElapsedMs();
                        stopwatch.startedAt = null;
                        stopwatch.running = false;
                    } else {
                        stopwatch.startedAt = Date.now();
                        stopwatch.running = true;
                    }

                    saveStopwatch();
                    syncStopwatchUi();
                });

                stopwatchStop?.addEventListener('click', function () {
                    const elapsed = currentElapsedMs();

                    if (elapsed <= 0) {
                        return;
                    }

                    stopwatch.elapsedMs = elapsed;
                    stopwatch.startedAt = null;
                    stopwatch.running = false;
                    saveStopwatch();
                    syncStopwatchUi();
                    openTimeModalFromStopwatch(elapsed);
                });

                addTimeForm?.addEventListener('submit', function () {
                    stopwatch = { ...defaultStopwatchState };
                    localStorage.removeItem(stopwatchStorageKey);
                });
            });
        });
    </script>
@endonce
