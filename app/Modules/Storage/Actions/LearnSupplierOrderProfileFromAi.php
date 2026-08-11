<?php

namespace App\Modules\Storage\Actions;

use App\Models\Core\User;
use App\Modules\Documentation\Models\Vendor;
use App\Modules\Storage\Models\PurchaseOrderAutomationPolicy;
use App\Modules\Storage\Models\PurchaseOrderImport;
use App\Modules\Storage\Models\PurchaseOrderImportProfile;
use App\Modules\Storage\Models\PurchaseOrderImportProfileFixture;
use App\Modules\Storage\Models\PurchaseOrderImportProfileVersion;
use App\Modules\Storage\Support\StableJson;
use App\Modules\Storage\Support\SupplierOrderProfileCandidateReproducer;
use App\Modules\Storage\Support\SupplierOrderProfileDefinitionValidator;
use App\Modules\Storage\Support\SupplierOrderProfileMatcher;
use App\Modules\Storage\Support\SupplierOrderProfileMatchResult;
use App\Modules\Storage\Support\SupplierOrderSourceIntegrity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LearnSupplierOrderProfileFromAi
{
    public function __construct(
        private readonly SupplierOrderProfileDefinitionValidator $definitionValidator,
        private readonly CreateSupplierOrderProfileVersion $createVersion,
        private readonly ReplaySupplierOrderProfileFixtures $replayFixtures,
        private readonly ValidateSupplierOrderProfileVersion $validateVersion,
        private readonly ActivateSupplierOrderProfileVersion $activateVersion,
        private readonly SupplierOrderSourceIntegrity $sourceIntegrity,
        private readonly SupplierOrderProfileCandidateReproducer $candidateReproducer,
        private readonly SupplierOrderProfileMatcher $profileMatcher,
    ) {}

    /** @param array<string, mixed> $candidateDefinition */
    public function handle(
        PurchaseOrderImport $import,
        array $candidateDefinition,
        array $canonicalDocument,
        PurchaseOrderAutomationPolicy $policy,
    ): ?PurchaseOrderImportProfileVersion {
        if ($policy->ai_profile_learning_mode === 'off') {
            return null;
        }

        $actor = $this->automationActor($policy);
        $this->sourceIntegrity->validateOrFail(
            (array) $import->safe_source_snapshot,
            (string) $import->source_fingerprint,
            (array) $import->trusted_auth_snapshot,
        );
        $this->assertTrustedSource($import);
        $candidateDefinition['match'] = $this->serverManagedProfileMatchScope($import);
        $this->definitionValidator->validateOrFail($candidateDefinition);

        return DB::transaction(function () use (
            $import,
            $candidateDefinition,
            $canonicalDocument,
            $policy,
            $actor,
        ): PurchaseOrderImportProfileVersion {
            $lockedImport = PurchaseOrderImport::query()
                ->with(['profile.activeVersion'])
                ->lockForUpdate()
                ->findOrFail($import->id);
            $this->sourceIntegrity->validateOrFail(
                (array) $lockedImport->safe_source_snapshot,
                (string) $lockedImport->source_fingerprint,
                (array) $lockedImport->trusted_auth_snapshot,
            );
            $this->assertTrustedSource($lockedImport);
            $candidateDefinition['match'] = $this->serverManagedProfileMatchScope($lockedImport);
            $profile = $lockedImport->profile
                ?: $this->resolveBootstrapProfile(
                    $lockedImport,
                    $candidateDefinition,
                    $policy,
                    $actor,
                );
            $profile->loadMissing('activeVersion');
            if ($lockedImport->profile === null && $profile->activeVersion !== null) {
                $lockedImport->forceFill([
                    'profile_id' => $profile->id,
                    'profile_version_id' => $profile->activeVersion->id,
                    'ai_profile_candidate_version_id' => $profile->activeVersion->id,
                ])->save();

                return $profile->activeVersion->fresh(['profile']);
            }
            $bootstrapProfile = $profile->activeVersion === null;
            $reproduction = $this->candidateReproducer->verifyOrFail(
                $candidateDefinition,
                $lockedImport,
                $canonicalDocument,
                $profile,
            );
            $version = $this->createVersion->handle(
                profile: $profile,
                definition: $candidateDefinition,
                source: 'ai_extraction',
                actor: $actor,
                parent: $profile->activeVersion,
            );
            $fixture = $this->createCurrentFixture(
                $lockedImport,
                $profile,
                $version,
                $canonicalDocument,
                $actor,
                $bootstrapProfile,
            );
            $replay = $this->replayFixtures->handle($version, true);
            $currentResult = collect($replay->results)->firstWhere('fixture_id', $fixture->id);
            if (! is_array($currentResult) || ! ($currentResult['passed'] ?? false)) {
                throw ValidationException::withMessages([
                    'profile_candidate' => 'AI profile candidate could not reproduce the current canonical import.',
                ]);
            }

            $version->forceFill([
                'test_metrics' => [
                    ...(array) ($version->test_metrics ?? []),
                    'ai_candidate_fixture_total' => $replay->total,
                    'ai_candidate_fixture_passed' => $replay->passed,
                    'ai_candidate_protected_total' => $replay->protectedTotal,
                    'ai_candidate_protected_passed' => $replay->protectedPassed,
                    'ai_candidate_current_fixture_id' => $fixture->id,
                    'ai_candidate_reproduction' => $reproduction->toArray(),
                ],
            ])->save();

            if ($replay->allPassed() && $replay->protectedPassed()) {
                $this->validateVersion->handle($version->fresh());
                $version->refresh();
            }
            $minimumSamples = max(1, min(25, (int) $policy->ai_profile_shadow_samples));
            $sampleGatePassed = $bootstrapProfile
                ? $reproduction->bootstrapMinimumMet($minimumSamples)
                : $reproduction->historicalMinimumMet($minimumSamples);
            if ($policy->ai_profile_learning_mode === 'auto_activate'
                && $version->status === PurchaseOrderImportProfileVersion::STATUS_VALIDATED
                && $sampleGatePassed) {
                $this->activateVersion->handle(
                    $version,
                    $actor,
                    $bootstrapProfile
                        ? 'AI bootstrap passed trusted source reproduction and protected fixture replay.'
                        : 'AI candidate passed protected fixtures and configured historical shadow replay.',
                );
                $version->refresh();
            }

            $activeVersionId = $version->status === PurchaseOrderImportProfileVersion::STATUS_ACTIVE
                ? $version->id
                : $profile->refresh()->active_version_id;
            $importLinks = ['ai_profile_candidate_version_id' => $version->id];
            if ($activeVersionId !== null) {
                $importLinks += [
                    'profile_id' => $profile->id,
                    'profile_version_id' => $activeVersionId,
                ];
            }
            $lockedImport->forceFill($importLinks)->save();

            return $version->fresh(['profile']);
        });
    }

    private function automationActor(PurchaseOrderAutomationPolicy $policy): User
    {
        $actor = $policy->automation_user_id ? User::query()->find($policy->automation_user_id) : null;
        if (! SupplierOrderAutomationActor::canAct($actor, 'storage.purchase_import_profile_manage')) {
            throw ValidationException::withMessages([
                'automation' => 'The managed supplier-order authority cannot manage profiles.',
            ]);
        }

        return $actor;
    }

    private function assertTrustedSource(PurchaseOrderImport $import): void
    {
        $auth = $import->trusted_auth_snapshot ?? [];
        if (! ($auth['authentication_passed'] ?? false)
            || ! ($auth['aligned'] ?? false)
            || blank($auth['authenticated_supplier_domain'] ?? null)) {
            throw ValidationException::withMessages([
                'source' => 'AI profile learning requires a trusted, aligned authenticated supplier domain.',
            ]);
        }
    }

    /** @param array<string, mixed> $definition */
    private function resolveBootstrapProfile(
        PurchaseOrderImport $import,
        array $definition,
        PurchaseOrderAutomationPolicy $policy,
        User $actor,
    ): PurchaseOrderImportProfile {
        $vendorId = (int) $import->vendor_id;
        if ($vendorId < 1) {
            throw ValidationException::withMessages([
                'profile_candidate' => 'AI profile bootstrap requires a resolved Supplier.',
            ]);
        }

        Vendor::query()
            ->where('is_supplier', true)
            ->where('is_active', true)
            ->lockForUpdate()
            ->findOrFail($vendorId);

        // A second first-time import may have resolved no profile before another
        // worker activated one. Re-run the real matcher while the Supplier row is
        // locked so overlapping scopes cannot create equal-priority profiles.
        $liveMatch = $this->profileMatcher->match((array) $import->safe_source_snapshot);
        if ($liveMatch->status === SupplierOrderProfileMatchResult::STATUS_AMBIGUOUS) {
            throw ValidationException::withMessages([
                'profile_candidate' => 'Several active Supplier profiles match this trusted source.',
            ]);
        }
        if ($liveMatch->matched()) {
            if ((int) $liveMatch->profile?->vendor_id !== $vendorId) {
                throw ValidationException::withMessages([
                    'profile_candidate' => 'The trusted source already belongs to another Supplier profile.',
                ]);
            }

            $activeProfile = PurchaseOrderImportProfile::query()
                ->with('activeVersion')
                ->lockForUpdate()
                ->findOrFail($liveMatch->profile->id);
            $activeVersion = $activeProfile->activeVersion;
            $containerScope = StableJson::checksum((array) $activeProfile->matching_scope);
            $definitionScope = StableJson::checksum((array) data_get($activeVersion?->definition, 'match', []));
            if ($activeProfile->lifecycle_state !== PurchaseOrderImportProfile::STATE_ACTIVE
                || $activeVersion?->status !== PurchaseOrderImportProfileVersion::STATUS_ACTIVE
                || (int) $activeProfile->active_version_id !== (int) $activeVersion?->id
                || (int) $activeVersion?->profile_id !== (int) $activeProfile->id
                || ! hash_equals($containerScope, $definitionScope)
                || ! $this->profileMatcher->matches(
                    $activeProfile,
                    $activeVersion,
                    (array) $import->safe_source_snapshot,
                )) {
                throw ValidationException::withMessages([
                    'profile_candidate' => 'The existing Supplier profile does not have a consistent active source scope.',
                ]);
            }

            return $activeProfile;
        }

        $scopeChecksum = StableJson::checksum((array) $definition['match']);
        $matches = PurchaseOrderImportProfile::query()
            ->with('activeVersion')
            ->where('vendor_id', $vendorId)
            ->orderBy('priority')
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->filter(fn (PurchaseOrderImportProfile $profile): bool => hash_equals(
                $scopeChecksum,
                StableJson::checksum((array) $profile->matching_scope),
            ))
            ->values();

        if ($matches->count() > 1) {
            throw ValidationException::withMessages([
                'profile_candidate' => 'Several Supplier profiles already own the same trusted source scope.',
            ]);
        }

        $existing = $matches->first();
        if ($existing !== null) {
            if ($existing->activeVersion !== null
                || ! in_array($existing->lifecycle_state, [
                    PurchaseOrderImportProfile::STATE_DRAFT,
                    PurchaseOrderImportProfile::STATE_SHADOW,
                ], true)) {
                throw ValidationException::withMessages([
                    'profile_candidate' => 'An inactive or inconsistent Supplier profile already owns this trusted source scope.',
                ]);
            }

            return $existing;
        }

        return $this->createProfileContainer($import, $definition, $policy, $actor);
    }

    /** @return array<string, mixed> */
    private function serverManagedProfileMatchScope(PurchaseOrderImport $import): array
    {
        $existing = data_get($import->profileVersion?->definition, 'match');
        if (is_array($existing) && $existing !== []) {
            return $existing;
        }

        $snapshot = (array) $import->safe_source_snapshot;
        $auth = (array) $import->trusted_auth_snapshot;
        $accountId = data_get($snapshot, 'account_id');
        $mailbox = Str::lower(trim((string) data_get($snapshot, 'mailbox', '')));
        $sender = Str::lower(trim((string) data_get($snapshot, 'from.email', '')));
        $senderDomain = str_contains($sender, '@')
            ? substr($sender, strrpos($sender, '@') + 1)
            : null;
        $authenticatedDomain = Str::lower(trim((string) ($auth['authenticated_supplier_domain'] ?? '')));
        $recipients = collect((array) ($snapshot['to'] ?? []))->map(function (mixed $address): string {
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
                ->sort()
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

    /** @param array<string, mixed> $definition */
    private function createProfileContainer(
        PurchaseOrderImport $import,
        array $definition,
        PurchaseOrderAutomationPolicy $policy,
        User $actor,
    ): PurchaseOrderImportProfile {
        $supplierName = trim((string) data_get($import->normalized_document, 'supplier.name', 'Supplier')) ?: 'Supplier';
        $authenticatedDomain = trim((string) data_get(
            $import->trusted_auth_snapshot,
            'authenticated_supplier_domain',
            '',
        ));
        $base = Str::slug($supplierName.' '.$authenticatedDomain.' supplier orders') ?: 'supplier-orders';
        $slug = $base;
        $suffix = 2;
        while (PurchaseOrderImportProfile::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return PurchaseOrderImportProfile::query()->create([
            'vendor_id' => $import->vendor_id,
            'name' => Str::limit($supplierName.' supplier orders', 255, ''),
            'slug' => $slug,
            'description' => 'AI-created declarative profile verified against trusted source evidence.',
            'lifecycle_state' => PurchaseOrderImportProfile::STATE_DRAFT,
            'priority' => 100,
            'matching_scope' => (array) $definition['match'],
            'policy_overrides' => [
                'ai_profile_learning_mode' => $policy->ai_profile_learning_mode,
                'ai_profile_shadow_samples' => $policy->ai_profile_shadow_samples,
            ],
            'health_state' => 'unknown',
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
    }

    private function createCurrentFixture(
        PurchaseOrderImport $import,
        PurchaseOrderImportProfile $profile,
        PurchaseOrderImportProfileVersion $version,
        array $document,
        User $actor,
        bool $bootstrapProfile,
    ): PurchaseOrderImportProfileFixture {
        $source = $import->safe_source_snapshot ?? [];
        $expected = $this->expectedSubset($document);

        return PurchaseOrderImportProfileFixture::query()->firstOrCreate([
            'profile_id' => $profile->id,
            'source_checksum' => StableJson::checksum($source),
        ], [
            'profile_version_id' => $version->id,
            'name' => ($bootstrapProfile ? 'AI verified bootstrap' : 'AI learning sample')
                .' for import '.$import->id,
            'fixture_type' => $bootstrapProfile ? 'ai_verified_bootstrap' : 'body',
            'is_protected' => $bootstrapProfile,
            'safe_source_snapshot' => $source,
            'expected_document' => $expected,
            'expected_checksum' => StableJson::checksum($expected),
            'created_by' => $actor->id,
        ]);
    }

    /** @return array<string, mixed> */
    private function expectedSubset(array $document): array
    {
        return [
            'schema_version' => 'storage.supplier_order.v1',
            'document_type' => 'supplier_order_confirmation',
            'external_order_number' => data_get($document, 'external_order_number'),
            'supplier' => ['name' => data_get($document, 'supplier.name')],
            'ordered_at' => data_get($document, 'ordered_at'),
            'currency' => data_get($document, 'currency'),
            'lines' => collect($document['lines'] ?? [])->map(fn (array $line): array => array_filter([
                'supplier_sku' => $line['supplier_sku'] ?? null,
                'description' => $line['description'] ?? null,
                'quantity' => is_numeric($line['quantity'] ?? null) ? (int) $line['quantity'] : null,
                'unit_price' => $this->decimal($line['unit_price'] ?? null),
                'line_total' => $this->decimal($line['line_total'] ?? null),
            ], fn (mixed $value): bool => $value !== null))->values()->all(),
            'totals' => collect($document['totals'] ?? [])->map(
                fn (mixed $value): ?string => $this->decimal($value),
            )->filter(fn (?string $value): bool => $value !== null)->all(),
        ];
    }

    private function decimal(mixed $value): ?string
    {
        if (! is_numeric($value)) {
            return null;
        }

        return rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');
    }
}
