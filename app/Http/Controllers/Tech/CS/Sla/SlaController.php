<?php

namespace App\Http\Controllers\Tech\CS\Sla;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tech\CS\SlaRequest;
use App\Models\CS\Sla\Sla;

class SlaController extends Controller
{
    // -----------------------------------------
    // INDEX - Show a list of all sla's
    // -----------------------------------------
    public function index()
    {

        $allowed = [
            'name' => 'name',
            'low_firstResponse' => 'low_firstResponse',
            'low_onsite' => 'low_onsite',
            'medium_firstResponse' => 'medium_firstResponse',
            'medium_onsite' => 'medium_onsite',
            'high_firstResponse' => 'high_firstResponse',
            'high_onsite' => 'high_onsite',
        ];

        $sla = Sla::query()
            ->select('sla.*')
            ->orderBy('sla.id') // stabil sortering
            ->get();

        return view('tech.cs.sla.index', [
            'sla' => $sla,
        ]);
    }

    // -----------------------------------------
    // SHOW - Show a single sla profile
    // -----------------------------------------
    public function show(Sla $sla)
    {
        return view('tech.cs.sla.form', [
            'sla' => $sla,
        ]);
    }

    // -----------------------------------------
    // CREATE - Show Create form
    // -----------------------------------------
    public function create()
    {
        return view('tech.cs.sla.form');
    }

    // -----------------------------------------
    // STORE - Store the cost from form
    // -----------------------------------------
    public function store(SlaRequest $request)
    {
        // Validate request via FormRequest
        $data = $request->validated();

        Sla::create([
            'name' => $data['name'],
            'description' => $data['description'],

            'low_firstResponse' => $data['low_firstResponse'],
            'low_firstResponse_type' => $data['low_firstResponse_type'],
            'low_onsite' => $data['low_onsite'],
            'low_onsite_type' => $data['low_onsite_type'],

            'medium_firstResponse' => $data['medium_firstResponse'],
            'medium_firstResponse_type' => $data['medium_firstResponse_type'],
            'medium_onsite' => $data['medium_onsite'],
            'medium_onsite_type' => $data['medium_onsite_type'],

            'high_firstResponse' => $data['high_firstResponse'],
            'high_firstResponse_type' => $data['high_firstResponse_type'],
            'high_onsite' => $data['high_onsite'],
            'high_onsite_type' => $data['high_onsite_type'],

            'created_by_user_id' => auth()->id(),
            'updated_by_user_id' => auth()->id(),
        ]);

        return redirect()->route('tech.sla.index')->with('success', 'Sla created successfully');
    }

    // -----------------------------------------
    // UPDATE - Store updated sla
    // -----------------------------------------
    public function update(SlaRequest $request, Sla $sla)
    {
        $data = $request->validated();

        $sla->update([
            'name' => $data['name'],
            'description' => $data['description'],

            'low_firstResponse' => $data['low_firstResponse'],
            'low_firstResponse_type' => $data['low_firstResponse_type'],
            'low_onsite' => $data['low_onsite'],
            'low_onsite_type' => $data['low_onsite_type'],

            'medium_firstResponse' => $data['medium_firstResponse'],
            'medium_firstResponse_type' => $data['medium_firstResponse_type'],
            'medium_onsite' => $data['medium_onsite'],
            'medium_onsite_type' => $data['medium_onsite_type'],

            'high_firstResponse' => $data['high_firstResponse'],
            'high_firstResponse_type' => $data['high_firstResponse_type'],
            'high_onsite' => $data['high_onsite'],
            'high_onsite_type' => $data['high_onsite_type'],

            'updated_by_user_id' => auth()->id(),
        ]);

        return redirect()
            ->route('tech.sla.index')
            ->with('success', 'SLA updated successfully');
    }
}
