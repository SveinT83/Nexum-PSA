@extends('layouts.default_tech')

@section('title', $profile->name)

@section('sidebar')
    <x-nav.admin-menu group="storage" />
@endsection

@section('pageHeader')
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
        <div>
            <h1 class="mb-0">{{ $profile->name }}</h1>
            <div class="small text-muted">{{ $profile->slug }} / immutable version history</div>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('tech.admin.settings.storage.supplier-order-profiles.export', $profile) }}" class="btn btn-sm btn-outline-secondary">Export JSON</a>
            <a href="{{ route('tech.admin.settings.storage.supplier-order-profiles.edit', $profile) }}" class="btn btn-sm btn-outline-primary">Edit Metadata</a>
            <x-buttons.addlink :url="route('tech.admin.settings.storage.supplier-order-profiles.versions.create', $profile)" class="mb-0">New Version</x-buttons.addlink>
            <x-buttons.back :url="route('tech.admin.settings.storage.supplier-order-profiles.index')" class="mb-0">Profiles</x-buttons.back>
        </div>
    </div>
@endsection

@section('content')
    <div class="container-fluid">
        @php
            $protectedFixtures = $profile->fixtures->where('is_protected', true);
            $testResult = session('profile_test_result');
            $stateClass = match($profile->lifecycle_state) {
                'active' => 'text-bg-success',
                'degraded' => 'text-bg-warning',
                'paused', 'retired' => 'text-bg-secondary',
                default => 'text-bg-light border',
            };
        @endphp

        @if($errors->any())
            <div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif
        @if($testResult)
            <div class="alert {{ data_get($testResult, 'valid') ? 'alert-success' : 'alert-warning' }}">
                <strong>Profile validation {{ data_get($testResult, 'valid') ? 'passed' : 'failed' }}.</strong>
                @if(data_get($testResult, 'errors'))
                    <ul class="mb-0 mt-1">
                        @foreach(data_get($testResult, 'errors', []) as $error)
                            <li>{{ $error['code'] ?? 'validation_error' }}: {{ $error['message'] ?? 'Unknown error' }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endif

        {{-- Container metadata is audited separately from immutable runtime definitions. --}}
        <div class="card mb-3">
            <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                <div class="d-flex align-items-center gap-2">
                    <h2 class="h6 mb-0">Profile State</h2>
                    <span class="badge {{ $stateClass }}">{{ str($profile->lifecycle_state)->title() }}</span>
                    <span class="badge text-bg-light border">{{ str($profile->health_state ?: 'unknown')->replace('_', ' ')->title() }}</span>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    @if(! in_array($profile->lifecycle_state, ['paused', 'retired'], true))
                        <details>
                            <summary class="btn btn-sm btn-outline-warning">Pause</summary>
                            <form method="POST" action="{{ route('tech.admin.settings.storage.supplier-order-profiles.pause', $profile) }}"
                                  class="border rounded bg-body p-2 mt-2" style="min-width: 20rem;">
                                @csrf
                                <label for="pause_reason" class="form-label small">Reason</label>
                                <textarea id="pause_reason" name="reason" rows="2" minlength="5" maxlength="245" class="form-control form-control-sm" required></textarea>
                                <button type="submit" class="btn btn-sm btn-warning w-100 mt-2">Pause Profile</button>
                            </form>
                        </details>
                    @endif
                    @if($profile->lifecycle_state !== 'retired')
                        <details>
                            <summary class="btn btn-sm btn-outline-danger">Retire</summary>
                            <form method="POST" action="{{ route('tech.admin.settings.storage.supplier-order-profiles.retire', $profile) }}"
                                  class="border rounded bg-body p-2 mt-2" style="min-width: 20rem;">
                                @csrf
                                <label for="retire_reason" class="form-label small">Reason</label>
                                <textarea id="retire_reason" name="reason" rows="2" minlength="5" maxlength="245" class="form-control form-control-sm" required></textarea>
                                <button type="submit" class="btn btn-sm btn-danger w-100 mt-2">Retire Profile</button>
                            </form>
                        </details>
                    @endif
                </div>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-sm-6 col-xl-3"><div class="small text-muted">Supplier</div><div class="fw-semibold">{{ $profile->vendor?->name ?: 'Generic' }}</div></div>
                    <div class="col-sm-6 col-xl-3"><div class="small text-muted">Priority</div><div>{{ $profile->priority }}</div></div>
                    <div class="col-sm-6 col-xl-3"><div class="small text-muted">Active version</div><div>{{ $profile->activeVersion?->version_number ? 'v'.$profile->activeVersion->version_number : '-' }}</div></div>
                    <div class="col-sm-6 col-xl-3"><div class="small text-muted">Consecutive failures</div><div>{{ $profile->consecutive_failures }}</div></div>
                    <div class="col-sm-6 col-xl-3"><div class="small text-muted">Last matched</div><div>{{ $profile->last_matched_at?->format('d.m.Y H:i') ?: '-' }}</div></div>
                    <div class="col-sm-6 col-xl-3"><div class="small text-muted">Last success</div><div>{{ $profile->last_success_at?->format('d.m.Y H:i') ?: '-' }}</div></div>
                    <div class="col-sm-6 col-xl-3"><div class="small text-muted">Protected fixtures</div><div>{{ $protectedFixtures->count() }}</div></div>
                    <div class="col-sm-6 col-xl-3"><div class="small text-muted">All fixtures</div><div>{{ $profile->fixtures->count() }}</div></div>
                </div>
                @if($profile->description)
                    <p class="mt-3 mb-0">{{ $profile->description }}</p>
                @endif
                @if($profile->pause_reason)
                    <div class="alert alert-light border mt-3 mb-0"><strong>Pause reason:</strong> {{ $profile->pause_reason }}</div>
                @endif
                @if($protectedFixtures->isEmpty())
                    <div class="alert alert-warning mt-3 mb-0">
                        Activation is unavailable until at least one protected fixture exists and every protected replay passes.
                    </div>
                @endif
            </div>
        </div>

        {{-- Version actions preserve a tested, reasoned activation and rollback trail. --}}
        <div class="card mb-3">
            <div class="card-header d-flex align-items-center justify-content-between gap-2">
                <h2 class="h6 mb-0">Immutable Versions</h2>
                <span class="badge text-bg-light border">{{ $profile->versions->count() }} versions</span>
            </div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                    <tr><th>Version</th><th>Status</th><th>Source</th><th>Created</th><th>Validated</th><th>Activated</th><th>Checksum</th><th style="min-width: 20rem;">Actions</th></tr>
                    </thead>
                    <tbody>
                    @forelse($profile->versions as $version)
                        @php
                            $isRollback = $profile->active_version_id !== null
                                && (int) $profile->active_version_id !== (int) $version->id
                                && $version->status === 'superseded';
                        @endphp
                        <tr id="profile-version-{{ $version->id }}">
                            <td class="fw-semibold">v{{ $version->version_number }}</td>
                            <td><span class="badge text-bg-light border">{{ str($version->status)->title() }}</span></td>
                            <td>{{ $version->source }}</td>
                            <td>{{ $version->created_at?->format('d.m.Y H:i') }}<div class="small text-muted">{{ $version->creator?->name ?: 'System' }}</div></td>
                            <td>{{ $version->validated_at?->format('d.m.Y H:i') ?: '-' }}</td>
                            <td>
                                {{ $version->activated_at?->format('d.m.Y H:i') ?: '-' }}
                                @if($version->activator)<div class="small text-muted">{{ $version->activator->name }}</div>@endif
                            </td>
                            <td class="small font-monospace">{{ str($version->checksum)->limit(18) }}</td>
                            <td>
                                <div class="d-flex flex-wrap gap-1">
                                    <form method="POST" action="{{ route('tech.admin.settings.storage.supplier-order-profiles.versions.test', [$profile, $version]) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-outline-primary">Test</button>
                                    </form>
                                    <a href="{{ route('tech.admin.settings.storage.supplier-order-profiles.versions.create', [$profile, 'from_version' => $version->id]) }}"
                                       class="btn btn-sm btn-outline-secondary">Clone</a>
                                </div>

                                @if(in_array($version->status, ['validated', 'superseded', 'active'], true))
                                    <details class="mt-2">
                                        <summary class="btn btn-sm btn-outline-success" @if($protectedFixtures->isEmpty()) title="Protected fixture required" @endif>
                                            {{ $isRollback ? 'Roll back' : ((int) $profile->active_version_id === (int) $version->id ? 'Re-activate' : 'Activate') }}
                                        </summary>
                                        @if($protectedFixtures->isNotEmpty())
                                            <form method="POST"
                                                  action="{{ $isRollback
                                                      ? route('tech.admin.settings.storage.supplier-order-profiles.versions.rollback', [$profile, $version])
                                                      : route('tech.admin.settings.storage.supplier-order-profiles.versions.activate', [$profile, $version]) }}"
                                                  class="border rounded p-2 mt-2">
                                                @csrf
                                                <label for="version_reason_{{ $version->id }}" class="form-label small">Activation reason</label>
                                                <textarea id="version_reason_{{ $version->id }}" name="reason" rows="2" minlength="5" maxlength="245"
                                                          class="form-control form-control-sm" required></textarea>
                                                <button type="submit" class="btn btn-sm btn-success w-100 mt-2">Run Fresh Replay and Apply</button>
                                            </form>
                                        @else
                                            <div class="small text-muted mt-1">Add a protected fixture before activation.</div>
                                        @endif
                                    </details>
                                @endif

                                <details class="small mt-2">
                                    <summary>Definition</summary>
                                    <pre class="bg-body-tertiary border rounded p-2 mt-1 text-wrap">{{ json_encode($version->definition, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                </details>
                                @if($version->test_metrics)
                                    <details class="small mt-1">
                                        <summary>Test metrics</summary>
                                        <pre class="bg-body-tertiary border rounded p-2 mt-1 text-wrap">{{ json_encode($version->test_metrics, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                    </details>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-center text-muted py-4">No versions stored.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="row g-3">
            {{-- This audited container copy is descriptive; runtime reads the active version. --}}
            <div class="col-xl-6">
                <div class="card h-100">
                    <div class="card-header"><h2 class="h6 mb-0">Container Matching Scope</h2></div>
                    <div class="card-body">
                        <pre class="small bg-body-tertiary border rounded p-3 mb-0 text-wrap">{{ json_encode($profile->matching_scope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                        <div class="small text-muted mt-2">
                            Runtime matching remains pinned to the active immutable version's <code>definition.match</code>.
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-6">
                <div class="card h-100">
                    <div class="card-header"><h2 class="h6 mb-0">Protected Fixture Results</h2></div>
                    @include('storage::Admin.PurchaseOrderImportProfiles._fixture-form')
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead class="table-light"><tr><th>Name</th><th>Version</th><th>Result</th><th>Tested</th></tr></thead>
                            <tbody>
                            @forelse($protectedFixtures as $fixture)
                                <tr>
                                    <td>{{ $fixture->name }}</td>
                                    <td>{{ $fixture->profileVersion?->version_number ? 'v'.$fixture->profileVersion->version_number : '-' }}</td>
                                    <td>{{ str($fixture->last_result ?: 'not_tested')->replace('_', ' ')->title() }}</td>
                                    <td>{{ $fixture->last_tested_at?->format('d.m.Y H:i') ?: '-' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-center text-muted py-4">No protected fixtures are available.</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        {{-- Every mutable metadata write retains actor, reason, and exact snapshots. --}}
        <div class="card mt-3 mb-4">
            <div class="card-header d-flex align-items-center justify-content-between gap-2">
                <h2 class="h6 mb-0">Metadata Audit Trail</h2>
                <span class="badge text-bg-light border">{{ $profile->metadataAudits->count() }} changes</span>
            </div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                    <tr><th>Changed</th><th>Actor</th><th>Fields</th><th>Reason</th><th>Snapshots</th></tr>
                    </thead>
                    <tbody>
                    @forelse($profile->metadataAudits as $audit)
                        <tr>
                            <td class="text-nowrap">{{ $audit->created_at?->format('d.m.Y H:i') }}</td>
                            <td>{{ $audit->actor?->name ?: 'System' }}</td>
                            <td>
                                @foreach((array) $audit->changed_fields as $field)
                                    <span class="badge text-bg-light border">{{ str($field)->replace('_', ' ')->title() }}</span>
                                @endforeach
                            </td>
                            <td>{{ $audit->reason }}</td>
                            <td>
                                <details>
                                    <summary class="small">Before / after</summary>
                                    <div class="row g-2 mt-1" style="min-width: 42rem;">
                                        <div class="col-md-6">
                                            <div class="small fw-semibold">Before</div>
                                            <pre class="small bg-body-tertiary border rounded p-2 mb-0 text-wrap">{{ json_encode($audit->before_snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="small fw-semibold">After</div>
                                            <pre class="small bg-body-tertiary border rounded p-2 mb-0 text-wrap">{{ json_encode($audit->after_snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                        </div>
                                    </div>
                                </details>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-muted py-4">No metadata changes have been recorded.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
