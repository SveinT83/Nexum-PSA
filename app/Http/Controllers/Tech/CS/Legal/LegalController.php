<?php

namespace App\Http\Controllers\Tech\CS\Legal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tech\CS\termsRequest;
use App\Models\CS\Terms\terms;

class LegalController extends Controller
{
    // -----------------------------------------
    // INDEX - Show a list of all legal & terms
    // -----------------------------------------
    public function index()
    {

        $terms = terms::query()
            ->select('terms.*')
            ->orderBy('terms.id') // stabil sortering
            ->get();

        return view('tech.cs.legal.index', [
            'terms' => $terms,
        ]);
    }

    // -----------------------------------------
    // CREATE - Show Create form
    // -----------------------------------------
    public function create()
    {
        return view('tech.cs.legal.form');
    }

    // -----------------------------------------
    // STORE - Save the new legal or term
    // -----------------------------------------
    public function store(termsRequest $request)
    {
        // Validate request via FormRequest
        $data = $request->validated();

        //Save the form to DB
        $legal = terms::create($data);

        //Redirect wiew whit message
        return redirect()->route('tech.legal.index')
            ->with('success', 'Legal or Term created successfully.');
    }

    // -----------------------------------------
    // SHOW - Show a single legal or term
    // -----------------------------------------
    public function show(terms $term)
    {
        // $term kommer fra route model binding
        return view('tech.cs.legal.form', [
            'term' => $term,
        ]);
    }

    // -----------------------------------------
    // EDIT - Show Edit form
    // -----------------------------------------
    public function edit(terms $term)
    {
        return view('tech.cs.legal.form', [
            'term' => $term,
        ]);
    }

    // -----------------------------------------
    // UPDATE - Update a legal or term
    // -----------------------------------------
    public function update(termsRequest $request, terms $term)
    {

        //Validate data
        $data = $request->validated();

        //Update the term in the database
        $term->update($data);

        //Redirect with message
        return redirect()->route('tech.legal.index')
            ->with('success', 'Legal or Term updated successfully.');
    }

    // -----------------------------------------
    // DELETE - Delete a legal or term
    // -----------------------------------------
    public function delete(terms $term)
    {
        $term->delete();

        return redirect()->route('tech.legal.index')
            ->with('success', 'Legal or Term deleted successfully.');
    }
}
