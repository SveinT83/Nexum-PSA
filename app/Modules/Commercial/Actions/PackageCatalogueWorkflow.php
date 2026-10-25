<?php

namespace App\Modules\Commercial\Actions;

use App\Modules\Commercial\Models\Packages\Package;
use App\Modules\Commercial\Support\CommercialInput;
use Illuminate\Http\Request;

/** Authoritative Package persistence shared by browser forms and typed API. */
class PackageCatalogueWorkflow
{
    public function store(Request $request)
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($request) {
            $validated = $this->validated($request, $package ?? null);

            $package = Package::create([
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'sales_price_user' => $validated['sales_price_user'] ?? null,
                'sales_price_asset' => $validated['sales_price_asset'] ?? null,
                'sales_price_site' => $validated['sales_price_site'] ?? null,
                'sales_price_client' => $validated['sales_price_client'] ?? null,
                'sales_price_other' => $validated['sales_price_other'] ?? null,
                'created_by_user_id' => $request->user()->id,
            ]);

            if (isset($validated['services'])) {
                $package->services()->sync($validated['services']);
            }

            if (isset($validated['terms'])) {
                $package->terms()->sync($validated['terms']);
            }

            return $package->refresh();

        }, 3);
    }

    public function update(Request $request, Package $package)
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($request, $package) {
            $package = $package->newQuery()->lockForUpdate()->findOrFail($package->id);

            $validated = $this->validated($request, $package ?? null);

            $package->update([
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'sales_price_user' => $validated['sales_price_user'] ?? null,
                'sales_price_asset' => $validated['sales_price_asset'] ?? null,
                'sales_price_site' => $validated['sales_price_site'] ?? null,
                'sales_price_client' => $validated['sales_price_client'] ?? null,
                'sales_price_other' => $validated['sales_price_other'] ?? null,
                'updated_by_user_id' => $request->user()->id,
            ]);

            $package->services()->sync($validated['services'] ?? []);
            $package->terms()->sync($validated['terms'] ?? []);

            return $package->refresh();

        }, 3);
    }

    public function rules(Request $request): array
    {
        return [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'sales_price_user' => 'nullable|numeric',
            'sales_price_asset' => 'nullable|numeric',
            'sales_price_site' => 'nullable|numeric',
            'sales_price_client' => 'nullable|numeric',
            'sales_price_other' => 'nullable|numeric',
            'services' => 'nullable|array',
            'services.*' => 'exists:services,id',
            'terms' => 'nullable|array',
            'terms.*' => 'exists:terms,id',
        ];
    }

    public function validated(Request $request, ?Package $record = null): array
    {
        $rules = $this->rules($request);
        $base = $record ? $record->only(array_keys($rules)) : [];
        if ($record && $request->is('api/*')) {
            $base['services'] = $record->services()->pluck('services.id')->all();
            $base['terms'] = $record->terms()->pluck('terms.id')->all();
        }
        $payload = array_merge($base, $request->all());

        return CommercialInput::validate($request, $rules, $payload);
    }
}
