@extends('layouts.default_tech')

@section('title', 'Economy settings')

@section('pageHeader')
    <div>
        <h1 class="mb-1">Economy settings</h1>
        <p class="text-muted mb-0">Controls when ticket work becomes internal order lines.</p>
    </div>
@endsection

@section('sidebar')
    <x-nav.economy-menu />
@endsection

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Order Generation Settings -->
    <!-- ------------------------------------------------- -->
    <form method="POST" action="{{ route('tech.economy.settings.update') }}" class="card">
        @csrf
        @method('PATCH')
        <div class="card-header py-2">
            <h2 class="h6 mb-0">Order generation</h2>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" id="create_orders_from_closed_ticket_time" name="create_orders_from_closed_ticket_time" value="1" @checked($settings->create_orders_from_closed_ticket_time)>
                        <label class="form-check-label" for="create_orders_from_closed_ticket_time">Create orders from closed ticket time</label>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" id="create_orders_from_resolved_ticket_time" name="create_orders_from_resolved_ticket_time" value="1" @checked($settings->create_orders_from_resolved_ticket_time)>
                        <label class="form-check-label" for="create_orders_from_resolved_ticket_time">Create orders from resolved ticket time</label>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" id="include_unresolved_ticket_time_in_period_close" name="include_unresolved_ticket_time_in_period_close" value="1" @checked($settings->include_unresolved_ticket_time_in_period_close)>
                        <label class="form-check-label" for="include_unresolved_ticket_time_in_period_close">Include unresolved ticket time at period close</label>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" id="create_orders_from_picked_ticket_costs" name="create_orders_from_picked_ticket_costs" value="1" @checked($settings->create_orders_from_picked_ticket_costs)>
                        <label class="form-check-label" for="create_orders_from_picked_ticket_costs">Create orders from picked ticket costs</label>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" id="auto_pick_ticket_costs_on_resolved_or_closed_ticket" name="auto_pick_ticket_costs_on_resolved_or_closed_ticket" value="1" @checked($settings->auto_pick_ticket_costs_on_resolved_or_closed_ticket)>
                        <label class="form-check-label" for="auto_pick_ticket_costs_on_resolved_or_closed_ticket">Auto-pick available ticket costs on solved or closed tickets</label>
                    </div>
                </div>
                <div class="col-md-3">
                    <label for="order_prefix" class="form-label">Order prefix</label>
                    <input id="order_prefix" name="order_prefix" class="form-control" value="{{ old('order_prefix', $settings->order_prefix) }}" required>
                </div>
                <div class="col-md-3">
                    <label for="default_vat_rate" class="form-label">Default VAT %</label>
                    <input id="default_vat_rate" name="default_vat_rate" type="number" step="0.01" min="0" max="100" class="form-control" value="{{ old('default_vat_rate', $settings->default_vat_rate) }}">
                </div>
                <div class="col-md-3">
                    <label for="time_order_line_grouping" class="form-label">Time grouping</label>
                    <select id="time_order_line_grouping" name="time_order_line_grouping" class="form-select">
                        <option value="per_entry" @selected($settings->time_order_line_grouping === 'per_entry')>One line per entry</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="order_line_text_format" class="form-label">Line text</label>
                    <select id="order_line_text_format" name="order_line_text_format" class="form-select">
                        <option value="ticket_date_text" @selected($settings->order_line_text_format === 'ticket_date_text')>Ticket, date, text</option>
                    </select>
                </div>
            </div>
        </div>
        <div class="card-footer d-flex justify-content-end">
            <button type="submit" class="btn btn-primary">Save settings</button>
        </div>
    </form>
@endsection
