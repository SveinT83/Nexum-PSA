@can('storage.purchase_import_profile_manage')
    {{-- Fixtures contain a bounded canonical expectation and a re-sanitized source, never raw mail or uploads. --}}
    <div class="card-body border-bottom">
        <h3 class="h6">Add Protected Fixture from Reviewed Import</h3>
        <p class="small text-muted">
            Select a reviewed import and the profile version that must replay it. Nexum stores only a sanitized
            source snapshot and the bounded canonical facts needed to detect regressions.
        </p>

        @if($profile->versions->isEmpty())
            <div class="alert alert-light border small mb-0">Create a profile version before adding a fixture.</div>
        @elseif($fixtureCandidates->isEmpty())
            <div class="alert alert-light border small mb-0">
                No reviewed imports with a validated canonical document are available for this profile.
            </div>
        @else
            <form method="POST"
                  action="{{ route('tech.admin.settings.storage.supplier-order-profiles.fixtures.store', $profile) }}"
                  class="row g-2">
                @csrf
                <div class="col-12">
                    <label for="fixture_name" class="form-label small">Fixture name</label>
                    <input id="fixture_name" name="fixture_name" type="text" maxlength="255" required
                           class="form-control form-control-sm @error('fixture_name') is-invalid @enderror"
                           value="{{ old('fixture_name') }}"
                           placeholder="Reviewed order layout">
                    @error('fixture_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-5">
                    <label for="profile_version_id" class="form-label small">Profile version</label>
                    @php($selectedVersionId = old('profile_version_id', $profile->active_version_id ?: $profile->versions->first()?->id))
                    <select id="profile_version_id" name="profile_version_id" required
                            class="form-select form-select-sm @error('profile_version_id') is-invalid @enderror">
                        @foreach($profile->versions as $version)
                            <option value="{{ $version->id }}" @selected((string) $selectedVersionId === (string) $version->id)>
                                v{{ $version->version_number }} - {{ str($version->status)->replace('_', ' ')->title() }}
                            </option>
                        @endforeach
                    </select>
                    @error('profile_version_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-7">
                    <label for="purchase_order_import_id" class="form-label small">Reviewed import</label>
                    <select id="purchase_order_import_id" name="purchase_order_import_id" required
                            class="form-select form-select-sm @error('purchase_order_import_id') is-invalid @enderror">
                        <option value="">Select import</option>
                        @foreach($fixtureCandidates as $candidate)
                            <option value="{{ $candidate->id }}" @selected((string) old('purchase_order_import_id') === (string) $candidate->id)>
                                #{{ $candidate->id }} - {{ $candidate->external_order_number ?: 'No external order number' }}
                                - {{ str($candidate->status)->replace('_', ' ')->title() }}
                            </option>
                        @endforeach
                    </select>
                    @error('purchase_order_import_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-sm btn-outline-primary">
                        Save Fixture and Run Fresh Replay
                    </button>
                </div>
            </form>
        @endif
    </div>
@endcan
