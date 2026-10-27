<?php

namespace App\Modules\LeadIntelligence\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\LeadIntelligence\Actions\PromoteLeadCandidate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeadIntelligencePromotionController extends Controller
{
    public function promoteCandidate(Request $request, PromoteLeadCandidate $promote): JsonResponse
    {
        $validated = \App\Modules\LeadIntelligence\Support\LeadPromotionInput::validate($request);

        try {
            return response()->json([
                'data' => $promote->handle($validated + ['tripletex_request_key' => $request->attributes->get('lead_command_id')]),
            ], ! empty($validated['dry_run']) ? 200 : 201);
        } catch (\Illuminate\Validation\ValidationException $error) {
            $id = $request->attributes->get('lead_command_id');
            if ($id && \App\Modules\LeadIntelligence\Support\LeadPromotionInput::pendingProvider($id, $request->user()->id)) {
                $request->attributes->set('lead_unknown', true);

                return response()->json(['data' => ['state' => 'unknown', 'reconciliation_required' => true]], 202);
            }
            throw $error;
        } catch (\Throwable) {
            $request->attributes->set('lead_unknown', true);

            return response()->json(['data' => ['state' => 'unknown', 'reconciliation_required' => true]], 202);
        }
    }
}
