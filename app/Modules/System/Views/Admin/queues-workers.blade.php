@extends('layouts.default_tech')

@section('title', 'Queues and Workers')

<!-- -------------------------------------------------------------------------------------------------- -->
<!-- Page header -->
<!-- Operational page for queue visibility and safe Laravel worker maintenance actions. -->
<!-- -------------------------------------------------------------------------------------------------- -->
@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center">
        <h1 class="mb-0">Queues and Workers</h1>
        <x-buttons.back url="{{ route('tech.admin.index') }}">Back</x-buttons.back>
    </div>
@endsection

@section('content')
    @php($applicationPath = $basePath ?? base_path())

    <!-- -------------------------------------------------------------------------------------------------- -->
    <!-- Queue status overview -->
    <!-- Shows what Laravel can observe from configured queue tables without assuming a specific process manager. -->
    <!-- -------------------------------------------------------------------------------------------------- -->
    <div class="row">
        <div class="col-md-6">
            <x-card.default title="Queue status">
                <dl class="row mb-0">
                    <dt class="col-sm-5">Connection</dt>
                    <dd class="col-sm-7">{{ $status['connection'] }}</dd>

                    <dt class="col-sm-5">Default queue</dt>
                    <dd class="col-sm-7">{{ $status['configured_queue'] }}</dd>

                    <dt class="col-sm-5">Worker queues</dt>
                    <dd class="col-sm-7"><code>{{ $status['worker_queues'] }}</code></dd>

                    <dt class="col-sm-5">Failed jobs</dt>
                    <dd class="col-sm-7">{{ $status['failed_jobs']['count'] }}</dd>
                </dl>
            </x-card.default>
        </div>

        <div class="col-md-6">
            <x-card.default title="Worker controls">
                <!-- ------------------------------------------------- -->
                <!-- Safe worker restart -->
                <!-- queue:restart signals workers to restart after their current job; it does not kill processes. -->
                <!-- ------------------------------------------------- -->
                <form method="POST" action="{{ route('tech.admin.system.queues-workers.restart') }}" class="mb-3">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-warning">Restart workers</button>
                    <div class="small text-muted mt-1">Requests a graceful Laravel worker restart after current jobs finish.</div>
                </form>

                <!-- ------------------------------------------------- -->
                <!-- Queue clear -->
                <!-- Clears pending jobs from one queue on the configured default queue connection. -->
                <!-- ------------------------------------------------- -->
                <form method="POST" action="{{ route('tech.admin.system.queues-workers.clear') }}">
                    @csrf
                    <label for="queue" class="form-label">Clear queue</label>
                    <div class="input-group input-group-sm">
                        <input id="queue" name="queue" type="text" class="form-control" value="{{ $status['configured_queue'] }}" placeholder="default">
                        <button type="submit" class="btn btn-outline-danger">Clear</button>
                    </div>
                    <div class="small text-muted mt-1">Removes pending jobs from the named queue. Failed jobs are managed separately.</div>
                </form>
            </x-card.default>
        </div>
    </div>

    <!-- -------------------------------------------------------------------------------------------------- -->
    <!-- Pending jobs -->
    <!-- Database-backed queues can be counted here. Redis/Horizon queues should be monitored by their own driver tools. -->
    <!-- -------------------------------------------------------------------------------------------------- -->
    <x-card.default title="Pending jobs">
        @if ($status['supports_database_counts'])
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Queue</th>
                            <th class="text-end">Ready</th>
                            <th class="text-end">Delayed</th>
                            <th class="text-end">Reserved</th>
                            <th class="text-end">Total</th>
                            <th>Oldest available at</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($status['pending_jobs'] as $queue)
                            <tr>
                                <td><code>{{ $queue['queue'] }}</code></td>
                                <td class="text-end">{{ $queue['ready_count'] }}</td>
                                <td class="text-end">{{ $queue['delayed_count'] }}</td>
                                <td class="text-end">{{ $queue['reserved_count'] }}</td>
                                <td class="text-end">{{ $queue['jobs_count'] }}</td>
                                <td>{{ $queue['oldest_available_at'] ?? 'N/A' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-muted">No pending jobs found in the database queue table.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @else
            <p class="text-muted mb-0">The <code>jobs</code> table does not exist, so this page cannot count pending database jobs.</p>
        @endif
    </x-card.default>

    <!-- -------------------------------------------------------------------------------------------------- -->
    <!-- Failed jobs -->
    <!-- Failed jobs can be retried or flushed from the web UI because these are Laravel-supported commands. -->
    <!-- -------------------------------------------------------------------------------------------------- -->
    <x-card.default title="Failed jobs">
        <div class="d-flex gap-2 mb-3">
            <form method="POST" action="{{ route('tech.admin.system.queues-workers.failed.retry') }}">
                @csrf
                <button type="submit" class="btn btn-sm btn-outline-primary">Retry all failed</button>
            </form>

            <form method="POST" action="{{ route('tech.admin.system.queues-workers.failed.flush') }}">
                @csrf
                <button type="submit" class="btn btn-sm btn-outline-danger">Flush failed</button>
            </form>
        </div>

        @if ($status['supports_failed_jobs'])
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Queue</th>
                            <th>Failed at</th>
                            <th>Exception</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($status['failed_jobs']['latest'] as $job)
                            <tr>
                                <td>{{ $job['uuid'] ?? $job['id'] }}</td>
                                <td>{{ $job['queue'] }}</td>
                                <td>{{ $job['failed_at'] }}</td>
                                <td>{{ $job['exception'] }}</td>
                                <td class="text-end">
                                    <form method="POST" action="{{ route('tech.admin.system.queues-workers.failed.retry') }}">
                                        @csrf
                                        <input type="hidden" name="job_id" value="{{ $job['uuid'] ?? $job['id'] }}">
                                        <button type="submit" class="btn btn-sm btn-outline-primary">Retry</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-muted">No failed jobs found.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @else
            <p class="text-muted mb-0">The <code>failed_jobs</code> table does not exist.</p>
        @endif
    </x-card.default>

@endsection

@section('sidebar')
    <x-nav.admin-menu group="system" />
@endsection

@section('rightbar')
    <x-card.default title="Operational note">
        <p class="small text-muted mb-0">
            This page can safely restart Laravel workers and manage queued/failed jobs.
            Starting and stopping worker processes should be handled by Supervisor, systemd, Docker, or another process manager.
        </p>
    </x-card.default>

    <!-- -------------------------------------------------------------------------------------------------- -->
    <!-- Documentation card -->
    <!-- Keeps setup guidance accessible without making the main operations page unnecessarily long. -->
    <!-- -------------------------------------------------------------------------------------------------- -->
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Documentation</h5>
            <i class="bi bi-info-circle"></i>
        </div>
        <div class="card-body">
            <p class="small text-muted">
                Set up the scheduler and a persistent process manager before relying on queued email, workflow, or rule jobs.
            </p>
            <h6 class="small fw-bold">Worker setup:</h6>
            <p class="x-small text-muted mb-2">
                Includes cron, manual worker commands, Supervisor, systemd, and useful Laravel queue commands.
            </p>
            <div class="d-grid gap-2">
                <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#queueWorkerDocModal">
                    <i class="bi bi-book me-1"></i> View Full Documentation
                </button>
            </div>
        </div>
    </div>

    <!-- -------------------------------------------------------------------------------------------------- -->
    <!-- Documentation modal -->
    <!-- Full worker setup guide shown on demand from the rightbar documentation card. -->
    <!-- -------------------------------------------------------------------------------------------------- -->
    <div class="modal fade" id="queueWorkerDocModal" tabindex="-1" aria-labelledby="queueWorkerDocModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content text-start">
                <div class="modal-header">
                    <h5 class="modal-title" id="queueWorkerDocModalLabel">Queue and Worker Setup</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <h5>Scheduler cron</h5>
                    <p class="text-muted">Laravel's scheduler should be called every minute. It can dispatch scheduled queue jobs and maintenance tasks.</p>
                    <pre><code>* * * * * cd {{ $applicationPath }} && php artisan schedule:run >> /dev/null 2>&1</code></pre>

                    <h5>Manual worker command</h5>
                    <p class="text-muted">Useful while developing or debugging. Stop with Ctrl+C.</p>
                    <pre><code>cd {{ $applicationPath }}
php artisan queue:work --queue={{ $status['worker_queues'] }} --sleep=3 --tries=3 --timeout=120</code></pre>

                    <h5>Supervisor example</h5>
                    <p class="text-muted">Recommended for long-running workers on a traditional Linux server.</p>
                    <pre><code>[program:tdpsa-worker]
process_name=%(program_name)s_%(process_num)02d
command=php {{ $applicationPath }}/artisan queue:work --queue={{ $status['worker_queues'] }} --sleep=3 --tries=3 --timeout=120
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile={{ $applicationPath }}/storage/logs/worker.log
stopwaitsecs=3600</code></pre>

                    <h5>systemd example</h5>
                    <p class="text-muted">Use this style when systemd owns application processes.</p>
                    <pre><code>[Unit]
Description=Nexum PSA Laravel queue worker
After=network.target

[Service]
WorkingDirectory={{ $applicationPath }}
ExecStart=/usr/bin/php artisan queue:work --queue={{ $status['worker_queues'] }} --sleep=3 --tries=3 --timeout=120
Restart=always
RestartSec=5
User=www-data

[Install]
WantedBy=multi-user.target</code></pre>

                    <h5>Useful shell commands</h5>
                    <pre><code>php artisan queue:restart
php artisan queue:failed
php artisan queue:retry all
php artisan queue:flush
php artisan queue:clear --queue={{ $status['configured_queue'] }}</code></pre>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
@endsection
