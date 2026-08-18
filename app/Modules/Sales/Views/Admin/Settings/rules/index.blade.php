@extends('layouts.default_tech')

@section('title', 'Tech Admin Settings Sales Rules')

@section('pageHeader')
    <h1>Sales Rules</h1>
@endsection

@section('content')
    <div class="card">
        <div class="card-header">
            <h2 class="h5 mb-0">CPQ approval policy</h2>
        </div>
        <div class="card-body">
            <form method="POST" action="{{ route('tech.admin.settings.sales.rules.update') }}" class="row g-3">
                @csrf
                <div class="col-12">
                    <div class="form-check form-switch">
                        <input type="hidden" name="enabled" value="0">
                        <input class="form-check-input" type="checkbox" id="cpq_policy_enabled" name="enabled" value="1" @checked(old('enabled', $policy['enabled'] ?? true))>
                        <label class="form-check-label" for="cpq_policy_enabled">Require approval when quote risk thresholds are met</label>
                    </div>
                </div>

                <div class="col-md-3">
                    <label for="discount_percent_threshold" class="form-label">Discount threshold %</label>
                    <input type="number" step="0.01" min="0" max="100" id="discount_percent_threshold" name="discount_percent_threshold" class="form-control" value="{{ old('discount_percent_threshold', $policy['discount_percent_threshold'] ?? 20) }}" required>
                </div>

                <div class="col-md-3">
                    <label for="minimum_margin_percent" class="form-label">Minimum margin %</label>
                    <input type="number" step="0.01" min="-100" max="100" id="minimum_margin_percent" name="minimum_margin_percent" class="form-control" value="{{ old('minimum_margin_percent', $policy['minimum_margin_percent'] ?? 10) }}" required>
                </div>

                <div class="col-md-3">
                    <label for="quote_total_ex_vat_threshold" class="form-label">Quote total threshold</label>
                    <input type="number" step="0.01" min="0" id="quote_total_ex_vat_threshold" name="quote_total_ex_vat_threshold" class="form-control" value="{{ old('quote_total_ex_vat_threshold', $policy['quote_total_ex_vat_threshold'] ?? 100000) }}" required>
                </div>

                <div class="col-md-3">
                    <label for="manual_line_ex_vat_threshold" class="form-label">Manual line threshold</label>
                    <input type="number" step="0.01" min="0" id="manual_line_ex_vat_threshold" name="manual_line_ex_vat_threshold" class="form-control" value="{{ old('manual_line_ex_vat_threshold', $policy['manual_line_ex_vat_threshold'] ?? 50000) }}" required>
                </div>

                <div class="col-12">
                    <button type="submit" class="btn btn-primary">Save approval policy</button>
                </div>
            </form>
        </div>
    </div>
@endsection

@section('sidebar')
    <x-nav.admin-menu group="sales" />
@endsection

@section('rightbar')
@endsection
