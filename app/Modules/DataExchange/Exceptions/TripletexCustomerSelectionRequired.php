<?php

namespace App\Modules\DataExchange\Exceptions;

use Illuminate\Validation\ValidationException;

/** Duplicate preflight stopped before any customer POST; a corrected selection needs new keys. */
final class TripletexCustomerSelectionRequired extends ValidationException
{
    public array $providerCandidates = [];

    /** Keep the established validation status and field errors; expose identity choices to authorized API readers. */
    public function render(\Illuminate\Http\Request $request)
    {
        if (! $request->routeIs('api.v1.clients.store') || ! $request->user()?->tokenCan('clients.read')
            || ! $request->user()->can('client.view') || $request->user()->isSystemActor()) {
            return null;
        }

        return response()->json(['message' => $this->getMessage(), 'errors' => $this->errors(),
            'state' => 'selection_required', 'provider_candidates' => $this->providerCandidates,
            'lookup_url' => route('api.v1.clients.context.tripletex'),
        ], $this->status)->header('Cache-Control', 'private, no-store');
    }
}
