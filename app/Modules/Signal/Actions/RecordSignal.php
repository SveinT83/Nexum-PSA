<?php

namespace App\Modules\Signal\Actions;

use App\Modules\Signal\Models\Signal;

class RecordSignal
{
    public function __construct(private readonly ProcessSignalRules $rules)
    {
    }

    public function handle(array $payload, bool $processRules = true): Signal
    {
        $signal = Signal::query()->create([
            'source_domain' => $payload['source_domain'],
            'source_type' => $payload['source_type'] ?? null,
            'source_id' => $payload['source_id'] ?? null,
            'subject_type' => $payload['subject_type'] ?? null,
            'subject_id' => $payload['subject_id'] ?? null,
            'contact_id' => $payload['contact_id'] ?? null,
            'client_id' => $payload['client_id'] ?? null,
            'signal_type' => $payload['signal_type'],
            'severity' => $payload['severity'] ?? 'info',
            'confidence' => $payload['confidence'] ?? 100,
            'status' => $payload['status'] ?? 'new',
            'summary' => $payload['summary'] ?? null,
            'payload' => $payload['payload'] ?? [],
            'occurred_at' => $payload['occurred_at'] ?? now(),
        ]);

        if ($processRules) {
            $this->rules->handle($signal);
        }

        return $signal;
    }
}
