@extends('layouts.default_tech')

@section('title', 'Client Timebank Policy')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center gap-2">
        <h1 class="h4 mb-0">Client Timebank Policy</h1>
        <x-buttons.back url="{{ route('tech.admin.settings.cs.contracts') }}" class="mb-0">Back</x-buttons.back>
    </div>
@endsection

@section('content')
    <!-- Client timebank quick registration policy -->
    <form method="post" action="{{ route('tech.admin.settings.cs.timebank-policy.update') }}">
        @csrf
        @method('PUT')

        <div class="row g-3">
            <div class="col-xl-8">
                <div class="card">
                    <div class="card-header">
                        <h2 class="h5 mb-0">Quick Registration</h2>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="form-check form-switch">
                                    <input type="checkbox" class="form-check-input" id="quick_timebank_enabled" name="quick_timebank_enabled" value="1" @checked(old('quick_timebank_enabled', $policy['quick_timebank_enabled']))>
                                    <label class="form-check-label" for="quick_timebank_enabled">Allow quick timebank registration</label>
                                </div>
                                <div class="form-text">Shows the modal action on Client Contracts tab for authorized technicians.</div>
                            </div>

                            <div class="col-md-6">
                                <div class="form-check form-switch">
                                    <input type="checkbox" class="form-check-input" id="quick_timebank_require_remaining" name="quick_timebank_require_remaining" value="1" @checked(old('quick_timebank_require_remaining', $policy['quick_timebank_require_remaining']))>
                                    <label class="form-check-label" for="quick_timebank_require_remaining">Require remaining included time</label>
                                </div>
                                <div class="form-text">Blocks quick registration when the period has no remaining time.</div>
                            </div>

                            <div class="col-md-6">
                                <div class="form-check form-switch">
                                    <input type="checkbox" class="form-check-input" id="quick_timebank_allow_overuse" name="quick_timebank_allow_overuse" value="1" @checked(old('quick_timebank_allow_overuse', $policy['quick_timebank_allow_overuse']))>
                                    <label class="form-check-label" for="quick_timebank_allow_overuse">Allow direct overuse</label>
                                </div>
                                <div class="form-text">Still requires the technician to have the overconsume permission.</div>
                            </div>

                            <div class="col-md-6">
                                <div class="form-check form-switch">
                                    <input type="checkbox" class="form-check-input" id="quick_timebank_require_note" name="quick_timebank_require_note" value="1" @checked(old('quick_timebank_require_note', $policy['quick_timebank_require_note']))>
                                    <label class="form-check-label" for="quick_timebank_require_note">Require note</label>
                                </div>
                                <div class="form-text">Keeps no-ticket time consumption auditable.</div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label" for="quick_timebank_max_minutes">Max minutes per quick entry</label>
                                <input type="number" min="1" max="1440" class="form-control @error('quick_timebank_max_minutes') is-invalid @enderror" id="quick_timebank_max_minutes" name="quick_timebank_max_minutes" value="{{ old('quick_timebank_max_minutes', $policy['quick_timebank_max_minutes']) }}" required>
                                @error('quick_timebank_max_minutes')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                    </div>
                    <div class="card-footer text-end">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg" aria-hidden="true"></i>
                            Save policy
                        </button>
                    </div>
                </div>
            </div>

            <div class="col-xl-4">
                <x-card.default title="Operational Rules">
                    <p class="small text-muted mb-2">
                        The Client Contracts tab always calculates balances for users with view permission.
                    </p>
                    <p class="small text-muted mb-2">
                        Quick entries are stored as audit records and do not create Tickets or order lines.
                    </p>
                    <p class="small text-muted mb-0">
                        Overuse billing is a separate Economy integration decision.
                    </p>
                </x-card.default>
            </div>
        </div>
    </form>
@endsection

@section('sidebar')
    <x-nav.admin-menu group="commercial" />
@endsection
