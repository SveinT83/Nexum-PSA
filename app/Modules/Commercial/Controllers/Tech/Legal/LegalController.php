<?php

namespace App\Modules\Commercial\Controllers\Tech\Legal;

use App\Http\Controllers\Controller;
use App\Modules\Commercial\Requests\termsRequest;
use App\Modules\Commercial\Models\Terms\terms;
use App\Modules\Commercial\Services\LegalDocumentVersioning;
use Illuminate\Support\Facades\DB;

class LegalController extends Controller
{
    // -----------------------------------------
    // INDEX - Show a list of all legal & terms
    // -----------------------------------------
    public function index()
    {

        $terms = terms::query()
            ->select('terms.*')
            ->with('currentVersion')
            ->orderBy('terms.id') // stabil sortering
            ->get();

        return view('commercial::Tech.cs.legal.index', [
            'terms' => $terms,
        ]);
    }

    // -----------------------------------------
    // CREATE - Show Create form
    // -----------------------------------------
    public function create()
    {
        return view('commercial::Tech.cs.legal.form');
    }

    // -----------------------------------------
    // STORE - Save the new legal or term
    // -----------------------------------------
    public function store(termsRequest $request, LegalDocumentVersioning $versions)
    {
        $data = $request->validated();

        DB::transaction(function () use ($data, $versions): void {
            $term = terms::query()->create([
                ...$data,
                'origin' => 'nexum',
                'managed_externally' => false,
            ]);
            $versions->record($term, $data);
        });

        // Redirect with message
        return redirect()->route('tech.legal.index')
            ->with('success', 'Legal or Term created successfully.');
    }

    // -----------------------------------------
    // SHOW - Show a single legal or term
    // -----------------------------------------
    public function show(terms $term)
    {
        $term->load(['services', 'currentVersion', 'versions']);
        // $term kommer fra route model binding
        return view('commercial::Tech.cs.legal.form', [
            'term' => $term,
        ]);
    }

    // -----------------------------------------
    // EDIT - Show Edit form
    // -----------------------------------------
    public function edit(terms $term)
    {
        $term->load(['services', 'currentVersion', 'versions']);
        return view('commercial::Tech.cs.legal.form', [
            'term' => $term,
        ]);
    }

    // -----------------------------------------
    // UPDATE - Update a legal or term
    // -----------------------------------------
    public function update(termsRequest $request, terms $term, LegalDocumentVersioning $versions)
    {
        if ($term->isProviderManaged()) {
            return back()->with('error', 'Provider-managed legal documents are read-only and must be updated by synchronization.');
        }

        $data = $request->validated();

        DB::transaction(function () use ($term, $data, $versions): void {
            $term->update($data);
            $versions->record($term, $data);
        });

        // Redirect with message
        return redirect()->route('tech.legal.index')
            ->with('success', 'Legal or Term updated successfully.');
    }

    // -----------------------------------------
    // DELETE - Delete a legal or term
    // -----------------------------------------
    public function delete(terms $term)
    {
        if ($term->isProviderManaged()) {
            return redirect()->route('tech.legal.show', $term)
                ->with('error', 'Provider-managed legal documents cannot be deleted manually.');
        }

        if ($term->isInUse()) {
            return redirect()->route('tech.legal.show', $term)
                ->with('error', 'This legal or term cannot be deleted because it is currently in use.');
        }

        $term->delete();

        return redirect()->route('tech.legal.index')
            ->with('success', 'Legal or Term deleted successfully.');
    }
}
