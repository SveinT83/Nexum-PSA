<?php

namespace App\Modules\Storage\Actions;

use App\Models\Core\User;
use App\Modules\Storage\Models\PurchaseOrderAutomationPolicy;
use App\Modules\Storage\Models\PurchaseOrderImport;
use App\Modules\Storage\Models\PurchaseOrderImportRepair;
use App\Modules\Storage\Models\Warehouse;
use App\Modules\Storage\Support\StableJson;
use App\Modules\Storage\Support\SupplierOrderCanonicalValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ManuallyCorrectPurchaseOrderImport
{
    private const TERMINAL_STATUSES = [
        PurchaseOrderImport::STATUS_IMPORTED,
        PurchaseOrderImport::STATUS_DUPLICATE,
        PurchaseOrderImport::STATUS_REJECTED,
        PurchaseOrderImport::STATUS_CANCELLED,
    ];

    public function __construct(
        private readonly ResolveEffectivePurchaseOrderAutomationPolicy $resolveEffectivePolicy,
        private readonly SupplierOrderCanonicalValidator $canonicalValidator,
        private readonly SyncPurchaseOrderImportLines $syncLines,
    ) {}

    /**
     * Replace only the mutable import proposal and append an immutable manual-review repair.
     *
     * @param  array<string, mixed>  $correction
     */
    public function handle(
        PurchaseOrderImport $import,
        array $correction,
        User $actor,
    ): PurchaseOrderImport {
        $this->authorize($actor);

        return DB::transaction(function () use ($import, $correction, $actor): PurchaseOrderImport {
            $locked = PurchaseOrderImport::query()
                ->with(['policyRevision', 'profile', 'profileVersion'])
                ->lockForUpdate()
                ->findOrFail($import->id);
            $this->ensureMutable($locked);
            $this->assertSourceIntegrity($locked);

            $policy = $this->pinnedEffectivePolicy($locked);
            $document = $this->canonicalDocument($locked, $correction, $actor);
            if (! Warehouse::query()
                ->whereKey((int) ($document['destination_warehouse_id'] ?? 0))
                ->where('is_active', true)
                ->exists()) {
                throw ValidationException::withMessages([
                    'correction.destination_warehouse_id' => 'Select an active destination warehouse.',
                ]);
            }
            $validation = $this->canonicalValidator->validate($document, $policy);
            if (! $validation->valid()) {
                throw ValidationException::withMessages([
                    'correction.document' => collect($validation->errors)
                        ->take(30)
                        ->map(fn (array $error): string => ($error['code'] ?? 'validation_error')
                            .': '.($error['message'] ?? 'The corrected document is invalid.'))
                        ->values()
                        ->all(),
                ]);
            }

            $reason = trim((string) ($correction['audit_reason'] ?? ''));
            if (mb_strlen($reason) < 5 || mb_strlen($reason) > 1000) {
                throw ValidationException::withMessages([
                    'correction.audit_reason' => 'An audit reason between 5 and 1000 characters is required.',
                ]);
            }

            $sequence = (int) $locked->repairs()->lockForUpdate()->max('sequence') + 1;
            $repair = $locked->repairs()->create([
                'sequence' => $sequence,
                'ai_execution_uuid' => null,
                'status' => PurchaseOrderImportRepair::STATUS_READY_FOR_REPROCESS,
                'original_document_checksum' => $locked->normalized_document
                    ? StableJson::checksum($locked->normalized_document)
                    : null,
                'corrected_document' => $document,
                'corrected_document_checksum' => StableJson::checksum($document),
                'profile_candidate_version_id' => null,
                'validation_results' => $validation->toArray(),
                'decision_summary' => [
                    'method' => 'manual',
                    'reason' => $reason,
                    'source_fingerprint' => $locked->source_fingerprint,
                    'effective_policy_checksum' => $locked->effective_policy_checksum,
                    'before_document' => $this->auditDocument((array) $locked->normalized_document),
                    'ready_for_reprocess' => true,
                    'candidate_reproduction' => null,
                    'blocked_reason' => null,
                ],
                'actor_id' => $actor->id,
            ]);

            $locked->forceFill([
                'external_order_number' => $document['external_order_number'],
                'domain_identity_hash' => null,
                'normalized_document' => $document,
                'validation_results' => $validation->toArray(),
                'commercial_snapshot' => $document['totals'],
                'delivery_snapshot' => $document['delivery'],
                'extraction_method' => 'manual_review',
                'status' => PurchaseOrderImport::STATUS_NEEDS_ATTENTION,
                'stage' => PurchaseOrderImport::STAGE_VALIDATE,
                'reason_code' => 'manual_repair_ready_for_reprocess',
                'reason_context' => [
                    'repair_id' => $repair->id,
                    'method' => 'manual',
                    'source_fingerprint' => $locked->source_fingerprint,
                ],
                'next_retry_at' => null,
                'locked_at' => null,
                'last_actor_id' => $actor->id,
            ])->save();

            $this->syncLines->handle($locked, $document);

            return $locked->fresh([
                'vendor',
                'profile',
                'profileVersion',
                'policyRevision',
                'purchaseOrder',
                'lines.item',
                'repairs.actor',
            ]);
        });
    }

    private function authorize(User $actor): void
    {
        if (! $actor->isActive() || ! $actor->can('storage.purchase_import_resolve')) {
            throw ValidationException::withMessages([
                'correction' => 'Manual correction requires supplier-import resolution permission.',
            ]);
        }
    }

    private function ensureMutable(PurchaseOrderImport $import): void
    {
        if ($import->purchase_order_id !== null || in_array($import->status, self::TERMINAL_STATUSES, true)) {
            throw ValidationException::withMessages([
                'correction' => 'An import with Purchase Order or terminal history cannot be corrected.',
            ]);
        }
        if ($import->status === PurchaseOrderImport::STATUS_PROCESSING) {
            throw ValidationException::withMessages([
                'correction' => 'Wait for active import processing to finish before correcting the proposal.',
            ]);
        }
    }

    private function assertSourceIntegrity(PurchaseOrderImport $import): void
    {
        $snapshot = $import->safe_source_snapshot;
        if (! is_array($snapshot)
            || ! is_string($import->source_fingerprint)
            || ! hash_equals($import->source_fingerprint, StableJson::checksum($snapshot))) {
            throw ValidationException::withMessages([
                'correction' => 'The immutable supplier source fingerprint is inconsistent.',
            ]);
        }
    }

    private function pinnedEffectivePolicy(PurchaseOrderImport $import): PurchaseOrderAutomationPolicy
    {
        if (! is_array($import->effective_policy_snapshot) || ! $import->policyRevision) {
            throw ValidationException::withMessages([
                'correction' => 'A pinned effective policy is required before manual correction.',
            ]);
        }

        $global = $this->resolveEffectivePolicy->fromPinnedRevision($import->policyRevision);

        return $this->resolveEffectivePolicy->handle(
            $import,
            $global,
            $import->profile,
            $import->profileVersion,
        );
    }

    /**
     * Build the one canonical manual-review proposal without altering the immutable source.
     *
     * @param  array<string, mixed>  $correction
     * @return array<string, mixed>
     */
    private function canonicalDocument(
        PurchaseOrderImport $import,
        array $correction,
        User $actor,
    ): array {
        $currency = strtoupper(trim((string) ($correction['currency'] ?? '')));
        $lines = collect($correction['lines'] ?? [])
            ->take(500)
            ->values()
            ->map(function (mixed $line, int $index) use ($import, $actor, $currency): array {
                $line = is_array($line) ? $line : [];
                $position = $index + 1;
                $evidence = $this->manualEvidence($import, $actor, 'line.'.$position);

                return [
                    'source_row_identifier' => 'manual-'.$position,
                    'supplier_sku' => $this->text($line['supplier_sku'] ?? null, 255),
                    'description' => $this->text($line['description'] ?? null, 2000),
                    'quantity' => (int) ($line['quantity'] ?? 0),
                    'unit_price' => $this->nullableDecimal($line['unit_price'] ?? null),
                    'line_total' => $this->decimal($line['line_total'] ?? 0),
                    'tax_rate' => $this->nullableDecimal($line['tax_rate'] ?? null),
                    'currency' => $currency,
                    'evidence' => [
                        'supplier_sku' => $evidence,
                        'description' => $evidence,
                        'quantity' => $evidence,
                        'unit_price' => $evidence,
                        'line_total' => $evidence,
                        'tax_rate' => $evidence,
                    ],
                ];
            })
            ->all();
        $goodsSubtotal = collect($lines)->sum(
            fn (array $line): float => (float) ($line['line_total'] ?? 0),
        );
        $headerEvidence = $this->manualEvidence($import, $actor, 'header');

        return [
            'schema_version' => 'storage.supplier_order.v1',
            'document_type' => 'supplier_order_confirmation',
            'supplier' => [
                'name' => $this->text($correction['supplier_name'] ?? null, 500),
            ],
            'external_order_number' => $this->text($correction['external_order_number'] ?? null, 255),
            'ordered_at' => trim((string) ($correction['ordered_at'] ?? '')),
            'ordered_at_provenance' => 'explicit_manual_review',
            'currency' => $currency,
            'buyer_reference' => null,
            'supplier_po_reference' => null,
            'destination_warehouse_id' => (int) ($correction['destination_warehouse_id'] ?? 0),
            'delivery' => [
                'method' => null,
                'address' => null,
                'expected_at' => null,
            ],
            'lines' => $lines,
            'totals' => [
                'goods_subtotal' => $this->decimal($goodsSubtotal),
                'freight' => $this->decimal(data_get($correction, 'totals.freight', 0)),
                'discount' => $this->decimal(data_get($correction, 'totals.discount', 0)),
                'other_charges' => $this->decimal(data_get($correction, 'totals.other_charges', 0)),
                'tax_total' => null,
                'total_ex_tax' => $this->decimal(data_get($correction, 'totals.total_ex_tax', 0)),
                'total_inc_tax' => null,
            ],
            'evidence' => [
                'supplier' => ['name' => $headerEvidence],
                'external_order_number' => $headerEvidence,
            ],
            'unknown_fields' => [],
            'manual_review' => [
                'method' => 'manual',
                'reviewed_by' => $actor->id,
                'reviewed_at' => now()->utc()->toIso8601String(),
                'source_fingerprint' => $import->source_fingerprint,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function auditDocument(array $document): array
    {
        return [
            'supplier' => [
                'name' => Str::limit((string) data_get($document, 'supplier.name'), 255, ''),
            ],
            'external_order_number' => Str::limit((string) data_get($document, 'external_order_number'), 255, ''),
            'ordered_at' => data_get($document, 'ordered_at'),
            'currency' => data_get($document, 'currency'),
            'delivery' => [
                'method' => Str::limit((string) data_get($document, 'delivery.method'), 255, ''),
                'expected_at' => data_get($document, 'delivery.expected_at'),
            ],
            'lines' => collect($document['lines'] ?? [])->take(500)->map(fn (mixed $line): array => [
                'supplier_sku' => is_array($line) ? ($line['supplier_sku'] ?? null) : null,
                'description' => is_array($line)
                    ? Str::limit((string) ($line['description'] ?? ''), 500, '')
                    : null,
                'quantity' => is_array($line) ? ($line['quantity'] ?? null) : null,
                'unit_price' => is_array($line) ? ($line['unit_price'] ?? null) : null,
                'line_total' => is_array($line) ? ($line['line_total'] ?? null) : null,
                'tax_rate' => is_array($line) ? ($line['tax_rate'] ?? null) : null,
                'currency' => is_array($line) ? ($line['currency'] ?? null) : null,
            ])->values()->all(),
            'totals' => collect((array) ($document['totals'] ?? []))->only([
                'goods_subtotal', 'freight', 'discount', 'other_charges',
                'tax_total', 'total_ex_tax', 'total_inc_tax',
            ])->all(),
        ];
    }

    /** @return array<string, int|string|null> */
    private function manualEvidence(PurchaseOrderImport $import, User $actor, string $field): array
    {
        return [
            'block_id' => 'manual-review-'.$import->id.'-'.Str::slug($field),
            'quote' => 'Confirmed by authorized manual review.',
            'provenance' => 'manual_review',
            'source_fingerprint' => $import->source_fingerprint,
            'reviewed_by' => $actor->id,
        ];
    }

    private function text(mixed $value, int $limit): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : Str::limit($value, $limit, '');
    }

    private function decimal(mixed $value): string
    {
        if (! is_numeric($value) || ! is_finite((float) $value)) {
            return '';
        }
        $formatted = rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');

        return in_array($formatted, ['', '-0'], true) ? '0' : $formatted;
    }

    private function nullableDecimal(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return $this->decimal($value);
    }
}
