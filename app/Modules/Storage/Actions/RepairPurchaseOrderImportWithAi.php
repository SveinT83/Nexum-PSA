<?php

namespace App\Modules\Storage\Actions;

use App\Models\Core\User;
use App\Modules\Integration\Contracts\RunsStructuredAiWorkloads;
use App\Modules\Integration\Models\AiModelUsageEvent;
use App\Modules\Integration\Support\AiExecutionContext;
use App\Modules\Integration\Support\StructuredAiWorkloadRequest;
use App\Modules\Integration\Support\StructuredAiWorkloadResult;
use App\Modules\Storage\Models\Item;
use App\Modules\Storage\Models\PurchaseOrder;
use App\Modules\Storage\Models\PurchaseOrderAutomationPolicy;
use App\Modules\Storage\Models\PurchaseOrderImport;
use App\Modules\Storage\Models\PurchaseOrderImportLine;
use App\Modules\Storage\Models\PurchaseOrderImportProfile;
use App\Modules\Storage\Models\PurchaseOrderImportProfileVersion;
use App\Modules\Storage\Models\PurchaseOrderImportRepair;
use App\Modules\Storage\Support\StableJson;
use App\Modules\Storage\Support\SupplierOrderAiInputMinimizer;
use App\Modules\Storage\Support\SupplierOrderCanonicalValidator;
use App\Modules\Storage\Support\SupplierOrderDocumentNormalizer;
use App\Modules\Storage\Support\SupplierOrderIdentity;
use App\Modules\Storage\Support\SupplierOrderProfileCandidateReproducer;
use App\Modules\Storage\Support\SupplierOrderProfileCandidateReproductionResult;
use App\Modules\Storage\Support\SupplierOrderProfileDefinitionValidator;
use App\Modules\Storage\Support\SupplierOrderSourceIntegrity;
use App\Modules\Storage\Support\SupplierSkuIdentity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;

class RepairPurchaseOrderImportWithAi
{
    public function __construct(
        private readonly RunsStructuredAiWorkloads $structuredAi,
        private readonly ResolveEffectivePurchaseOrderAutomationPolicy $effectivePolicy,
        private readonly SupplierOrderDocumentNormalizer $normalizer,
        private readonly SupplierOrderAiInputMinimizer $inputMinimizer,
        private readonly ExtractSupplierOrderWithAi $extractionSchema,
        private readonly SupplierOrderCanonicalValidator $canonicalValidator,
        private readonly SupplierOrderProfileDefinitionValidator $definitionValidator,
        private readonly CreateSupplierOrderProfileVersion $createVersion,
        private readonly ReplaySupplierOrderProfileFixtures $replayFixtures,
        private readonly ValidateSupplierOrderProfileVersion $validateVersion,
        private readonly ActivateSupplierOrderProfileVersion $activateVersion,
        private readonly SupplierOrderProfileCandidateReproducer $candidateReproducer,
        private readonly SupplierOrderSourceIntegrity $sourceIntegrity,
        private readonly SyncPurchaseOrderImportLines $syncLines,
        private readonly UpdatePurchaseOrder $updatePurchaseOrder,
    ) {}

    public function handle(PurchaseOrderImport $import, User $actor): PurchaseOrderImport
    {
        $this->authorize($actor);
        $preflight = DB::transaction(function () use ($import): array {
            [$locked, $purchaseOrder] = $this->lockRepairState($import->id);
            $this->assertRepairableState($locked);
            $this->validateSourceIntegrity($locked);
            $policy = $this->policyForImport($locked);

            // Resolving the effective policy may pin it, so reload before issuing the lease token.
            [$locked, $purchaseOrder] = $this->lockRepairState($import->id);

            return [
                'import' => $locked,
                'purchase_order' => $purchaseOrder,
                'policy' => $policy,
                'state_token' => $this->stateToken($locked, $purchaseOrder),
            ];
        });

        /** @var PurchaseOrderImport $preflightImport */
        $preflightImport = $preflight['import'];
        /** @var PurchaseOrderAutomationPolicy $policy */
        $policy = $preflight['policy'];
        $policy->loadMissing(['aiWorkloadProfile', 'aiConsensusWorkloadProfile']);
        if ($policy->ai_mode === 'off' || blank($policy->aiWorkloadProfile?->slug)) {
            throw ValidationException::withMessages(['ai' => 'No governed supplier-order AI workload is enabled.']);
        }

        $normalized = $this->inputMinimizer->minimize(
            $this->normalizer->normalize((array) $preflightImport->safe_source_snapshot)->toArray(),
        );
        $input = $this->boundedInput($preflightImport, $normalized);
        $budget = $this->remainingBudget($preflightImport, $policy);
        if ($budget['reason_code'] !== null) {
            $this->failAiGuard(
                $preflightImport->id,
                (string) $preflight['state_token'],
                (string) $budget['reason_code'],
                null,
                ['ai_budget' => $budget],
            );
        }
        $result = $this->executeRepairWorkload(
            import: $preflightImport,
            actor: $actor,
            policy: $policy,
            workloadSlug: (string) $policy->aiWorkloadProfile->slug,
            operation: 'repair_supplier_order_import',
            input: $input,
            remainingCost: $budget['remaining'],
        );
        if (! $result->successful()) {
            $this->recordAiFailure($preflightImport->id, (string) $preflight['state_token'], $result);

            throw ValidationException::withMessages([
                'ai' => 'AI repair was '.$result->status->value.': '.($result->reasonCode ?: 'unknown reason'),
            ]);
        }

        $responseDocument = $result->data['corrected_document'] ?? [];
        $corrected = $this->extractionSchema->canonicalDocument(
            is_array($responseDocument) ? $responseDocument : [],
        );
        $corrected['destination_warehouse_id'] ??= data_get(
            $preflightImport->normalized_document,
            'destination_warehouse_id',
            $policy->default_warehouse_id,
        );
        $consensusAudit = null;
        if (($policy->ai_consensus_mode ?? 'off') === 'required') {
            $consensusSlug = $policy->aiConsensusWorkloadProfile?->slug;
            if (blank($consensusSlug) || $consensusSlug === $policy->aiWorkloadProfile->slug) {
                $this->failAiGuard(
                    $preflightImport->id,
                    (string) $preflight['state_token'],
                    'ai_consensus_not_configured',
                    $result->metadata->executionId,
                );
            }
            $remaining = $this->remainingBudget($preflightImport, $policy);
            if ($remaining['reason_code'] !== null) {
                $this->failAiGuard(
                    $preflightImport->id,
                    (string) $preflight['state_token'],
                    (string) $remaining['reason_code'],
                    $result->metadata->executionId,
                    ['ai_budget_after_primary' => $remaining],
                );
            }
            $secondary = $this->executeRepairWorkload(
                import: $preflightImport,
                actor: $actor,
                policy: $policy,
                workloadSlug: (string) $consensusSlug,
                operation: 'verify_supplier_order_repair',
                input: $input,
                remainingCost: $remaining['remaining'],
            );
            if (! $secondary->successful()) {
                $this->failAiGuard(
                    $preflightImport->id,
                    (string) $preflight['state_token'],
                    'ai_consensus_'.$secondary->status->value,
                    $result->metadata->executionId,
                    ['consensus' => $this->resultAuditMetadata($secondary)],
                );
            }
            $secondaryResponse = $secondary->data['corrected_document'] ?? [];
            $secondaryCorrected = $this->extractionSchema->canonicalDocument(
                is_array($secondaryResponse) ? $secondaryResponse : [],
            );
            $secondaryCorrected['destination_warehouse_id'] ??= $corrected['destination_warehouse_id'];
            $secondaryValidation = $this->canonicalValidator->validateAiDocument(
                $secondaryCorrected,
                $policy,
                (array) $preflightImport->safe_source_snapshot,
                (string) $preflightImport->source_fingerprint,
            );
            if (! $secondaryValidation->valid()) {
                $this->failAiGuard(
                    $preflightImport->id,
                    (string) $preflight['state_token'],
                    'ai_consensus_invalid',
                    $result->metadata->executionId,
                    ['consensus_error_codes' => collect($secondaryValidation->errors)->pluck('code')->unique()->values()->all()],
                );
            }
            $primaryChecksum = StableJson::checksum($this->consensusProjection($corrected));
            $secondaryChecksum = StableJson::checksum($this->consensusProjection($secondaryCorrected));
            if (! hash_equals($primaryChecksum, $secondaryChecksum)) {
                $this->failAiGuard(
                    $preflightImport->id,
                    (string) $preflight['state_token'],
                    'ai_consensus_disagreement',
                    $result->metadata->executionId,
                    ['primary_checksum' => $primaryChecksum, 'secondary_checksum' => $secondaryChecksum],
                );
            }
            $consensusAudit = $this->resultAuditMetadata($secondary) + [
                'status' => 'agreed',
                'primary_checksum' => $primaryChecksum,
                'secondary_checksum' => $secondaryChecksum,
            ];
        }

        return DB::transaction(function () use (
            $preflightImport,
            $preflight,
            $result,
            $corrected,
            $actor,
            $budget,
            $consensusAudit,
        ): PurchaseOrderImport {
            [$locked, $purchaseOrder] = $this->lockRepairState($preflightImport->id);
            $this->assertRepairableState($locked);
            $this->validateSourceIntegrity($locked);
            $policy = $this->policyForImport($locked);
            $validation = $this->canonicalValidator->validateAiDocument(
                $corrected,
                $policy,
                (array) $locked->safe_source_snapshot,
                (string) $locked->source_fingerprint,
            );
            if (! $validation->valid()) {
                throw ValidationException::withMessages([
                    'ai' => collect($validation->errors)->take(30)->map(
                        fn (array $error): string => ($error['code'] ?? 'ai_validation_failed')
                            .': '.($error['path'] ?? 'document'),
                    )->values()->all(),
                ]);
            }

            $decision = [
                'diagnosis' => $result->data['diagnosis'] ?? null,
                'change_summary' => $result->data['change_summary'] ?? [],
                'confidence' => $result->data['confidence'] ?? null,
                'before_document' => $this->auditDocument((array) $locked->normalized_document),
                'ai_budget' => $budget,
                'primary_execution' => $this->resultAuditMetadata($result),
                'consensus' => $consensusAudit,
            ];
            if (! hash_equals((string) $preflight['state_token'], $this->stateToken($locked, $purchaseOrder))) {
                $this->storeRepairLocked(
                    import: $locked,
                    actor: $actor,
                    executionId: $result->metadata->executionId,
                    corrected: $corrected,
                    candidateVersion: null,
                    validation: $validation->toArray(),
                    status: PurchaseOrderImportRepair::STATUS_PROPOSAL_ONLY_STATE_CHANGED,
                    decision: $decision + ['blocked_reason' => 'repair_state_changed_during_ai'],
                );

                return $this->freshResult($locked);
            }

            [$candidateVersion, $reproduction] = $this->profileCandidate(
                $locked,
                $actor,
                $result->data['profile_candidate_json'] ?? null,
                $policy,
                $corrected,
            );
            $decision['profile_candidate_status'] = $candidateVersion?->status;
            $decision['candidate_reproduction'] = $reproduction?->toArray();

            if ($purchaseOrder !== null) {
                $blockedReason = $this->preHistoryBlockReason($locked, $purchaseOrder, $corrected, $actor);
                if ($blockedReason !== null) {
                    $this->storeRepairLocked(
                        import: $locked,
                        actor: $actor,
                        executionId: $result->metadata->executionId,
                        corrected: $corrected,
                        candidateVersion: $candidateVersion,
                        validation: $validation->toArray(),
                        status: PurchaseOrderImportRepair::STATUS_PROPOSAL_ONLY_LOCKED_PURCHASE_ORDER,
                        decision: $decision + ['blocked_reason' => $blockedReason],
                    );

                    return $this->freshResult($locked);
                }

                $repair = $this->storeRepairLocked(
                    import: $locked,
                    actor: $actor,
                    executionId: $result->metadata->executionId,
                    corrected: $corrected,
                    candidateVersion: $candidateVersion,
                    validation: $validation->toArray(),
                    status: PurchaseOrderImportRepair::STATUS_APPLIED_PRE_HISTORY_PURCHASE_ORDER,
                    decision: $decision + ['blocked_reason' => null],
                );
                $this->applyPreHistoryPurchaseOrderCorrection(
                    $locked,
                    $purchaseOrder,
                    $corrected,
                    $validation->toArray(),
                    $candidateVersion,
                    $repair,
                    $result->metadata->executionId,
                    $actor,
                );

                return $this->freshResult($locked);
            }

            $repair = $this->storeRepairLocked(
                import: $locked,
                actor: $actor,
                executionId: $result->metadata->executionId,
                corrected: $corrected,
                candidateVersion: $candidateVersion,
                validation: $validation->toArray(),
                status: PurchaseOrderImportRepair::STATUS_READY_FOR_REPROCESS,
                decision: $decision + ['blocked_reason' => null],
            );
            $locked->forceFill([
                'normalized_document' => $corrected,
                'external_order_number' => SupplierOrderIdentity::storedReference($corrected['external_order_number'] ?? null),
                'domain_identity_hash' => null,
                'commercial_snapshot' => $corrected['totals'] ?? [],
                'delivery_snapshot' => $corrected['delivery'] ?? [],
                'validation_results' => $validation->toArray(),
                'status' => PurchaseOrderImport::STATUS_NEEDS_ATTENTION,
                'stage' => PurchaseOrderImport::STAGE_VALIDATE,
                'reason_code' => 'ai_repair_ready_for_reprocess',
                'reason_context' => [
                    'repair_id' => $repair->id,
                    'profile_candidate_version_id' => $candidateVersion?->id,
                ],
                'extraction_method' => 'ai',
                'ai_profile_candidate_version_id' => $candidateVersion?->id,
                'ai_execution_uuid' => $result->metadata->executionId,
                'last_actor_id' => $actor->id,
            ])->save();
            $this->syncLines->handle($locked, $corrected);

            return $this->freshResult($locked);
        });
    }

    /** @param array<string, mixed> $input */
    private function executeRepairWorkload(
        PurchaseOrderImport $import,
        User $actor,
        PurchaseOrderAutomationPolicy $policy,
        string $workloadSlug,
        string $operation,
        array $input,
        ?string $remainingCost,
    ): StructuredAiWorkloadResult {
        $executionId = (string) Str::uuid();

        return $this->structuredAi->execute(new StructuredAiWorkloadRequest(
            workloadSlug: $workloadSlug,
            requestSchemaVersion: 'storage.supplier_order_repair_request.v1',
            responseSchemaVersion: 'storage.supplier_order_repair.v1',
            operation: $operation,
            input: $input,
            allowedInputFields: $this->allowedInputFields(),
            responseDataSchema: $this->responseSchema(),
            executionContext: new AiExecutionContext(
                executionId: $executionId,
                featureKey: 'storage.supplier_order_import',
                operationKey: $operation,
                domain: 'storage',
                billingClassification: 'internal',
                actorUserId: $actor->id,
                subjectType: 'storage_supplier_order_import',
                subjectId: (string) $import->id,
                correlationId: 'supplier-order-repair:'.$import->id.':'.($import->repairs->count() + 1).':'.$operation,
            ),
            configuredIdentifiers: $this->identifiersToRedact((array) $import->safe_source_snapshot),
            timeoutSeconds: max(1, min(165, (int) $policy->ai_timeout_seconds)),
            maxOutputTokens: max(1, min(12000, (int) $policy->ai_max_output_tokens)),
            reasoningEffort: $this->reasoningEffort($policy),
            maxProviderReportedCost: $remainingCost,
            costCurrency: $remainingCost === null ? null : $policy->ai_cost_currency,
        ));
    }

    /**
     * Enforce one aggregate provider-reported budget for extraction, repair, and consensus calls.
     * Unknown cost or a currency mismatch is deliberately non-recoverable for automation.
     *
     * @return array{limit: string|null, currency: string|null, spent: string|null, remaining: string|null, reason_code: string|null}
     */
    private function remainingBudget(
        PurchaseOrderImport $import,
        PurchaseOrderAutomationPolicy $policy,
    ): array {
        if ($policy->ai_max_cost_per_import === null) {
            return [
                'limit' => null,
                'currency' => null,
                'spent' => null,
                'remaining' => null,
                'reason_code' => null,
            ];
        }
        $currency = strtoupper(trim((string) $policy->ai_cost_currency));
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            return [
                'limit' => (string) $policy->ai_max_cost_per_import,
                'currency' => null,
                'spent' => null,
                'remaining' => null,
                'reason_code' => 'ai_cost_currency_not_configured',
            ];
        }

        $events = AiModelUsageEvent::query()
            ->where('feature_key', 'storage.supplier_order_import')
            ->where('subject_type', 'storage_supplier_order_import')
            ->where('subject_id', (string) $import->id)
            ->get(['provider_reported_cost', 'cost_currency']);
        $spent = 0.0;
        foreach ($events as $event) {
            if ($event->provider_reported_cost === null) {
                return [
                    'limit' => (string) $policy->ai_max_cost_per_import,
                    'currency' => $currency,
                    'spent' => null,
                    'remaining' => null,
                    'reason_code' => 'ai_cost_history_unverifiable',
                ];
            }
            if ($event->cost_currency !== $currency) {
                return [
                    'limit' => (string) $policy->ai_max_cost_per_import,
                    'currency' => $currency,
                    'spent' => null,
                    'remaining' => null,
                    'reason_code' => 'ai_cost_history_currency_mismatch',
                ];
            }
            $spent += (float) $event->provider_reported_cost;
        }
        $remaining = (float) $policy->ai_max_cost_per_import - $spent;
        $facts = [
            'limit' => (string) $policy->ai_max_cost_per_import,
            'currency' => $currency,
            'spent' => $this->decimal($spent),
            'remaining' => $this->decimal(max(0, $remaining)),
            'reason_code' => null,
        ];
        if ($remaining <= 0) {
            $facts['reason_code'] = 'ai_cost_limit_exhausted';
        }

        return $facts;
    }

    private function decimal(float $value): string
    {
        return rtrim(rtrim(number_format($value, 12, '.', ''), '0'), '.') ?: '0';
    }

    /** @param array<string, mixed> $context */
    private function failAiGuard(
        int $importId,
        string $preflightStateToken,
        string $reasonCode,
        ?string $executionId,
        array $context = [],
    ): never {
        $this->recordGuardFailure($importId, $preflightStateToken, $reasonCode, $executionId, $context);

        throw ValidationException::withMessages(['ai' => $reasonCode]);
    }

    /** @param array<string, mixed> $context */
    private function recordGuardFailure(
        int $importId,
        string $preflightStateToken,
        string $reasonCode,
        ?string $executionId,
        array $context,
    ): void {
        DB::transaction(function () use ($importId, $preflightStateToken, $reasonCode, $executionId, $context): void {
            [$locked, $purchaseOrder] = $this->lockRepairState($importId);
            $this->validateSourceIntegrity($locked);
            if (! hash_equals($preflightStateToken, $this->stateToken($locked, $purchaseOrder))) {
                return;
            }
            if (! in_array($locked->status, [
                PurchaseOrderImport::STATUS_PENDING,
                PurchaseOrderImport::STATUS_RETRY_SCHEDULED,
                PurchaseOrderImport::STATUS_NEEDS_ATTENTION,
                PurchaseOrderImport::STATUS_FAILED,
            ], true)) {
                return;
            }

            $values = [
                'reason_code' => $reasonCode,
                'reason_context' => $context + ['ai_execution_id' => $executionId],
            ];
            if ($executionId !== null) {
                $values['ai_execution_uuid'] = $executionId;
            }
            $locked->forceFill($values)->save();
        });
    }

    /** @return array<string, mixed> */
    private function resultAuditMetadata(StructuredAiWorkloadResult $result): array
    {
        return array_filter([
            'execution_id' => $result->metadata->executionId,
            'workload_id' => $result->metadata->workloadId,
            'workload_slug' => $result->metadata->workloadSlug,
            'provider_id' => $result->metadata->providerId,
            'agent_id' => $result->metadata->agentId,
            'access_event_id' => $result->metadata->accessEventId,
            'provider_reported_cost' => $result->metadata->providerReportedCost,
            'cost_currency' => $result->metadata->costCurrency,
        ], fn (mixed $value): bool => $value !== null);
    }

    /** @return array<string, mixed> */
    private function consensusProjection(array $document): array
    {
        return [
            'supplier' => $document['supplier'] ?? null,
            'external_order_number' => $document['external_order_number'] ?? null,
            'ordered_at' => $document['ordered_at'] ?? null,
            'ordered_at_provenance' => $document['ordered_at_provenance'] ?? null,
            'currency' => $document['currency'] ?? null,
            'buyer_reference' => $document['buyer_reference'] ?? null,
            'supplier_po_reference' => $document['supplier_po_reference'] ?? null,
            'delivery' => $document['delivery'] ?? null,
            'lines' => collect($document['lines'] ?? [])->map(function (mixed $line): array {
                $line = is_array($line) ? $line : [];
                unset($line['evidence']);

                return $line;
            })->all(),
            'totals' => $document['totals'] ?? null,
        ];
    }

    private function authorize(User $actor): void
    {
        if (! $actor->isActive()
            || ! $actor->can('storage.purchase_import_execute')
            || ! $actor->can('storage.purchase_import_profile_manage')) {
            throw ValidationException::withMessages([
                'ai' => 'AI repair requires import-execution and profile-management permission.',
            ]);
        }
    }

    private function assertRepairableState(PurchaseOrderImport $import): void
    {
        if ($import->status === PurchaseOrderImport::STATUS_PROCESSING) {
            throw ValidationException::withMessages([
                'repair_state' => 'The import is currently processing and cannot accept an AI repair.',
            ]);
        }
        if (! in_array($import->status, [
            PurchaseOrderImport::STATUS_PENDING,
            PurchaseOrderImport::STATUS_RETRY_SCHEDULED,
            PurchaseOrderImport::STATUS_NEEDS_ATTENTION,
            PurchaseOrderImport::STATUS_FAILED,
            PurchaseOrderImport::STATUS_IMPORTED,
        ], true)) {
            throw ValidationException::withMessages([
                'repair_state' => 'The import is terminal and cannot accept an AI repair.',
            ]);
        }
        if ($import->status === PurchaseOrderImport::STATUS_IMPORTED && ! $import->purchase_order_id) {
            throw ValidationException::withMessages([
                'repair_state' => 'An imported record without its purchase order cannot be repaired automatically.',
            ]);
        }
    }

    private function validateSourceIntegrity(PurchaseOrderImport $import): void
    {
        $this->sourceIntegrity->validateOrFail(
            (array) $import->safe_source_snapshot,
            (string) $import->source_fingerprint,
            (array) $import->trusted_auth_snapshot,
        );
    }

    private function policyForImport(PurchaseOrderImport $import): PurchaseOrderAutomationPolicy
    {
        $revision = $import->policyRevision;
        if ($revision === null || ! is_array($revision->snapshot)) {
            throw ValidationException::withMessages([
                'ai' => 'The supplier-order import has no readable pinned policy revision.',
            ]);
        }

        $globalPolicy = new PurchaseOrderAutomationPolicy;
        $globalPolicy->forceFill($revision->snapshot + ['id' => $revision->policy_id]);
        $globalPolicy->exists = true;

        return $this->effectivePolicy->handle(
            $import,
            $globalPolicy,
            $import->profile,
            $import->profileVersion,
        );
    }

    /**
     * Lock every state element used to determine whether an AI result may mutate the import or order.
     *
     * @return array{0: PurchaseOrderImport, 1: PurchaseOrder|null}
     */
    private function lockRepairState(int $importId): array
    {
        $import = PurchaseOrderImport::query()->lockForUpdate()->findOrFail($importId);
        $import->load(['profile', 'profileVersion', 'policyRevision', 'vendor']);
        $importLines = $import->lines()->reorder('position')->lockForUpdate()->get();
        $repairs = $import->repairs()->reorder('sequence')->lockForUpdate()->get();

        $purchaseOrder = $import->purchase_order_id
            ? PurchaseOrder::query()->lockForUpdate()->find($import->purchase_order_id)
            : null;
        $purchaseOrderLines = $purchaseOrder
            ? $purchaseOrder->lines()->orderBy('id')->lockForUpdate()->get()
            : new EloquentCollection;
        $itemIds = $importLines->pluck('item_id')
            ->merge($purchaseOrderLines->pluck('item_id'))
            ->filter()
            ->unique()
            ->values();
        $items = $itemIds->isEmpty()
            ? collect()
            : Item::withTrashed()->whereKey($itemIds)->lockForUpdate()->get()->keyBy('id');
        foreach ($importLines as $line) {
            $line->setRelation('item', $items->get($line->item_id));
        }
        foreach ($purchaseOrderLines as $line) {
            $line->setRelation('item', $items->get($line->item_id));
        }
        $import->setRelation('lines', $importLines);
        $import->setRelation('repairs', $repairs);

        if ($purchaseOrder !== null) {
            $purchaseOrder->load(['vendor', 'deliverToWarehouse']);
            $purchaseOrder->setRelation('lines', $purchaseOrderLines);
            $purchaseOrder->setRelation(
                'shipments',
                $purchaseOrder->shipments()->orderBy('id')->lockForUpdate()->get(),
            );
            $purchaseOrder->setRelation(
                'receipts',
                $purchaseOrder->receipts()->orderBy('id')->lockForUpdate()->get(),
            );
        }
        $import->setRelation('purchaseOrder', $purchaseOrder);

        return [$import, $purchaseOrder];
    }

    private function stateToken(PurchaseOrderImport $import, ?PurchaseOrder $purchaseOrder): string
    {
        return StableJson::checksum([
            'import' => [
                'id' => $import->id,
                'status' => $import->status,
                'stage' => $import->stage,
                'decision' => $import->decision,
                'reason_code' => $import->reason_code,
                'purchase_order_id' => $import->purchase_order_id,
                'profile_id' => $import->profile_id,
                'profile_version_id' => $import->profile_version_id,
                'normalized_document_checksum' => $import->normalized_document
                    ? StableJson::checksum((array) $import->normalized_document)
                    : null,
                'effective_policy_checksum' => $import->effective_policy_checksum,
                'updated_at' => $import->getRawOriginal('updated_at'),
            ],
            'import_lines' => $import->lines->map(fn (mixed $line): array => [
                'id' => $line->id,
                'position' => $line->position,
                'supplier_sku' => $line->supplier_sku,
                'quantity' => $line->quantity,
                'unit_price' => $line->unit_price,
                'line_total' => $line->line_total,
                'item_id' => $line->item_id,
                'mapping_status' => $line->mapping_status,
                'resolution_method' => $line->resolution_method,
                'updated_at' => $line->getRawOriginal('updated_at'),
                'item_state' => $line->item ? [
                    'status' => $line->item->status,
                    'warehouse_id' => $line->item->warehouse_id,
                    'can_be_ordered' => $line->item->can_be_ordered,
                    'updated_at' => $line->item->getRawOriginal('updated_at'),
                ] : null,
            ])->all(),
            'repairs' => $import->repairs->map(fn (PurchaseOrderImportRepair $repair): array => [
                'id' => $repair->id,
                'sequence' => $repair->sequence,
                'status' => $repair->status,
                'checksum' => $repair->corrected_document_checksum,
            ])->all(),
            'purchase_order' => $purchaseOrder ? [
                'id' => $purchaseOrder->id,
                'status' => $purchaseOrder->status,
                'vendor_id' => $purchaseOrder->vendor_id,
                'warehouse_id' => $purchaseOrder->deliver_to_warehouse_id,
                'vendor_ref' => $purchaseOrder->vendor_ref,
                'currency' => $purchaseOrder->currency,
                'ordered_at' => optional($purchaseOrder->ordered_at)->toDateString(),
                'expected_at' => optional($purchaseOrder->expected_at)->toDateString(),
                'cancelled_at' => optional($purchaseOrder->cancelled_at)->toIso8601String(),
                'closed_at' => optional($purchaseOrder->closed_at)->toIso8601String(),
                'metadata_checksum' => StableJson::checksum((array) $purchaseOrder->metadata),
                'updated_at' => $purchaseOrder->getRawOriginal('updated_at'),
                'lines' => $purchaseOrder->lines->map(fn (mixed $line): array => [
                    'id' => $line->id,
                    'item_id' => $line->item_id,
                    'supplier_sku' => $line->supplier_sku_snapshot,
                    'qty_ordered' => $line->qty_ordered,
                    'qty_received' => $line->qty_received,
                    'qty_cancelled' => $line->qty_cancelled,
                    'unit_cost' => $line->unit_cost,
                    'tax_rate' => $line->tax_rate,
                    'currency' => $line->currency,
                    'cancelled_at' => optional($line->cancelled_at)->toIso8601String(),
                    'cancellation_reason' => $line->cancellation_reason,
                    'metadata_checksum' => StableJson::checksum((array) $line->metadata),
                    'updated_at' => $line->getRawOriginal('updated_at'),
                ])->all(),
                'shipments' => $purchaseOrder->shipments->map(fn (mixed $shipment): array => [
                    'id' => $shipment->id,
                    'status' => $shipment->status,
                    'updated_at' => $shipment->getRawOriginal('updated_at'),
                ])->all(),
                'receipts' => $purchaseOrder->receipts->map(fn (mixed $receipt): array => [
                    'id' => $receipt->id,
                    'status' => $receipt->status,
                    'updated_at' => $receipt->getRawOriginal('updated_at'),
                ])->all(),
            ] : null,
        ]);
    }

    private function recordAiFailure(
        int $importId,
        string $preflightStateToken,
        StructuredAiWorkloadResult $result,
    ): void {
        DB::transaction(function () use ($importId, $preflightStateToken, $result): void {
            [$locked, $purchaseOrder] = $this->lockRepairState($importId);
            $this->validateSourceIntegrity($locked);
            if (! hash_equals($preflightStateToken, $this->stateToken($locked, $purchaseOrder))) {
                return;
            }
            if (! in_array($locked->status, [
                PurchaseOrderImport::STATUS_PENDING,
                PurchaseOrderImport::STATUS_RETRY_SCHEDULED,
                PurchaseOrderImport::STATUS_NEEDS_ATTENTION,
                PurchaseOrderImport::STATUS_FAILED,
            ], true)) {
                return;
            }

            $locked->forceFill([
                'reason_code' => $result->reasonCode ?: 'ai_repair_failed',
                'reason_context' => [
                    'ai_status' => $result->status->value,
                    'ai_execution_id' => $result->metadata->executionId,
                ],
                'ai_execution_uuid' => $result->metadata->executionId,
            ])->save();
        });
    }

    /** @return array<string, mixed> */
    private function boundedInput(PurchaseOrderImport $import, array $normalized): array
    {
        $purchaseOrder = $import->purchaseOrder;

        return [
            'source' => [
                'fingerprint' => $import->source_fingerprint,
                'subject' => $this->inputMinimizer->boundedText(
                    data_get($import->safe_source_snapshot, 'subject'),
                    500,
                ),
                'received_at' => data_get($import->safe_source_snapshot, 'received_at'),
            ],
            'blocks' => collect($normalized['blocks'] ?? [])->take(80)->map(fn (array $block): array => [
                'id' => $this->inputMinimizer->boundedText($block['id'] ?? '', 100) ?? '',
                'type' => $this->inputMinimizer->boundedText($block['type'] ?? 'text', 50) ?? 'text',
                'text' => $this->inputMinimizer->boundedText($block['text'] ?? '', 2000) ?? '',
                'source' => $this->inputMinimizer->boundedText($block['source'] ?? 'body', 50) ?? 'body',
            ])->values()->all(),
            'tables' => collect($normalized['tables'] ?? [])->take(15)->map(fn (array $table): array => [
                'id' => $this->inputMinimizer->boundedText($table['id'] ?? '', 100) ?? '',
                'headers' => collect($table['headers'] ?? [])->take(25)->map(
                    fn (mixed $value): string => $this->inputMinimizer->boundedText($value, 200) ?? '',
                )->values()->all(),
                'rows' => collect($table['rows'] ?? [])->take(80)->map(fn (array $row): array => [
                    'id' => $this->inputMinimizer->boundedText($row['id'] ?? '', 100) ?? '',
                    'cells' => collect($row['cells'] ?? [])->take(25)->map(
                        fn (mixed $value, mixed $column): array => [
                            'column' => $this->inputMinimizer->boundedText($column, 200) ?? '',
                            'value' => $this->inputMinimizer->boundedText($value, 500) ?? '',
                        ],
                    )->values()->all(),
                ])->values()->all(),
            ])->values()->all(),
            'current' => [
                'document' => $this->boundedDocument((array) $import->normalized_document),
                'validation' => $this->boundedValidation((array) $import->validation_results),
                'profile_definition' => $this->boundedProfile((array) $import->profileVersion?->definition),
                'profile_version' => $import->profileVersion?->version_number,
                'import' => [
                    'status' => $import->status,
                    'stage' => $import->stage,
                    'decision' => $import->decision,
                    'reason_code' => $import->reason_code,
                    'purchase_order_id' => $import->purchase_order_id,
                ],
                'purchase_order' => $purchaseOrder ? [
                    'id' => $purchaseOrder->id,
                    'status' => $purchaseOrder->status,
                    'supplier_name' => $this->inputMinimizer->boundedText(
                        $purchaseOrder->supplier_name_snapshot,
                        500,
                    ),
                    'vendor_reference' => $this->inputMinimizer->boundedText($purchaseOrder->vendor_ref, 500),
                    'ordered_at' => optional($purchaseOrder->ordered_at)->toDateString(),
                    'expected_at' => optional($purchaseOrder->expected_at)->toDateString(),
                    'currency' => $purchaseOrder->currency,
                    'destination_warehouse_id' => $purchaseOrder->deliver_to_warehouse_id,
                    'lines' => $purchaseOrder->lines->take(500)->map(fn (mixed $line): array => [
                        'id' => $line->id,
                        'item_id' => $line->item_id,
                        'item_sku' => $this->inputMinimizer->boundedText($line->sku_snapshot, 255),
                        'supplier_sku' => $this->inputMinimizer->boundedText($line->supplier_sku_snapshot, 255),
                        'quantity_ordered' => $line->qty_ordered,
                        'quantity_received' => $line->qty_received,
                        'quantity_cancelled' => $line->qty_cancelled,
                        'unit_cost' => $line->unit_cost,
                        'tax_rate' => $line->tax_rate,
                        'currency' => $line->currency,
                    ])->values()->all(),
                ] : null,
                'items' => $import->lines->take(500)->map(fn (mixed $line): array => [
                    'position' => $line->position,
                    'supplier_sku' => $this->inputMinimizer->boundedText($line->supplier_sku, 255),
                    'mapping_status' => $line->mapping_status,
                    'resolution_method' => $line->resolution_method,
                    'item_id' => $line->item_id,
                    'item_sku' => $this->inputMinimizer->boundedText($line->item?->sku, 255),
                    'item_name' => $this->inputMinimizer->boundedText($line->item?->name, 500),
                    'item_status' => $line->item?->status,
                    'item_orderable' => $line->item?->can_be_ordered,
                    'item_warehouse_id' => $line->item?->warehouse_id,
                ])->values()->all(),
                'history' => $this->historyFacts($import, $purchaseOrder),
            ],
            'constraints' => [
                'declarative_profile_only' => true,
                'evidence_required' => true,
                'unknown_not_invented' => true,
                'no_tools_or_network' => true,
                'purchase_order_history_immutable' => true,
                'profile_match_scope_server_managed' => true,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function historyFacts(PurchaseOrderImport $import, ?PurchaseOrder $purchaseOrder): array
    {
        return [
            'previous_repairs' => $import->repairs->take(50)->map(fn (PurchaseOrderImportRepair $repair): array => [
                'sequence' => $repair->sequence,
                'status' => $repair->status,
                'created_at' => optional($repair->created_at)->toIso8601String(),
                'profile_candidate_version_id' => $repair->profile_candidate_version_id,
            ])->values()->all(),
            'shipment_count' => $purchaseOrder?->shipments->count() ?? 0,
            'receipt_count' => $purchaseOrder?->receipts->count() ?? 0,
            'order_cancelled' => $purchaseOrder?->cancelled_at !== null
                || $purchaseOrder?->status === PurchaseOrder::STATUS_CANCELLED,
            'order_closed' => $purchaseOrder?->closed_at !== null
                || $purchaseOrder?->status === PurchaseOrder::STATUS_CLOSED,
            'line_history' => $purchaseOrder?->lines->take(500)->map(function (mixed $line): array {
                $metadata = (array) $line->metadata;

                return [
                    'line_id' => $line->id,
                    'quantity_received' => $line->qty_received,
                    'quantity_cancelled' => $line->qty_cancelled,
                    'has_cancellation_history' => ! empty($metadata['cancellation_history']),
                    'quantity_change_count' => count((array) ($metadata['quantity_history'] ?? [])),
                ];
            })->values()->all() ?? [],
        ];
    }

    /** @return array<string, mixed> */
    private function boundedDocument(array $document): array
    {
        return $this->inputMinimizer->minimizeDocument($document);
    }

    /** @return array<string, mixed> */
    private function boundedValidation(array $validation): array
    {
        return [
            'valid' => (bool) ($validation['valid'] ?? false),
            'errors' => collect($validation['errors'] ?? [])->take(50)->map(fn (mixed $error): array => [
                'code' => is_array($error) ? ($error['code'] ?? null) : null,
                'path' => is_array($error) ? ($error['path'] ?? null) : null,
            ])->values()->all(),
            'warnings' => collect($validation['warnings'] ?? [])->take(50)->map(fn (mixed $warning): array => [
                'code' => is_array($warning) ? ($warning['code'] ?? null) : null,
                'path' => is_array($warning) ? ($warning['path'] ?? null) : null,
            ])->values()->all(),
        ];
    }

    private function boundedProfile(array $definition): array
    {
        $projected = $this->inputMinimizer->minimizeProfile($definition);

        return strlen(StableJson::encode($projected)) <= 20_000
            ? $projected
            : ['checksum' => StableJson::checksum($projected)];
    }

    /** @return list<string> */
    private function allowedInputFields(): array
    {
        return [
            'source.fingerprint', 'source.subject', 'source.received_at',
            'blocks.id', 'blocks.type', 'blocks.text', 'blocks.source',
            'tables.id', 'tables.headers', 'tables.rows.id', 'tables.rows.cells.column', 'tables.rows.cells.value',
            'current.document.*', 'current.validation.*', 'current.profile_definition.*',
            'current.profile_version', 'current.import.*', 'current.purchase_order.*',
            'current.items.*', 'current.history.*',
            'constraints.declarative_profile_only', 'constraints.evidence_required',
            'constraints.unknown_not_invented', 'constraints.no_tools_or_network',
            'constraints.purchase_order_history_immutable',
            'constraints.profile_match_scope_server_managed',
        ];
    }

    /** @return list<string> */
    private function identifiersToRedact(array $snapshot): array
    {
        return collect([
            data_get($snapshot, 'from.email'),
            data_get($snapshot, 'message_id'),
            ...collect($snapshot['to'] ?? [])->pluck('email')->all(),
            ...collect($snapshot['cc'] ?? [])->pluck('email')->all(),
        ])->filter(fn (mixed $value): bool => is_string($value) && $value !== '')->unique()->values()->all();
    }

    /** @return array<string, mixed> */
    private function responseSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['diagnosis', 'corrected_document', 'profile_candidate_json', 'change_summary', 'confidence'],
            'properties' => [
                'diagnosis' => ['type' => 'string', 'maxLength' => 2000],
                'corrected_document' => $this->extractionSchema->responseSchema(false),
                'profile_candidate_json' => ['type' => ['string', 'null'], 'maxLength' => 16000],
                'change_summary' => [
                    'type' => 'array', 'maxItems' => 50,
                    'items' => ['type' => 'string', 'maxLength' => 500],
                ],
                'confidence' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
            ],
        ];
    }

    /**
     * @return array{0: PurchaseOrderImportProfileVersion|null, 1: SupplierOrderProfileCandidateReproductionResult|null}
     */
    private function profileCandidate(
        PurchaseOrderImport $import,
        User $actor,
        mixed $candidateJson,
        PurchaseOrderAutomationPolicy $policy,
        array $correctedDocument,
    ): array {
        if (! is_string($candidateJson) || trim($candidateJson) === '') {
            return [null, null];
        }

        try {
            $definition = json_decode($candidateJson, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ValidationException::withMessages(['ai' => 'AI profile candidate is not valid JSON.']);
        }
        if (! is_array($definition)) {
            throw ValidationException::withMessages(['ai' => 'AI profile candidate must be a declarative object.']);
        }
        $definition['match'] = $this->serverManagedProfileMatchScope($import);
        $this->definitionValidator->validateOrFail($definition);
        $reproduction = $this->candidateReproducer->verifyOrFail(
            $definition,
            $import,
            $correctedDocument,
            $import->profile,
        );
        $profile = $import->profile ?: $this->createProfileContainer($import, $correctedDocument, $actor);
        $version = $this->createVersion->handle(
            profile: $profile,
            definition: $definition,
            source: 'ai_repair',
            actor: $actor,
            parent: $import->profileVersion,
        );
        $replay = $this->replayFixtures->handle($version, true);
        if ($replay->protectedPassed()) {
            $this->validateVersion->handle($version);
            $version->refresh();
        }

        $minimum = max(1, min(25, (int) $policy->ai_profile_shadow_samples));
        if ($policy->ai_profile_learning_mode === 'auto_activate'
            && $version->status === PurchaseOrderImportProfileVersion::STATUS_VALIDATED
            && $replay->protectedPassed()
            && $reproduction->historicalMinimumMet($minimum)) {
            $this->activateVersion->handle(
                $version,
                $actor,
                'AI repair passed protected fixtures and exact historical commercial reproduction.',
            );
            $version->refresh();
        }

        return [$version->fresh(), $reproduction];
    }

    private function createProfileContainer(
        PurchaseOrderImport $import,
        array $correctedDocument,
        User $actor,
    ): PurchaseOrderImportProfile {
        $supplierName = trim((string) data_get($correctedDocument, 'supplier.name', 'Supplier')) ?: 'Supplier';
        $base = Str::slug($supplierName.' supplier order') ?: 'supplier-order';
        $slug = $base;
        $counter = 2;
        while (PurchaseOrderImportProfile::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$counter++;
        }

        return PurchaseOrderImportProfile::query()->create([
            'vendor_id' => $import->vendor_id,
            'name' => Str::limit($supplierName.' supplier orders', 255, ''),
            'slug' => $slug,
            'description' => 'AI-proposed profile created from a reviewed supplier-order import.',
            'lifecycle_state' => PurchaseOrderImportProfile::STATE_DRAFT,
            'priority' => 100,
            'matching_scope' => $this->serverManagedProfileMatchScope($import),
            'policy_overrides' => ['ai_profile_learning_mode' => 'propose', 'ai_profile_shadow_samples' => 3],
            'health_state' => 'unknown',
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
    }

    /**
     * Match scope contains trust and ingress identifiers. It remains local and
     * replaces any AI-returned match object before candidate validation.
     *
     * @return array<string, mixed>
     */
    private function serverManagedProfileMatchScope(PurchaseOrderImport $import): array
    {
        $existing = data_get($import->profileVersion?->definition, 'match');
        if (is_array($existing) && $existing !== []) {
            return $existing;
        }

        $snapshot = (array) $import->safe_source_snapshot;
        $accountId = data_get($snapshot, 'account_id');
        $mailbox = Str::lower(trim((string) data_get($snapshot, 'mailbox', '')));
        $sender = Str::lower(trim((string) data_get($snapshot, 'from.email', '')));
        $senderDomain = str_contains($sender, '@')
            ? substr($sender, strrpos($sender, '@') + 1)
            : null;
        $authenticatedDomain = Str::lower(trim((string) data_get(
            $snapshot,
            'trusted_auth.authenticated_supplier_domain',
            '',
        )));
        $recipients = collect([
            ...(array) ($snapshot['to'] ?? []),
            ...(array) ($snapshot['cc'] ?? []),
        ])->map(function (mixed $address): string {
            if (is_array($address)) {
                return Str::lower(trim((string) ($address['email'] ?? '')));
            }

            return is_string($address) ? Str::lower(trim($address)) : '';
        });
        if (filter_var($mailbox, FILTER_VALIDATE_EMAIL) !== false) {
            $recipients->push($mailbox);
        }

        return [
            'account_ids' => is_numeric($accountId) && (int) $accountId > 0 ? [(int) $accountId] : [],
            'mailboxes' => array_values(array_filter([$mailbox])),
            'recipients' => $recipients
                ->filter(fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
                ->unique()
                ->values()
                ->all(),
            'senders' => filter_var($sender, FILTER_VALIDATE_EMAIL) !== false ? [$sender] : [],
            'sender_domains' => array_values(array_filter([$senderDomain])),
            'subject_markers' => [],
            'body_markers' => [],
            'authenticated_supplier_domains' => array_values(array_filter([$authenticatedDomain])),
            'require_trusted_auth' => true,
            'require_aligned' => true,
        ];
    }

    private function preHistoryBlockReason(
        PurchaseOrderImport $import,
        PurchaseOrder $purchaseOrder,
        array $corrected,
        User $actor,
    ): ?string {
        if (! $actor->can('storage.purchase_manage')) {
            return 'purchase_management_permission_missing';
        }
        if (! in_array($purchaseOrder->status, [
            PurchaseOrder::STATUS_DRAFT,
            PurchaseOrder::STATUS_ORDERED,
        ], true)) {
            return 'purchase_order_status_is_operational_or_terminal';
        }
        if ($purchaseOrder->cancelled_at !== null || $purchaseOrder->closed_at !== null) {
            return 'purchase_order_has_terminal_history';
        }
        $lifecycleHistory = (array) data_get($purchaseOrder->metadata, 'lifecycle_history', []);
        if (collect($lifecycleHistory)->contains(
            fn (mixed $event): bool => is_array($event)
                && ($event['to'] ?? null) === PurchaseOrder::STATUS_CANCELLED,
        )) {
            return 'purchase_order_has_cancellation_history';
        }
        if ($purchaseOrder->shipments->isNotEmpty() || filled($purchaseOrder->tracking_no)) {
            return 'purchase_order_has_shipment_history';
        }
        if ($purchaseOrder->receipts->isNotEmpty()) {
            return 'purchase_order_has_receipt_history';
        }
        if ($purchaseOrder->lines->contains(function (mixed $line): bool {
            $metadata = (array) $line->metadata;

            return (int) $line->qty_received > 0
                || (int) $line->qty_cancelled > 0
                || $line->cancelled_at !== null
                || $line->cancelled_by !== null
                || filled($line->cancellation_reason)
                || ! empty($metadata['cancellation_history']);
        })) {
            return 'purchase_order_has_line_receipt_or_cancellation_history';
        }
        if ((int) $import->vendor_id !== (int) $purchaseOrder->vendor_id) {
            return 'purchase_order_supplier_identity_mismatch';
        }
        if ($this->identity(data_get($corrected, 'supplier.name')) !== $this->identity(
            $purchaseOrder->supplier_name_snapshot ?: $purchaseOrder->vendor?->name,
        )) {
            return 'corrected_supplier_identity_differs_from_purchase_order';
        }
        if ((int) data_get($corrected, 'destination_warehouse_id') !== (int) $purchaseOrder->deliver_to_warehouse_id) {
            return 'corrected_destination_differs_from_purchase_order';
        }

        $correctedLines = array_values(array_filter(
            $corrected['lines'] ?? [],
            fn (mixed $line): bool => is_array($line),
        ));
        if (count($correctedLines) !== $purchaseOrder->lines->count() || $correctedLines === []) {
            return 'corrected_line_set_differs_from_purchase_order';
        }
        foreach ($purchaseOrder->lines->values() as $index => $line) {
            $correctedLine = $correctedLines[$index];
            if (SupplierSkuIdentity::normalize($correctedLine['supplier_sku'] ?? null)
                !== SupplierSkuIdentity::normalize($line->supplier_sku_snapshot)) {
                return 'corrected_supplier_sku_identity_differs_from_purchase_order';
            }
            $quantity = $correctedLine['quantity'] ?? null;
            if (! is_numeric($quantity) || (float) $quantity !== (float) (int) $quantity || (int) $quantity < 1) {
                return 'corrected_quantity_is_not_purchase_order_compatible';
            }
            if ($this->sourceUnitCost($correctedLine) === null) {
                return 'corrected_line_cost_is_missing';
            }
            if (! $line->item
                || $line->item->status !== 'active'
                || ! $line->item->can_be_ordered
                || (int) $line->item->warehouse_id !== (int) $purchaseOrder->deliver_to_warehouse_id) {
                return 'purchase_order_item_is_not_safely_editable';
            }
            if ($line->ticket_planned_line_id
                && (int) $line->item->primary_vendor_id !== (int) $purchaseOrder->vendor_id) {
                return 'ticket_linked_purchase_order_item_supplier_mismatch';
            }
        }

        $externalOrder = SupplierOrderIdentity::storedReference(data_get($corrected, 'external_order_number'));
        if ($externalOrder === null) {
            return 'corrected_external_order_number_missing';
        }
        $domainHash = $this->domainIdentityHash($purchaseOrder->vendor_id, $externalOrder);
        if (PurchaseOrderImport::query()
            ->where('domain_identity_hash', $domainHash)
            ->whereKeyNot($import->id)
            ->lockForUpdate()
            ->exists()) {
            return 'corrected_external_order_identity_conflicts_with_another_import';
        }

        return null;
    }

    private function applyPreHistoryPurchaseOrderCorrection(
        PurchaseOrderImport $import,
        PurchaseOrder $purchaseOrder,
        array $corrected,
        array $validation,
        ?PurchaseOrderImportProfileVersion $candidateVersion,
        PurchaseOrderImportRepair $repair,
        string $executionId,
        User $actor,
    ): void {
        $purchaseOrderLines = $purchaseOrder->lines->values();
        $correctedLines = array_values($corrected['lines'] ?? []);
        $updated = $this->updatePurchaseOrder->handle($purchaseOrder, [
            'vendor_id' => $purchaseOrder->vendor_id,
            'deliver_to_warehouse_id' => $purchaseOrder->deliver_to_warehouse_id,
            'status' => $purchaseOrder->status,
            'vendor_ref' => SupplierOrderIdentity::storedReference(data_get($corrected, 'external_order_number')),
            'ordered_at' => $this->orderDate($corrected, $import),
            'expected_at' => data_get($corrected, 'delivery.expected_at'),
            'currency' => strtoupper((string) data_get($corrected, 'currency', $purchaseOrder->currency)),
            'metadata' => [
                'latest_supplier_order_ai_repair_id' => $repair->id,
                'latest_supplier_order_ai_execution_id' => $executionId,
            ],
            'lines' => $purchaseOrderLines->map(function (mixed $line, int $index) use ($correctedLines, $corrected, $repair): array {
                $source = $correctedLines[$index];

                return [
                    'id' => $line->id,
                    'item_id' => $line->item_id,
                    'qty_ordered' => (int) $source['quantity'],
                    'qty_cancelled' => 0,
                    'supplier_sku' => $source['supplier_sku'] ?? null,
                    'unit_cost' => $this->sourceUnitCost($source),
                    'tax_rate' => $source['tax_rate'] ?? null,
                    'expected_at' => data_get($corrected, 'delivery.expected_at'),
                    'metadata' => [
                        'source_line_total' => $source['line_total'] ?? null,
                        'source_unit_cost_basis' => ($source['unit_price'] ?? null) === null
                            ? 'line_total_divided_by_quantity'
                            : 'unit_price',
                        'latest_supplier_order_ai_repair_id' => $repair->id,
                    ],
                ];
            })->all(),
        ], $actor, allowConfirmedIdentityCorrection: true);

        $externalOrder = SupplierOrderIdentity::storedReference(data_get($corrected, 'external_order_number'));
        $import->forceFill([
            'normalized_document' => $corrected,
            'external_order_number' => $externalOrder,
            'domain_identity_hash' => $this->domainIdentityHash($purchaseOrder->vendor_id, $externalOrder),
            'commercial_snapshot' => $corrected['totals'] ?? [],
            'delivery_snapshot' => $corrected['delivery'] ?? [],
            'validation_results' => $validation,
            'status' => PurchaseOrderImport::STATUS_IMPORTED,
            'stage' => PurchaseOrderImport::STAGE_FINALIZE,
            'reason_code' => 'ai_repair_applied_pre_history_purchase_order',
            'reason_context' => [
                'repair_id' => $repair->id,
                'purchase_order_id' => $purchaseOrder->id,
                'profile_candidate_version_id' => $candidateVersion?->id,
            ],
            'extraction_method' => 'ai',
            'ai_profile_candidate_version_id' => $candidateVersion?->id,
            'ai_execution_uuid' => $executionId,
            'processed_at' => now(),
            'finalized_at' => now(),
            'locked_at' => null,
            'last_actor_id' => $actor->id,
        ])->save();
        $this->syncLines->handle($import, $corrected);

        $importLines = $import->lines()->reorder('position')->lockForUpdate()->get();
        $updatedLines = $updated->lines()->orderBy('id')->lockForUpdate()->get()->values();
        foreach ($importLines->values() as $index => $line) {
            $purchaseOrderLine = $updatedLines->get($index);
            if ($purchaseOrderLine === null) {
                throw ValidationException::withMessages([
                    'purchase_order' => 'The corrected import and purchase-order line sets diverged during repair.',
                ]);
            }
            $line->forceFill([
                'item_id' => $purchaseOrderLine->item_id,
                'mapping_status' => PurchaseOrderImportLine::MAPPING_RESOLVED,
                'resolution_method' => 'purchase_order_pre_history_repair',
                'resolved_by' => $actor->id,
                'resolved_at' => now(),
            ])->save();
        }
    }

    /** @param array<string, mixed> $line */
    private function sourceUnitCost(array $line): ?float
    {
        if (is_numeric($line['unit_price'] ?? null)) {
            return round((float) $line['unit_price'], 2);
        }
        $quantity = is_numeric($line['quantity'] ?? null) ? (int) $line['quantity'] : 0;
        if (! is_numeric($line['line_total'] ?? null) || $quantity < 1) {
            return null;
        }

        return round((float) $line['line_total'] / $quantity, 2);
    }

    private function orderDate(array $document, PurchaseOrderImport $import): string
    {
        $value = data_get($document, 'ordered_at');
        if (filled($value)) {
            return CarbonImmutable::parse((string) $value)->toDateString();
        }
        if (data_get($document, 'ordered_at_provenance') !== 'received_at_fallback') {
            throw ValidationException::withMessages([
                'purchase_order' => 'Order date is missing and no received-date fallback was approved.',
            ]);
        }

        $receivedAt = data_get($import->safe_source_snapshot, 'received_at');
        if (blank($receivedAt)) {
            throw ValidationException::withMessages([
                'purchase_order' => 'Order date fallback requires the immutable source received date.',
            ]);
        }

        return CarbonImmutable::parse((string) $receivedAt)->toDateString();
    }

    private function domainIdentityHash(int $vendorId, string $externalOrder): ?string
    {
        return SupplierOrderIdentity::hash($vendorId, $externalOrder);
    }

    private function identity(mixed $value): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $value)) ?? trim((string) $value));
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

    private function storeRepairLocked(
        PurchaseOrderImport $import,
        User $actor,
        string $executionId,
        array $corrected,
        ?PurchaseOrderImportProfileVersion $candidateVersion,
        array $validation,
        string $status,
        array $decision,
    ): PurchaseOrderImportRepair {
        $sequence = (int) $import->repairs()->lockForUpdate()->max('sequence') + 1;

        return $import->repairs()->create([
            'sequence' => $sequence,
            'ai_execution_uuid' => $executionId,
            'status' => $status,
            'original_document_checksum' => $import->normalized_document
                ? StableJson::checksum((array) $import->normalized_document)
                : null,
            'corrected_document' => $corrected,
            'corrected_document_checksum' => StableJson::checksum($corrected),
            'profile_candidate_version_id' => $candidateVersion?->id,
            'validation_results' => $validation,
            'decision_summary' => $decision,
            'actor_id' => $actor->id,
        ]);
    }

    private function freshResult(PurchaseOrderImport $import): PurchaseOrderImport
    {
        return $import->fresh([
            'repairs.profileCandidateVersion',
            'profile',
            'profileVersion',
            'purchaseOrder.lines.item',
        ]);
    }

    private function reasoningEffort(PurchaseOrderAutomationPolicy $policy): string
    {
        $model = Str::lower(trim((string) $policy->aiWorkloadProfile?->model));

        return Str::contains($model, '-pro') ? 'medium' : 'low';
    }
}
