<?php

namespace App\Modules\Storage\Actions;

use App\Models\Core\User;
use App\Modules\Storage\Models\PurchaseOrderImport;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TransitionPurchaseOrderImport
{
    private const TRANSITIONS = [
        PurchaseOrderImport::STATUS_PENDING => [
            PurchaseOrderImport::STATUS_PROCESSING,
            PurchaseOrderImport::STATUS_NEEDS_ATTENTION,
            PurchaseOrderImport::STATUS_REJECTED,
            PurchaseOrderImport::STATUS_CANCELLED,
        ],
        PurchaseOrderImport::STATUS_PROCESSING => [
            PurchaseOrderImport::STATUS_RETRY_SCHEDULED,
            PurchaseOrderImport::STATUS_NEEDS_ATTENTION,
            PurchaseOrderImport::STATUS_IMPORTED,
            PurchaseOrderImport::STATUS_DUPLICATE,
            PurchaseOrderImport::STATUS_REJECTED,
            PurchaseOrderImport::STATUS_FAILED,
        ],
        PurchaseOrderImport::STATUS_RETRY_SCHEDULED => [
            PurchaseOrderImport::STATUS_PROCESSING,
            PurchaseOrderImport::STATUS_FAILED,
            PurchaseOrderImport::STATUS_CANCELLED,
        ],
        PurchaseOrderImport::STATUS_NEEDS_ATTENTION => [
            PurchaseOrderImport::STATUS_PROCESSING,
            PurchaseOrderImport::STATUS_REJECTED,
            PurchaseOrderImport::STATUS_CANCELLED,
        ],
        PurchaseOrderImport::STATUS_FAILED => [
            PurchaseOrderImport::STATUS_PROCESSING,
            PurchaseOrderImport::STATUS_RETRY_SCHEDULED,
            PurchaseOrderImport::STATUS_CANCELLED,
        ],
    ];

    /** @param array<string, mixed> $context */
    public function handle(
        PurchaseOrderImport $import,
        string $status,
        string $stage,
        ?string $reasonCode = null,
        array $context = [],
        ?User $actor = null,
        string $serviceIdentity = 'storage.import',
    ): PurchaseOrderImport {
        if (! in_array($status, PurchaseOrderImport::statuses(), true)
            || ! in_array($stage, PurchaseOrderImport::stages(), true)) {
            throw ValidationException::withMessages(['status' => 'Unknown supplier-order import state.']);
        }

        return DB::transaction(function () use ($import, $status, $stage, $reasonCode, $context, $actor, $serviceIdentity): PurchaseOrderImport {
            $locked = PurchaseOrderImport::query()->lockForUpdate()->findOrFail($import->id);
            $allowed = self::TRANSITIONS[$locked->status] ?? [];
            if ($locked->status !== $status && ! in_array($status, $allowed, true)) {
                throw ValidationException::withMessages([
                    'status' => "Cannot transition supplier-order import from {$locked->status} to {$status}.",
                ]);
            }

            $locked->forceFill([
                'status' => $status,
                'stage' => $stage,
                'reason_code' => $reasonCode,
                'reason_context' => $this->safeContext($context),
                'last_actor_id' => $actor?->id,
                'processed_at' => in_array($status, [
                    PurchaseOrderImport::STATUS_IMPORTED,
                    PurchaseOrderImport::STATUS_DUPLICATE,
                    PurchaseOrderImport::STATUS_REJECTED,
                    PurchaseOrderImport::STATUS_CANCELLED,
                ], true) ? now() : $locked->processed_at,
            ])->save();

            $locked->attempts()->create([
                'attempt_number' => max(1, $locked->attempt_count),
                'stage' => $stage,
                'method' => $locked->extraction_method,
                'status' => $status,
                'reason_code' => $reasonCode,
                'metadata' => $this->safeContext($context),
                'service_identity' => $serviceIdentity,
                'actor_id' => $actor?->id,
                'started_at' => now(),
                'completed_at' => now(),
            ]);

            return $locked->fresh();
        });
    }

    private function safeContext(array $context): array
    {
        return collect($context)->except([
            'body', 'body_html', 'body_text', 'headers', 'raw', 'prompt', 'response', 'model_response',
        ])->take(50)->all();
    }
}
