<?php

namespace App\Modules\Commercial\Controllers\Tech\Rates;

use App\Http\Controllers\Controller;
use App\Modules\Commercial\Models\TimeRate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TimeRateController extends Controller
{
    /**
     * Show the reusable commercial rate catalogue.
     */
    public function index(): View
    {
        return view('commercial::Tech.cs.rates.index', [
            'rates' => TimeRate::query()->orderBy('sort_order')->orderBy('name')->get(),
            'rateTypes' => $this->rateTypes(),
            'units' => $this->units(),
        ]);
    }

    /**
     * Create a rate that services and contracts can reuse.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data['slug'] = Str::slug($data['code']);

        TimeRate::query()->create($data);

        return back()->with('success', 'Time rate created.');
    }

    /**
     * Update a reusable rate. Existing contract snapshots are not rewritten.
     */
    public function update(Request $request, TimeRate $rate): RedirectResponse
    {
        $data = $this->validated($request, $rate);
        $data['slug'] = Str::slug($data['code']);

        $rate->update($data);

        return back()->with('success', 'Time rate updated.');
    }

    private function validated(Request $request, ?TimeRate $rate = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:80', Rule::unique('time_rates', 'code')->ignore($rate)],
            'rate_type' => ['required', Rule::in(array_keys($this->rateTypes()))],
            'unit' => ['required', Rule::in(array_keys($this->units()))],
            'amount_ex_vat' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'description' => ['nullable', 'string'],
            'applies_without_contract' => ['nullable', 'boolean'],
            'applies_with_contract' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]) + [
            'applies_without_contract' => false,
            'applies_with_contract' => false,
            'is_active' => false,
            'sort_order' => 0,
        ];
    }

    private function rateTypes(): array
    {
        return [
            'labor' => 'Labor',
            'driving' => 'Driving',
            'travel' => 'Travel',
            'other' => 'Other',
        ];
    }

    private function units(): array
    {
        return [
            'hour' => 'Hour',
            'km' => 'Kilometer',
            'fixed' => 'Fixed',
        ];
    }
}
