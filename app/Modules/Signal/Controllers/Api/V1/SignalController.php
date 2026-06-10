<?php

namespace App\Modules\Signal\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Clients\Client;
use App\Modules\Contact\Models\Contact;
use App\Modules\Signal\Actions\RecordSignal;
use App\Modules\Signal\Resources\Api\V1\SignalResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SignalController extends Controller
{
    public function store(Request $request, RecordSignal $signals): JsonResponse
    {
        $data = $request->validate([
            'source_domain' => ['required', 'string', 'max:80'],
            'source_type' => ['nullable', 'string', 'max:255'],
            'source_id' => ['nullable', 'integer'],
            'subject_type' => ['nullable', 'string', 'max:255'],
            'subject_id' => ['nullable', 'integer'],
            'contact_id' => ['nullable', Rule::exists('contacts', 'id')],
            'client_id' => ['nullable', Rule::exists('clients', 'id')],
            'signal_type' => ['required', 'string', 'max:100'],
            'severity' => ['nullable', Rule::in(['info', 'warning', 'error', 'critical'])],
            'confidence' => ['nullable', 'integer', 'min:0', 'max:100'],
            'status' => ['nullable', 'string', 'max:50'],
            'summary' => ['nullable', 'string', 'max:1000'],
            'payload' => ['nullable', 'array'],
            'occurred_at' => ['nullable', 'date'],
            'process_rules' => ['nullable', 'boolean'],
        ]);

        $subjectType = $data['subject_type'] ?? null;
        if ($subjectType === 'contact') {
            $data['subject_type'] = (new Contact())->getMorphClass();
        } elseif ($subjectType === 'client') {
            $data['subject_type'] = (new Client())->getMorphClass();
        }

        $signal = $signals->handle($data, processRules: $request->boolean('process_rules', true))
            ->loadCount('executions');

        return response()->json([
            'data' => (new SignalResource($signal))->resolve($request),
        ], 201);
    }
}
