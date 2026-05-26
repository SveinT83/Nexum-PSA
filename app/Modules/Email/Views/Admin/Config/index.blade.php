@extends('layouts.default_tech')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center">
        <h1>Email Configuration</h1>
        <div class="d-flex gap-2">
            <button type="submit" form="config-form" class="btn btn-primary">Save Changes</button>
            <button type="button" class="btn btn-outline-secondary">Run Health Test</button>
        </div>
    </div>
@endsection

@section('content')
<div class="container-fluid">
    <form id="config-form" action="{{ route('tech.admin.settings.email.config.update') }}" method="POST">
        @csrf
        <div class="row">
            <div class="col-lg-8">
                <!-- 1. Ingest & Polling -->
                <div class="card mb-4 shadow-sm border-0">
                    <div class="card-header bg-light">
                        <h5 class="mb-0">Ingest & Polling</h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="poll_interval" class="form-label">Global poll interval (minutes)</label>
                                <select name="poll_interval" id="poll_interval" class="form-select">
                                    @foreach([1, 5, 15, 30] as $min)
                                        <option value="{{ $min }}" {{ (isset($config['poll_interval']) && $config['poll_interval'] == $min) ? 'selected' : '' }}>{{ $min }} min</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="concurrency" class="form-label">Max concurrent fetch</label>
                                <select name="concurrency" id="concurrency" class="form-select">
                                    @foreach([1, 2, 4, 8] as $val)
                                        <option value="{{ $val }}" {{ (isset($config['concurrency']) && $config['concurrency'] == $val) ? 'selected' : '' }}>{{ $val }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="batch_size" class="form-label">Messages per poll (Batch size)</label>
                                <input type="number" name="batch_size" id="batch_size" class="form-control" value="{{ $config['batch_size'] ?? 20 }}" min="1">
                            </div>
                            <div class="col-md-6">
                                <div class="form-check form-switch mt-4">
                                    <input class="form-check-input" type="checkbox" name="pause_ingest" id="pause_ingest" value="1" {{ (isset($config['pause_ingest']) && $config['pause_ingest'] == '1') ? 'checked' : '' }}>
                                    <label class="form-check-label" for="pause_ingest">Pause all ingest (Maintenance mode)</label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 2. Retention & Deletion -->
                <div class="card mb-4 shadow-sm border-0">
                    <div class="card-header bg-light">
                        <h5 class="mb-0">Retention & Deletion</h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-12">
                                <div class="form-check form-switch mb-3">
                                    <input class="form-check-input" type="checkbox" name="delete_on_success" id="delete_on_success" value="1" {{ (isset($config['delete_on_success']) && $config['delete_on_success'] == '1') ? 'checked' : '' }}>
                                    <label class="form-check-label" for="delete_on_success">Delete from server after successful import (Default)</label>
                                    <div class="form-text">Note: Per-account settings can override this.</div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label for="retention_months" class="form-label">Email retention (months)</label>
                                <input type="number" name="retention_months" id="retention_months" class="form-control" value="{{ $config['retention_months'] ?? 24 }}" min="0">
                            </div>
                            <div class="col-md-4">
                                <label for="size_limit_mb" class="form-label">Max message size (MB)</label>
                                <input type="number" name="size_limit_mb" id="size_limit_mb" class="form-control" value="{{ $config['size_limit_mb'] ?? 25 }}" min="1">
                            </div>
                            <div class="col-md-4">
                                <label for="max_failures" class="form-label">Alarm after X failures</label>
                                <input type="number" name="max_failures" id="max_failures" class="form-control" value="{{ $config['max_failures'] ?? 3 }}" min="1">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 3. Identification & Threading (Read-only) -->
                <div class="card mb-4 bg-light text-muted border-0">
                    <div class="card-header">
                        <h5 class="mb-0">Identification & Threading (Policy)</h5>
                    </div>
                    <div class="card-body">
                        <p class="small mb-1">Precedence: <strong>Headers</strong> (Message-ID / In-Reply-To) over Subject token.</p>
                        <p class="small mb-0">Subject token format: <code>[#{number}]</code></p>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card border-info mb-4 shadow-sm">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0">System Health</h5>
                    </div>
                    <div class="card-body p-0">
                        <ul class="list-group list-group-flush">
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                Active Accounts
                                <span class="badge bg-success rounded-pill">OK</span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                Queue Worker
                                <span class="badge bg-warning text-dark rounded-pill">Unknown</span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between align-items-center text-muted small">
                                Last Sync
                                <span>{{ now()->diffForHumans() }}</span>
                            </li>
                        </ul>
                    </div>
                </div>

                <div class="card shadow-sm border-0">
                    <div class="card-header bg-light">
                        <h5 class="mb-0">Shortcuts</h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="list-group list-group-flush">
                            <a href="{{ route('tech.admin.settings.email.accounts') }}" class="list-group-item list-group-item-action">Open Accounts</a>
                            <a href="{{ route('tech.admin.settings.email.rules') }}" class="list-group-item list-group-item-action">Open Rules</a>
                            <a href="{{ route('tech.inbox.index') }}" class="list-group-item list-group-item-action">Open Fallback Inbox</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>
@endsection
