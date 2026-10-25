<?php

namespace App\Modules\Commercial\Controllers\Tech\Package;

use App\Http\Controllers\Controller;
use App\Modules\Commercial\Models\Packages\Package;
use App\Modules\Commercial\Requests\PackageRequest;
use Illuminate\Http\Request;

class PackageController extends Controller
{
    // -----------------------------------------
    // INDEX - Show a list of all packages
    // -----------------------------------------
    public function index(Request $request)
    {
        $sort = $request->input('sort', 'name');
        $direction = $request->input('direction') === 'desc' ? 'desc' : 'asc';
        $sortableColumns = ['name', 'description', 'services', 'status', 'updated_at'];

        if (! in_array($sort, $sortableColumns, true)) {
            $sort = 'name';
        }

        $packagesQuery = Package::query()
            ->withCount('services')
            ->when($request->filled('q'), function ($query) use ($request): void {
                $search = '%'.$request->string('q')->trim()->toString().'%';

                $query->where(function ($query) use ($search): void {
                    $query->where('name', 'like', $search)
                        ->orWhere('description', 'like', $search)
                        ->orWhere('status', 'like', $search);
                });
            })
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->input('status')));

        if ($sort === 'services') {
            $packagesQuery->orderBy('services_count', $direction)->orderBy('name');
        } else {
            $packagesQuery->orderBy($sort, $direction)->orderBy('name');
        }

        $packages = $packagesQuery->paginate(20)->withQueryString();

        return view('commercial::Tech.cs.packages.index', [
            'packages' => $packages,
            'statuses' => Package::query()->distinct()->orderBy('status')->pluck('status')->filter()->values(),
            'filters' => $request->only(['q', 'status', 'sort', 'direction']),
        ]);
    }

    // -----------------------------------------
    // CREATE - Show a create form
    // -----------------------------------------
    public function create()
    {
        return view('commercial::Tech.cs.packages.form', [
            'package' => new Package,
        ]);
    }

    // -----------------------------------------
    // STORE - Store a new package
    // -----------------------------------------
    public function store(PackageRequest $request)
    {
        $package = app(\App\Modules\Commercial\Actions\PackageCatalogueWorkflow::class)->store($request);

        return redirect()->route('tech.packages.index')->with('success', 'Package created successfully.');
    }

    // -----------------------------------------
    // SHOW - Show a single package
    // -----------------------------------------
    public function show(Package $package)
    {
        return view('commercial::Tech.cs.packages.form', [
            'package' => $package,
        ]);
    }

    // -----------------------------------------
    // EDIT - Show edit form
    // -----------------------------------------
    public function edit(Package $package)
    {
        return view('commercial::Tech.cs.packages.form', [
            'package' => $package,
        ]);
    }

    // -----------------------------------------
    // UPDATE - Update an existing package
    // -----------------------------------------
    public function update(PackageRequest $request, Package $package)
    {
        $package = app(\App\Modules\Commercial\Actions\PackageCatalogueWorkflow::class)->update($request, $package);

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
