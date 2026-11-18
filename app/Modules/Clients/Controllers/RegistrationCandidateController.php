<?php

namespace App\Modules\Clients\Controllers;

use App\Modules\Clients\Actions\FindRegistrationCandidates;
use App\Modules\Integration\Support\DomainApiAuthorization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The browser and official API share the same read-only discovery operation. */
final class RegistrationCandidateController
{
    public function __invoke(Request $request, FindRegistrationCandidates $candidates): JsonResponse
    {
        app(DomainApiAuthorization::class)->authorize($request, 'client.create');
        $input = $request->validate([
            'email' => 'nullable|email|max:255', 'billing_email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50', 'org_no' => 'nullable|string|max:50',
        ]);
        $choices = $candidates->handle($request, $input);

        return response()->json(['state' => $choices ? 'selection_required' : 'no_match', 'candidates' => $choices])
            ->header('Cache-Control', 'private, no-store');
    }
}
