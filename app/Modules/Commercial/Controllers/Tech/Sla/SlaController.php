<?php

namespace App\Modules\Commercial\Controllers\Tech\Sla;

use App\Http\Controllers\Controller;
use App\Modules\Commercial\Models\Sla\Sla;
use App\Modules\Commercial\Requests\SlaRequest;

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

        return view('commercial::Tech.cs.sla.index', [
            'sla' => $sla,
        ]);
    }

    // -----------------------------------------
    // SHOW - Show a single sla profile
    // -----------------------------------------
    public function show(Sla $sla)
    {
        return view('commercial::Tech.cs.sla.form', [
            'sla' => $sla,
        ]);
    }

    // -----------------------------------------
    // CREATE - Show Create form
    // -----------------------------------------
    public function create()
    {
        return view('commercial::Tech.cs.sla.form');
    }

    // -----------------------------------------
    // STORE - Store the cost from form
    // -----------------------------------------
    public function store(SlaRequest $request)
    {
        $sla = app(\App\Modules\Commercial\Actions\SlaCatalogueWorkflow::class)->store($request);

        return redirect()->route('tech.sla.index')->with('success', 'Sla created successfully');
    }

    // -----------------------------------------
    // UPDATE - Store updated sla
    // -----------------------------------------
    public function update(SlaRequest $request, Sla $sla)
    {
        $sla = app(\App\Modules\Commercial\Actions\SlaCatalogueWorkflow::class)->update($request, $sla);

        return redirect()
            ->route('tech.sla.index')
            ->with('success', 'SLA updated successfully');
    }

    private function ensureSingleDefault(Sla $sla): void
    {
        if (! $sla->is_default) {
            if (! Sla::query()->where('is_default', true)->exists()) {
                $sla->forceFill(['is_default' => true])->save();
            }

            return;
        }

        Sla::query()
            ->whereKeyNot($sla->id)
            ->update(['is_default' => false]);
    }
}
