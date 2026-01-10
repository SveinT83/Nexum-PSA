<?php

namespace App\Http\Controllers\Tech\CS\Package;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tech\CS\PackageRequest;
use App\Models\CS\Packages\Package;

class PackageController extends Controller
{
    // -----------------------------------------
    // INDEX - Show a list of all packages
    // -----------------------------------------
    public function index()
    {
        $packages = Package::withCount('services')->orderBy('name')->get();

        return view('tech.cs.packages.index', [
            'packages' => $packages,
        ]);
    }

    // -----------------------------------------
    // CREATE - Show a create form
    // -----------------------------------------
    public function create()
    {
        return view('tech.cs.packages.form', [
            'package' => new Package(),
        ]);
    }

    // -----------------------------------------
    // STORE - Store a new package
    // -----------------------------------------
    public function store(PackageRequest $request)
    {
        $validated = $request->validated();

        $package = Package::create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'sales_price_user' => $validated['sales_price_user'] ?? null,
            'sales_price_asset' => $validated['sales_price_asset'] ?? null,
            'sales_price_site' => $validated['sales_price_site'] ?? null,
            'sales_price_client' => $validated['sales_price_client'] ?? null,
            'sales_price_other' => $validated['sales_price_other'] ?? null,
            'created_by_user_id' => auth()->id(),
        ]);

        if (isset($validated['services'])) {
            $package->services()->sync($validated['services']);
        }

        if (isset($validated['terms'])) {
            $package->terms()->sync($validated['terms']);
        }

        return redirect()->route('tech.packages.index')->with('success', 'Package created successfully.');
    }

    // -----------------------------------------
    // SHOW - Show a single package
    // -----------------------------------------
    public function show(Package $package)
    {
        return view('tech.cs.packages.form', [
            'package' => $package,
        ]);
    }

    // -----------------------------------------
    // EDIT - Show edit form
    // -----------------------------------------
    public function edit(Package $package)
    {
        return view('tech.cs.packages.form', [
            'package' => $package,
        ]);
    }

    // -----------------------------------------
    // UPDATE - Update an existing package
    // -----------------------------------------
    public function update(PackageRequest $request, Package $package)
    {
        $validated = $request->validated();

        $package->update([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'sales_price_user' => $validated['sales_price_user'] ?? null,
            'sales_price_asset' => $validated['sales_price_asset'] ?? null,
            'sales_price_site' => $validated['sales_price_site'] ?? null,
            'sales_price_client' => $validated['sales_price_client'] ?? null,
            'sales_price_other' => $validated['sales_price_other'] ?? null,
            'updated_by_user_id' => auth()->id(),
        ]);

        $package->services()->sync($validated['services'] ?? []);
        $package->terms()->sync($validated['terms'] ?? []);

        return redirect()->route('tech.packages.index')->with('success', 'Package updated successfully.');
    }

    // -----------------------------------------
    // DESTROY - Delete a package
    // -----------------------------------------
    public function destroy(Package $package)
    {
        $package->delete();

        return redirect()->route('tech.packages.index')->with('success', 'Package deleted successfully.');
    }
}
