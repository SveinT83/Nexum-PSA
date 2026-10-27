<?php

namespace App\Modules\LeadIntelligence\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\LeadIntelligence\Support\LeadIntelligenceSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeadIntelligenceSettingsController extends Controller
{
    public function show(LeadIntelligenceSettings $settings): JsonResponse
    {
        return response()->json(['data' => $settings->get()]);
    }

    public function update(Request $request, LeadIntelligenceSettings $settings): JsonResponse
    {
        return response()->json(['data' => app(\App\Modules\LeadIntelligence\Actions\SaveLeadSettings::class)->handle($request->all())]);
    }
}
