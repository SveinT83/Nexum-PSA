<?php

namespace App\Modules\Storage\Actions;

use App\Models\Core\User;
use App\Modules\Documentation\Actions\CreateSupplierFromPurchaseImport;
use App\Modules\Documentation\Models\Vendor;
use App\Modules\Storage\Jobs\ProcessSupplierOrderImport;
use App\Modules\Storage\Models\PurchaseOrderAutomationPolicy;
use App\Modules\Storage\Models\PurchaseOrderImport;
use App\Modules\Storage\Models\PurchaseOrderImportLine;
use App\Modules\Storage\Models\PurchaseOrderImportProfile;
use App\Modules\Storage\Models\PurchaseOrderImportProfileVersion;
use App\Modules\Storage\Models\PurchaseOrderImportRepair;
use App\Modules\Storage\Support\StableJson;
use App\Modules\Storage\Support\SupplierItemResolutionSummary;
use App\Modules\Storage\Support\SupplierOrderCanonicalValidator;
use App\Modules\Storage\Support\SupplierOrderDeterministicExtractor;
use App\Modules\Storage\Support\SupplierOrderIdentity;
use App\Modules\Storage\Support\SupplierOrderPolicyDecision;
use App\Modules\Storage\Support\SupplierOrderProfileMatcher;
use App\Modules\Storage\Support\SupplierOrderProfileMatchResult;
use App\Modules\Storage\Support\SupplierOrderSourceIntegrity;
use App\Modules\Storage\Support\SupplierSkuIdentity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProcessPurchaseOrderImport
{
    public function __construct(
        private readonly SupplierOrderProfileMatcher $profileMatcher,
        private readonly SupplierOrderDeterministicExtractor $deterministicExtractor,
        private readonly ExtractSupplierOrderWithAi $aiExtractor,
        private readonly ResolveEffectivePurchaseOrderAutomationPolicy $effectivePolicy,
        private readonly SupplierOrderCanonicalValidator $canonicalValidator,
        private readonly LearnSupplierOrderProfileFromAi $learnProfile,
        private readonly SyncPurchaseOrderImportLines $syncLines,
        private readonly ResolveSupplierOrderItems $resolveItems,
        private readonly EvaluateSupplierOrderImportPolicy $evaluatePolicy,
        private readonly FinalizeImportedPurchaseOrder $finalize,
        private readonly CreateSupplierFromPurchaseImport $createSupplier,
        private readonly SupplierOrderSourceIntegrity $sourceIntegrity,
    ) {}

    public function handle(
        PurchaseOrderImport $import,
        ?string $schedulerClaimToken = null,
    ): PurchaseOrderImport {
        $sourceImport = $import;
        $import = $this->startAttempt($import, $schedulerClaimToken);
        if (! $import) {
            return $sourceImport->fresh();
        }
        $sourceIntegrityErrors = $this->sourceIntegrity->errors(
            $import->safe_source_snapshot ?? [],
            (string) $import->source_fingerprint,
            $import->trusted_auth_snapshot ?? [],
        );
        if ($sourceIntegrityErrors !== []) {
            return $this->attention($import, 'source_integrity_failed', ['errors' => $sourceIntegrityErrors]);
        }
        $policy = $this->pinnedPolicy($import);

        try {
            [$profile, $version] = $this->resolveProfile($import);
            if ($profile && $version && ! $this->profileMatcher->matches(
                $profile,
                $version,
                $import->safe_source_snapshot ?? [],
            )) {
                return $this->attention($import, 'profile_source_scope_mismatch', [
                    'profile_id' => $profile->id,
                    'profile_version_id' => $version->id,
                ]);
            }
            if ($profile && $version && (
                (int) $import->profile_id !== (int) $profile->id
                || (int) $import->profile_version_id !== (int) $version->id
            )) {
                $import->forceFill([
                    'profile_id' => $profile->id,
                    'profile_version_id' => $version->id,
                ])->save();
                $import->setRelation('profile', $profile);
                $import->setRelation('profileVersion', $version);
            }
            $policy = $this->effectivePolicy->handle($import, $policy, $profile, $version);

            $document = null;
            $method = null;
            $warnings = [];
            $aiMetadata = null;
            $deterministicDocument = null;
            $ai = null;
            $appliedRepair = $this->approvedRepair($import);
            if ($appliedRepair) {
                $document = $appliedRepair->corrected_document;
                $method = filled($appliedRepair->ai_execution_uuid) ? 'ai' : 'manual_review';
                $aiMetadata = [
                    'status' => $method === 'ai' ? 'success' : 'manual_review',
                    'execution_id' => $appliedRepair->ai_execution_uuid,
                    'repair_id' => $appliedRepair->id,
                    'repair_method' => $method,
                ];
            } elseif ($version) {
                $this->stage($import, PurchaseOrderImport::STAGE_DETERMINISTIC_EXTRACT);
                $extraction = $this->deterministicExtractor->extract($version, $import->safe_source_snapshot ?? []);
                if ($extraction->valid()) {
                    $candidate = $this->applyApprovedDefaults(
                        $extraction->document ?? [],
                        $import,
                        $policy,
                        $version,
                    );
                    $candidateValidation = $this->canonicalValidator->validate(
                        $candidate,
                        $policy,
                        $import->safe_source_snapshot ?? [],
                    );
                    if ($candidateValidation->valid()) {
                        $document = $candidate;
                        $method = 'deterministic';
                        $warnings = $extraction->warnings;
                        $deterministicDocument = $document;
                    } else {
                        $warnings = array_merge(
                            $warnings,
                            collect($candidateValidation->errors)->pluck('code')->filter()->all(),
                        );
                    }
                } else {
                    $warnings = array_merge($warnings, $extraction->errors, $extraction->warnings);
                }
            }

            if (! $appliedRepair && $this->shouldUseAi($policy, $document)) {
                $this->stage($import, PurchaseOrderImport::STAGE_AI_EXTRACT);
                $ai = $this->aiExtractor->handle($import->fresh(['profile', 'profileVersion']), $policy);
                $aiMetadata = $ai->toArray();
                if ($ai->successful()) {
                    if ($deterministicDocument !== null
                        && ! hash_equals(
                            StableJson::checksum($this->criticalDocumentFacts($deterministicDocument)),
                            StableJson::checksum($this->criticalDocumentFacts($ai->document ?? [])),
                        )) {
                        return $this->attention($import, 'ai_deterministic_disagreement', [
                            'deterministic_fingerprint' => StableJson::checksum($this->criticalDocumentFacts($deterministicDocument)),
                            'ai_fingerprint' => StableJson::checksum($this->criticalDocumentFacts($ai->document ?? [])),
                            'ai' => $aiMetadata,
                        ]);
                    }
                    $document = $ai->document;
                    $method = 'ai';
                    $import->forceFill(['ai_execution_uuid' => $ai->executionId])->save();
                } elseif ($ai->status === 'unavailable'
                    && $document !== null
                    && $policy->provider_outage_behavior === 'deterministic_only') {
                    $warnings[] = 'ai_provider_unavailable_deterministic_fallback';
                } else {
                    return $this->handleAiFailure($import, $policy, $ai->status, $ai->reasonCode, $aiMetadata);
                }
            }

            if (! is_array($document)) {
                return $this->attention($import, 'profile_or_extraction_unresolved', [
                    'profile_id' => $profile?->id,
                    'profile_version_id' => $version?->id,
                    'warnings' => array_slice($warnings, 0, 25),
                ]);
            }

            if ($method === 'ai') {
                $evidenceValidation = $this->canonicalValidator->verifySourceEvidence(
                    $document,
                    $import->safe_source_snapshot ?? [],
                    $import->source_fingerprint,
                );
                if (! $evidenceValidation->valid()) {
                    $import->forceFill([
                        'normalized_document' => $document,
                        'external_order_number' => SupplierOrderIdentity::storedReference($document['external_order_number'] ?? null),
                        'extraction_method' => 'ai',
                        'validation_results' => $evidenceValidation->toArray(),
                    ])->save();
                    $this->recordProfileFailure($profile, $policy, 'ai_source_evidence_invalid');

                    return $this->attention($import, 'ai_source_evidence_invalid', [
                        'errors' => array_slice($evidenceValidation->errors, 0, 50),
                        'ai' => $aiMetadata,
                    ]);
                }
            }

            $document = $this->applyApprovedDefaults($document, $import, $policy, $version);
            $import->forceFill([
                'normalized_document' => $document,
                'external_order_number' => SupplierOrderIdentity::storedReference($document['external_order_number'] ?? null),
                'extraction_method' => $method,
                'commercial_snapshot' => is_array($document['totals'] ?? null) ? $document['totals'] : [],
                'delivery_snapshot' => is_array($document['delivery'] ?? null) ? $document['delivery'] : [],
            ])->save();

            $this->stage($import, PurchaseOrderImport::STAGE_VALIDATE);
            $validation = $this->canonicalValidator->validate(
                $document,
                $policy,
                $import->safe_source_snapshot ?? [],
            );
            $import->forceFill(['validation_results' => $validation->toArray()])->save();
            if (! $validation->valid()) {
                $this->recordProfileFailure($profile, $policy, 'canonical_validation_failed');

                return $this->attention($import, 'canonical_validation_failed', [
                    'errors' => array_slice($validation->errors, 0, 50),
                    'ai' => $aiMetadata,
                ]);
            }

            if ($policy->runtime_mode === PurchaseOrderAutomationPolicy::MODE_SHADOW) {
                $this->syncLines->handle($import, $document);
                $lineCount = $import->lines()->count();
                $items = new SupplierItemResolutionSummary(
                    resolved: 0,
                    created: 0,
                    review: 0,
                    ambiguous: 0,
                    unresolved: $lineCount,
                    reasonCodes: ['shadow_item_resolution_skipped'],
                );
                $confidence = array_merge($validation->confidenceDimensions, [
                    'source_trust' => $this->sourceTrusted($import) ? 100 : 0,
                    'item_identity' => 0,
                ], $method === 'ai' ? ['ai_result_validity' => 100] : []);
                $import->forceFill(['confidence_dimensions' => $confidence])->save();

                $this->stage($import, PurchaseOrderImport::STAGE_POLICY);
                $decision = $this->evaluatePolicy->handle(
                    $import->fresh(['lines', 'profile', 'profileVersion']),
                    $policy,
                    $validation,
                    $items,
                );
                $import->forceFill(['decision' => $decision->outcome])->save();
                $this->recordProfileResult($profile, $validation->valid());

                return $this->attention($import, SupplierOrderPolicyDecision::SHADOW_COMPLETE, [
                    'decision' => $decision->toArray(),
                    'item_resolution' => $items->toArray(),
                    'ai' => $aiMetadata,
                ]);
            }

            $vendor = $this->resolveSupplier($import, $policy, $profile, $document);
            if (! $vendor || ! $vendor->is_active || ! $vendor->is_supplier) {
                return $this->attention($import, 'supplier_requires_review', [
                    'vendor_id' => $vendor?->id,
                    'bootstrap_mode' => $policy->supplier_bootstrap_mode,
                ]);
            }
            $import->forceFill(['vendor_id' => $vendor->id])->save();

            if ($method === 'ai' && $ai?->profileCandidateDefinition !== null) {
                try {
                    $candidateVersion = $this->learnProfile->handle(
                        $import->fresh(['profile', 'profileVersion']),
                        $ai->profileCandidateDefinition,
                        $document,
                        $policy,
                    );
                    $aiMetadata['profile_learning'] = [
                        'status' => $candidateVersion ? 'candidate_ready' : 'disabled',
                        'candidate_version_id' => $candidateVersion?->id,
                        'candidate_status' => $candidateVersion?->status,
                    ];
                } catch (ValidationException $exception) {
                    $aiMetadata['profile_learning'] = [
                        'status' => 'candidate_rejected',
                        'reason_codes' => collect($exception->errors())->keys()->take(20)->values()->all(),
                    ];
                }
            }
            if ($method === 'ai' && $profile === null) {
                $bootstrapVersion = $import->fresh(['aiProfileCandidateVersion.profile'])
                    ->aiProfileCandidateVersion;
                $bootstrapProfile = $bootstrapVersion?->profile;
                $bootstrapReady = $bootstrapVersion?->status === PurchaseOrderImportProfileVersion::STATUS_ACTIVE
                    && $bootstrapProfile?->lifecycle_state === PurchaseOrderImportProfile::STATE_ACTIVE
                    && (int) $bootstrapProfile?->active_version_id === (int) $bootstrapVersion?->id
                    && (int) $bootstrapProfile?->vendor_id === (int) $vendor->id;
                if (! $bootstrapReady) {
                    return $this->attention($import, 'ai_profile_bootstrap_incomplete', [
                        'ai' => $aiMetadata,
                    ]);
                }
                $profile = $bootstrapProfile;
                $version = $bootstrapVersion;
            }

            if ($conflict = $this->existingDomainConflict($import, $vendor, $document)) {
                $sameSource = hash_equals($conflict->source_fingerprint, $import->source_fingerprint);
                $status = $sameSource
                    ? PurchaseOrderImport::STATUS_DUPLICATE
                    : PurchaseOrderImport::STATUS_NEEDS_ATTENTION;
                $reason = $sameSource ? 'duplicate_supplier_order' : 'changed_supplier_order_resend';
                $import->forceFill([
                    'revision_of_import_id' => $conflict->id,
                    'status' => $status,
                    'reason_code' => $reason,
                    'reason_context' => ['conflicting_import_id' => $conflict->id],
                    'locked_at' => null,
                    'processed_at' => now(),
                ])->save();
                $this->recordProfileResult($profile, true);
                $this->completeAttempt($import, $status, $reason, [
                    'conflicting_import_id' => $conflict->id,
                ]);

                return $import->fresh();
            }

            $this->syncLines->handle($import, $document);
            $this->stage($import, PurchaseOrderImport::STAGE_ITEM_RESOLUTION);
            $actor = $this->automationActor($policy);
            $items = $this->resolveItems->handle($import->fresh(['lines', 'profileVersion']), $policy, $actor);
            $confidence = array_merge($validation->confidenceDimensions, [
                'source_trust' => $this->sourceTrusted($import) ? 100 : 0,
                'item_identity' => $items->allResolved() ? 100 : 0,
            ], $method === 'ai' ? ['ai_result_validity' => 100] : []);
            $import->forceFill(['confidence_dimensions' => $confidence])->save();

            $this->stage($import, PurchaseOrderImport::STAGE_POLICY);
            $decision = $this->evaluatePolicy->handle($import->fresh([
                'lines.item', 'vendor', 'profile', 'profileVersion', 'aiProfileCandidateVersion.profile',
            ]), $policy, $validation, $items);
            $import->forceFill([
                'decision' => $decision->outcome,
                'reason_context' => [
                    'decision' => $decision->toArray(),
                    'item_resolution' => $items->toArray(),
                    'ai' => $aiMetadata,
                ],
            ])->save();

            if (! $decision->permitsPurchaseOrderWrite()) {
                $this->recordProfileResult($profile, $validation->valid() && $items->allResolved());

                return $this->attention(
                    $import,
                    $decision->outcome === 'shadow_complete' ? 'shadow_complete' : ($decision->reasonCodes[0] ?? 'policy_requires_attention'),
                    ['decision' => $decision->toArray(), 'item_resolution' => $items->toArray(), 'ai' => $aiMetadata],
                );
            }

            $this->stage($import, PurchaseOrderImport::STAGE_FINALIZE);
            $purchaseOrder = $this->finalize->handle($import, $policy, $decision);
            $import->refresh();

            $completedStatus = $import->status;
            $completedReason = $import->reason_code;
            $this->recordProfileResult($profile, $completedStatus !== PurchaseOrderImport::STATUS_NEEDS_ATTENTION);
            $this->completeAttempt($import, $completedStatus, $completedReason, [
                'purchase_order_id' => $purchaseOrder?->id,
                'conflicting_import_id' => data_get($import->reason_context, 'conflicting_import_id'),
            ]);

            if (! $purchaseOrder) {
                return $import->fresh();
            }

            return $import->fresh(['purchaseOrder', 'lines.item']);
        } catch (ValidationException|AuthorizationException $exception) {
            $this->recordProfileFailure($import->profile, $policy, 'domain_validation_failed');

            return $this->attention($import, 'domain_validation_failed', [
                'errors' => $exception instanceof ValidationException
                    ? collect($exception->errors())->flatten()->take(20)->values()->all()
                    : [$exception->getMessage()],
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return $this->scheduleRetry($import, $policy, $exception);
        }
    }

    private function startAttempt(
        PurchaseOrderImport $import,
        ?string $schedulerClaimToken = null,
    ): ?PurchaseOrderImport {
        return DB::transaction(function () use ($import, $schedulerClaimToken): ?PurchaseOrderImport {
            $locked = PurchaseOrderImport::query()->lockForUpdate()->findOrFail($import->id);
            if (in_array($locked->status, [
                PurchaseOrderImport::STATUS_IMPORTED,
                PurchaseOrderImport::STATUS_DUPLICATE,
                PurchaseOrderImport::STATUS_REJECTED,
                PurchaseOrderImport::STATUS_CANCELLED,
            ], true)) {
                return null;
            }
            if ($schedulerClaimToken !== null) {
                if (! $this->matchesScheduledClaim($locked, $schedulerClaimToken)) {
                    return null;
                }
            } elseif ($locked->status === PurchaseOrderImport::STATUS_PROCESSING) {
                if ($locked->reason_code === 'scheduled_dispatch_claimed'
                    || $locked->locked_at?->isAfter(now()->subMinutes(15))) {
                    return null;
                }
            }

            $locked->forceFill([
                'status' => PurchaseOrderImport::STATUS_PROCESSING,
                'stage' => PurchaseOrderImport::STAGE_DETECT,
                'reason_code' => null,
                'reason_context' => null,
                'attempt_count' => $locked->attempt_count + 1,
                'locked_at' => now(),
                'next_retry_at' => null,
            ])->save();
            $this->recordAttempt($locked, PurchaseOrderImport::STAGE_DETECT, 'processing');

            return $locked->fresh(['policyRevision', 'profile', 'profileVersion']);
        });
    }

    private function matchesScheduledClaim(PurchaseOrderImport $import, string $claimToken): bool
    {
        if ($import->status !== PurchaseOrderImport::STATUS_PROCESSING
            || $import->reason_code !== 'scheduled_dispatch_claimed') {
            return false;
        }

        return DB::table('storage_purchase_order_import_dispatches')
            ->where('import_id', $import->id)
            ->where('claim_token', $claimToken)
            ->whereIn('status', ['dispatched', 'running'])
            ->lockForUpdate()
            ->exists();
    }

    private function pinnedPolicy(PurchaseOrderImport $import): PurchaseOrderAutomationPolicy
    {
        $revision = $import->policyRevision;
        if (! $revision) {
            throw ValidationException::withMessages(['policy' => 'Pinned policy revision is unavailable.']);
        }

        return $this->effectivePolicy->fromPinnedRevision($revision);
    }

    /** @return array{0: ?PurchaseOrderImportProfile, 1: ?PurchaseOrderImportProfileVersion} */
    private function resolveProfile(PurchaseOrderImport $import): array
    {
        if ($import->profileVersion) {
            return [$import->profileVersion->profile, $import->profileVersion];
        }

        $snapshot = $import->effective_policy_snapshot;
        if (is_array($snapshot)
            && (int) ($snapshot['profile_id'] ?? 0) === 0
            && (int) ($snapshot['profile_version_id'] ?? 0) === 0) {
            // Preserve the original profileless decision on retries. If another
            // import activated a profile meanwhile, learning will safely reuse it
            // under the Supplier lock after this import is verified by AI again.
            return [null, null];
        }

        $match = $this->profileMatcher->match($import->safe_source_snapshot ?? []);
        if ($match->status === SupplierOrderProfileMatchResult::STATUS_AMBIGUOUS) {
            throw ValidationException::withMessages([
                'profile' => 'Several supplier profiles matched at the same priority.',
            ]);
        }

        return $match->matched() ? [$match->profile, $match->version] : [null, null];
    }

    private function shouldUseAi(PurchaseOrderAutomationPolicy $policy, ?array $document): bool
    {
        if ($policy->ai_mode === 'off') {
            return false;
        }

        return $policy->ai_mode === 'always' || $document === null;
    }

    private function handleAiFailure(
        PurchaseOrderImport $import,
        PurchaseOrderAutomationPolicy $policy,
        string $status,
        ?string $reason,
        array $metadata,
    ): PurchaseOrderImport {
        if ($this->retryableAiFailure($status, $reason)
            && $import->attempt_count <= $policy->retry_limit) {
            $delay = min(86400, $policy->retry_base_seconds * (2 ** max(0, $import->attempt_count - 1)));
            $import->forceFill([
                'status' => PurchaseOrderImport::STATUS_RETRY_SCHEDULED,
                'reason_code' => $reason ?: 'ai_provider_unavailable',
                'reason_context' => ['ai' => $metadata],
                'next_retry_at' => now()->addSeconds($delay),
                'locked_at' => null,
            ])->save();
            $this->completeAttempt($import, PurchaseOrderImport::STATUS_RETRY_SCHEDULED, $reason, ['ai' => $metadata]);
            ProcessSupplierOrderImport::dispatch($import->id)->delay($import->next_retry_at)->afterCommit();

            return $import->fresh();
        }

        return $this->attention($import, $reason ?: 'ai_extraction_failed', ['ai' => $metadata]);
    }

    private function retryableAiFailure(string $status, ?string $reason): bool
    {
        if ($status === 'unavailable') {
            return true;
        }

        return $status === 'invalid' && in_array($reason, [
            'provider_response_invalid',
            'response_json_invalid',
            'response_schema_invalid',
            'ai_profile_candidate_invalid',
        ], true);
    }

    private function applyApprovedDefaults(
        array $document,
        PurchaseOrderImport $import,
        PurchaseOrderAutomationPolicy $policy,
        ?PurchaseOrderImportProfileVersion $version,
    ): array {
        $defaults = is_array(data_get($version?->definition, 'defaults'))
            ? data_get($version?->definition, 'defaults')
            : [];
        $document['schema_version'] = 'storage.supplier_order.v1';
        $document['document_type'] = 'supplier_order_confirmation';
        $document['destination_warehouse_id'] ??= $defaults['warehouse_id'] ?? $policy->default_warehouse_id;
        if (blank($document['currency'] ?? null) && filled($defaults['currency'] ?? null)) {
            $document['currency'] = strtoupper((string) $defaults['currency']);
        }
        if (blank($document['ordered_at'] ?? null)
            && ($defaults['ordered_date_fallback'] ?? null) === 'received_at') {
            $receivedAt = data_get($import->safe_source_snapshot, 'received_at');
            if (filled($receivedAt)) {
                $document['ordered_at'] = substr((string) $receivedAt, 0, 10);
                $document['ordered_at_provenance'] = 'received_at_fallback';
            }
        }

        return $document;
    }

    private function resolveSupplier(
        PurchaseOrderImport $import,
        PurchaseOrderAutomationPolicy $policy,
        ?PurchaseOrderImportProfile $profile,
        array $document,
    ): ?Vendor {
        if ($profile?->vendor_id) {
            return Vendor::query()->find($profile->vendor_id);
        }
        if ($policy->supplier_bootstrap_mode === 'existing_only') {
            return null;
        }

        $auth = $import->trusted_auth_snapshot ?? [];
        $mode = $policy->supplier_bootstrap_mode === 'create_active'
            ? CreateSupplierFromPurchaseImport::MODE_ACTIVE
            : CreateSupplierFromPurchaseImport::MODE_REVIEW_CANDIDATE;

        return $this->createSupplier->handle([
            'source_import_id' => $import->id,
            'source_fingerprint' => $import->source_fingerprint,
            'supplier_name' => data_get($document, 'supplier.name'),
            'authentication_passed' => (bool) ($auth['authentication_passed'] ?? false),
            'aligned' => (bool) ($auth['aligned'] ?? false),
            'authenticated_supplier_identity' => $auth['authenticated_supplier_identity'] ?? '',
            'authenticated_supplier_domain' => $auth['authenticated_supplier_domain'] ?? '',
            'authserv_id' => $auth['authserv_id'] ?? '',
            'spf' => $auth['spf'] ?? 'unknown',
            'dkim' => $auth['dkim'] ?? 'unknown',
            'dmarc' => $auth['dmarc'] ?? 'unknown',
            'email' => filter_var($auth['authenticated_supplier_identity'] ?? null, FILTER_VALIDATE_EMAIL)
                ? $auth['authenticated_supplier_identity']
                : null,
            'service_identity' => 'storage.supplier-order-import',
        ], $mode, $this->automationActor($policy));
    }

    private function existingDomainConflict(
        PurchaseOrderImport $import,
        Vendor $vendor,
        array $document,
    ): ?PurchaseOrderImport {
        $externalOrder = SupplierOrderIdentity::storedReference($document['external_order_number'] ?? null);
        if ($externalOrder === null) {
            return null;
        }
        $hash = SupplierOrderIdentity::hash($vendor->id, $externalOrder);

        return PurchaseOrderImport::query()
            ->where('domain_identity_hash', $hash)
            ->whereKeyNot($import->id)
            ->first();
    }

    private function syncImportLines(PurchaseOrderImport $import, array $document): void
    {
        DB::transaction(function () use ($import, $document): void {
            $existing = $import->lines()->lockForUpdate()->get()->keyBy('position');
            $seen = [];
            foreach (array_values($document['lines'] ?? []) as $index => $sourceLine) {
                $position = $index + 1;
                $line = $existing->get($position) ?: new PurchaseOrderImportLine([
                    'import_id' => $import->id,
                    'position' => $position,
                ]);
                $normalizedSku = SupplierSkuIdentity::normalize($sourceLine['supplier_sku'] ?? null);
                $identityChanged = $line->exists
                    && $line->normalized_supplier_sku !== $normalizedSku;
                $line->fill([
                    'source_row_identifier' => $sourceLine['source_row_identifier'] ?? (string) $position,
                    'supplier_sku' => $sourceLine['supplier_sku'] ?? null,
                    'normalized_supplier_sku' => $normalizedSku ?: null,
                    'description' => $sourceLine['description'] ?? null,
                    'quantity' => $sourceLine['quantity'] ?? null,
                    'unit_price' => $sourceLine['unit_price'] ?? null,
                    'line_total' => $sourceLine['line_total'] ?? null,
                    'tax_rate' => $sourceLine['tax_rate'] ?? null,
                    'currency' => strtoupper((string) ($sourceLine['currency'] ?? $document['currency'] ?? 'NOK')),
                    'evidence' => $sourceLine['evidence'] ?? [],
                    'extracted_fields' => $sourceLine,
                    'field_confidence' => $sourceLine['confidence'] ?? [],
                ]);
                if ($identityChanged) {
                    $line->fill([
                        'item_id' => null,
                        'mapping_status' => PurchaseOrderImportLine::MAPPING_UNRESOLVED,
                        'resolution_method' => null,
                        'resolved_by' => null,
                        'resolved_at' => null,
                    ]);
                }
                $line->save();
                $seen[] = $position;
            }

            $import->lines()->whereNotIn('position', $seen)->delete();
        });
    }

    private function automationActor(PurchaseOrderAutomationPolicy $policy): ?User
    {
        return $policy->automation_user_id
            ? User::query()->find($policy->automation_user_id)
            : null;
    }

    private function sourceTrusted(PurchaseOrderImport $import): bool
    {
        $auth = $import->trusted_auth_snapshot ?? [];

        return (bool) ($auth['authentication_passed'] ?? false) && (bool) ($auth['aligned'] ?? false);
    }

    private function stage(PurchaseOrderImport $import, string $stage): void
    {
        $import->forceFill(['stage' => $stage])->save();
        $this->recordAttempt($import, $stage, 'processing');
    }

    private function recordAttempt(PurchaseOrderImport $import, string $stage, string $status, ?string $reason = null, array $metadata = []): void
    {
        $import->attempts()->create([
            'attempt_number' => max(1, $import->attempt_count),
            'stage' => $stage,
            'method' => $import->extraction_method,
            'status' => $status,
            'reason_code' => $reason,
            'input_fingerprint' => $import->source_fingerprint,
            'output_fingerprint' => $import->normalized_document
                ? StableJson::checksum($import->normalized_document)
                : null,
            'metadata' => collect($metadata)->except(['body', 'headers', 'prompt', 'response'])->take(50)->all(),
            'service_identity' => 'storage.supplier-order-import',
            'started_at' => now(),
            'completed_at' => $status === 'processing' ? null : now(),
        ]);
    }

    private function completeAttempt(PurchaseOrderImport $import, string $status, ?string $reason, array $metadata = []): void
    {
        $this->recordAttempt($import, $import->stage, $status, $reason, $metadata);
    }

    private function attention(PurchaseOrderImport $import, string $reason, array $context = []): PurchaseOrderImport
    {
        $import->forceFill([
            'status' => PurchaseOrderImport::STATUS_NEEDS_ATTENTION,
            'reason_code' => $reason,
            'reason_context' => collect($context)->except(['body', 'headers', 'prompt', 'response'])->take(50)->all(),
            'locked_at' => null,
            'processed_at' => now(),
        ])->save();
        $this->completeAttempt($import, PurchaseOrderImport::STATUS_NEEDS_ATTENTION, $reason, $context);

        return $import->fresh(['lines.item', 'profile', 'profileVersion']);
    }

    private function scheduleRetry(
        PurchaseOrderImport $import,
        PurchaseOrderAutomationPolicy $policy,
        Throwable $exception,
    ): PurchaseOrderImport {
        $delay = min(86400, $policy->retry_base_seconds * (2 ** max(0, $import->attempt_count - 1)));
        $import->forceFill([
            'status' => $import->attempt_count <= $policy->retry_limit
                ? PurchaseOrderImport::STATUS_RETRY_SCHEDULED
                : PurchaseOrderImport::STATUS_FAILED,
            'reason_code' => $import->attempt_count <= $policy->retry_limit
                ? 'transient_processing_failure'
                : 'retry_limit_exhausted',
            'reason_context' => ['exception_class' => $exception::class],
            'next_retry_at' => $import->attempt_count <= $policy->retry_limit ? now()->addSeconds($delay) : null,
            'locked_at' => null,
        ])->save();
        $this->completeAttempt($import, $import->status, $import->reason_code, [
            'exception_class' => $exception::class,
        ]);

        return $import->fresh();
    }

    private function recordProfileFailure(
        ?PurchaseOrderImportProfile $profile,
        PurchaseOrderAutomationPolicy $policy,
        string $reason,
    ): void {
        if (! $profile) {
            return;
        }

        app(RecordSupplierOrderProfileHealth::class)->failure(
            $profile,
            (int) $policy->circuit_breaker_failures,
            $reason,
        );
    }

    private function recordProfileResult(?PurchaseOrderImportProfile $profile, bool $success): void
    {
        if (! $profile) {
            return;
        }

        app(RecordSupplierOrderProfileHealth::class)->result($profile, $success);
    }

    /** @return array<string, mixed> */
    private function criticalDocumentFacts(array $document): array
    {
        return [
            'supplier' => data_get($document, 'supplier.name'),
            'external_order_number' => data_get($document, 'external_order_number'),
            'ordered_at' => data_get($document, 'ordered_at'),
            'currency' => data_get($document, 'currency'),
            'lines' => collect($document['lines'] ?? [])->map(fn (array $line): array => [
                'supplier_sku' => $line['supplier_sku'] ?? null,
                'description' => $line['description'] ?? null,
                'quantity' => $line['quantity'] ?? null,
                'unit_price' => $line['unit_price'] ?? null,
                'line_total' => $line['line_total'] ?? null,
            ])->values()->all(),
            'totals' => collect($document['totals'] ?? [])->only([
                'goods_subtotal', 'freight', 'discount', 'other_charges', 'tax_total',
                'total_ex_tax', 'total_inc_tax',
            ])->all(),
        ];
    }

    private function approvedRepair(PurchaseOrderImport $import): ?PurchaseOrderImportRepair
    {
        $repair = $import->repairs()
            ->where('status', PurchaseOrderImportRepair::STATUS_READY_FOR_REPROCESS)
            ->latest('sequence')
            ->first();
        if (! $repair || ! is_array($repair->corrected_document)) {
            return null;
        }
        if (! hash_equals($repair->corrected_document_checksum, StableJson::checksum($repair->corrected_document))) {
            return null;
        }

        return $repair;
    }
}
