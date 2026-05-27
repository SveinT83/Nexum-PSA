<?php

namespace App\Modules\Economy\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Economy\Actions\EnsureEconomyDefaults;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EconomySettingsController extends Controller
{
    public function index(EnsureEconomyDefaults $defaults): View
    {
        return view('economy::Admin.settings', [
            'settings' => $defaults->handle(),
        ]);
    }

    public function update(Request $request, EnsureEconomyDefaults $defaults): RedirectResponse
    {
        $settings = $defaults->handle();
        $data = $request->validate([
            'create_orders_from_resolved_ticket_time' => 'nullable|boolean',
            'create_orders_from_closed_ticket_time' => 'nullable|boolean',
            'include_unresolved_ticket_time_in_period_close' => 'nullable|boolean',
            'create_orders_from_picked_ticket_costs' => 'nullable|boolean',
            'auto_pick_ticket_costs_on_resolved_or_closed_ticket' => 'nullable|boolean',
            'time_order_line_grouping' => 'required|string|in:per_entry',
            'order_line_text_format' => 'required|string|in:ticket_date_text',
            'order_prefix' => 'required|string|max:20',
            'default_vat_rate' => 'nullable|numeric|min:0|max:100',
        ]);

        foreach ([
            'create_orders_from_resolved_ticket_time',
            'create_orders_from_closed_ticket_time',
            'include_unresolved_ticket_time_in_period_close',
            'create_orders_from_picked_ticket_costs',
            'auto_pick_ticket_costs_on_resolved_or_closed_ticket',
        ] as $booleanField) {
            $data[$booleanField] = $request->boolean($booleanField);
        }

        $settings->update($data);

        return redirect()->route('tech.admin.settings.economy')
            ->with('success', 'Economy settings updated.');
    }
}
