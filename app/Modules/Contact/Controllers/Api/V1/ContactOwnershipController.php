<?php

namespace App\Modules\Contact\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Contact\Actions\RepairContactOwnership;
use App\Modules\Contact\Models\Contact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ContactOwnershipController extends Controller
{
    public function index(string $client, RepairContactOwnership $repair): JsonResponse
    {
        return response()->json($repair->inspectClient($repair->resolveClient($client)));
    }

    public function move(Request $request, Contact $contact, RepairContactOwnership $repair): JsonResponse
    {
        $validated = $request->validate([
            'target_client_id' => [
                'nullable',
                'integer',
                'required_without:target_client_number',
                Rule::exists('clients', 'id'),
            ],
            'target_client_number' => [
                'nullable',
                'string',
                'max:100',
                'required_without:target_client_id',
            ],
            'target_site_id' => ['nullable', 'integer', Rule::exists('client_sites', 'id')],
            'dry_run' => ['sometimes', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        if ($request->filled('target_client_id') && $request->filled('target_client_number')) {
            throw ValidationException::withMessages([
                'target_client' => 'Use either target_client_id or target_client_number, not both.',
            ]);
        }

        $targetClient = $repair->resolveTargetClient(
            $validated['target_client_id'] ?? null,
            $validated['target_client_number'] ?? null,
        );

        $payload = $repair->moveContact(
            $contact,
            $targetClient,
            $validated['target_site_id'] ?? null,
            (bool) ($validated['dry_run'] ?? false),
            $validated['reason'] ?? null,
            $request,
        );

        $status = ! $payload['dry_run'] && $payload['plan']['status'] === 'conflict' ? 409 : 200;

        return response()->json($payload, $status);
    }

    public function bulkFix(string $client, Request $request, RepairContactOwnership $repair): JsonResponse
    {
        $validated = $request->validate([
            'contact_ids' => ['required', 'array', 'min:1', 'max:500'],
            'contact_ids.*' => ['integer'],
            'target_site_id' => ['nullable', 'integer', Rule::exists('client_sites', 'id')],
            'dry_run' => ['sometimes', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $targetClient = $repair->resolveClient($client);

        return response()->json($repair->bulkFix(
            $targetClient,
            $validated['contact_ids'],
            $validated['target_site_id'] ?? null,
            (bool) ($validated['dry_run'] ?? true),
            $validated['reason'] ?? null,
            $request,
        ));
    }

    public function detach(string $client, Contact $contact, Request $request, RepairContactOwnership $repair): JsonResponse
    {
        $validated = $request->validate([
            'dry_run' => ['sometimes', 'boolean'],
            'delete_if_orphan' => ['sometimes', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        return response()->json($repair->detachContact(
            $repair->resolveClient($client),
            $contact,
            (bool) ($validated['dry_run'] ?? false),
            (bool) ($validated['delete_if_orphan'] ?? false),
            $validated['reason'] ?? null,
            $request,
        ));
    }
}
