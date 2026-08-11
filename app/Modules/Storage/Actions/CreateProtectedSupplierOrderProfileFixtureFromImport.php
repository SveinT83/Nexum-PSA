<?php

namespace App\Modules\Storage\Actions;

use App\Models\Core\User;
use App\Modules\Storage\Models\PurchaseOrderAutomationPolicy;
use App\Modules\Storage\Models\PurchaseOrderImport;
use App\Modules\Storage\Models\PurchaseOrderImportProfile;
use App\Modules\Storage\Models\PurchaseOrderImportProfileFixture;
use App\Modules\Storage\Models\PurchaseOrderImportProfileVersion;
use App\Modules\Storage\Support\StableJson;
use App\Modules\Storage\Support\SupplierOrderCanonicalValidator;
use App\Modules\Storage\Support\SupplierOrderFixtureReplayResult;
use App\Modules\Storage\Support\SupplierOrderSourceSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateProtectedSupplierOrderProfileFixtureFromImport
{
    public function __construct(
        private readonly ResolveEffectivePurchaseOrderAutomationPolicy $resolveEffectivePolicy,
        private readonly SupplierOrderCanonicalValidator $canonicalValidator,
        private readonly SupplierOrderSourceSnapshot $sourceSnapshot,
        private readonly ReplaySupplierOrderProfileFixtures $replayFixtures,
    ) {}

    /**
     * Create one immutable protected fixture from a reviewed import and replay the selected version.
     *
     * @return array{
     *     fixture: PurchaseOrderImportProfileFixture,
     *     replay: SupplierOrderFixtureReplayResult,
     *     created: bool
     * }
     */
    public function handle(
        PurchaseOrderImportProfile $profile,
        PurchaseOrderImportProfileVersion $version,
        PurchaseOrderImport $import,
        string $name,
        User $actor,
    ): array {
        $this->authorize($actor);

        return DB::transaction(function () use ($profile, $version, $import, $name, $actor): array {
            $lockedProfile = PurchaseOrderImportProfile::query()->lockForUpdate()->findOrFail($profile->id);
            $lockedVersion = PurchaseOrderImportProfileVersion::query()->lockForUpdate()->findOrFail($version->id);
            $lockedImport = PurchaseOrderImport::query()
                ->with(['policyRevision', 'profile', 'profileVersion'])
                ->lockForUpdate()
                ->findOrFail($import->id);
            $this->ensureOwnership($lockedProfile, $lockedVersion, $lockedImport);

            $fixtureName = trim($name);
            if ($fixtureName === '' || mb_strlen($fixtureName) > 255) {
                throw ValidationException::withMessages([
                    'fixture_name' => 'Fixture name is required and limited to 255 characters.',
                ]);
            }

            $source = $lockedImport->safe_source_snapshot;
            if (! is_array($source)
                || ! is_string($lockedImport->source_fingerprint)
                || ! hash_equals($lockedImport->source_fingerprint, StableJson::checksum($source))) {
                throw ValidationException::withMessages([
                    'purchase_order_import_id' => 'The immutable source fingerprint is inconsistent.',
                ]);
            }

            $document = $lockedImport->normalized_document;
            if (! is_array($document) || data_get($lockedImport->validation_results, 'valid') !== true) {
                throw ValidationException::withMessages([
                    'purchase_order_import_id' => 'Select an import with a reviewed, validated canonical document.',
                ]);
            }
            $policy = $this->pinnedEffectivePolicy($lockedImport);
            $validation = $lockedImport->extraction_method === 'ai'
                ? $this->canonicalValidator->validateAiDocument(
                    $document,
                    $policy,
                    $source,
                    $lockedImport->source_fingerprint,
                )
                : $this->canonicalValidator->validate(
                    $document,
                    $policy,
                    $source,
                );
            if (! $validation->valid()) {
                throw ValidationException::withMessages([
                    'purchase_order_import_id' => 'The selected import no longer passes its pinned canonical policy.',
                ]);
            }

            $safeSource = $this->sourceSnapshot->sanitizeStoredSnapshot(
                $source,
                (array) ($lockedImport->trusted_auth_snapshot ?? []),
            );
            $safeSource['fixture_provenance'] = [
                'method' => 'reviewed_import',
                'source_import_id' => $lockedImport->id,
                'immutable_source_fingerprint' => $lockedImport->source_fingerprint,
            ];
            $expected = $this->expectedSubset($document, (int) $policy->max_lines);
            $sourceChecksum = StableJson::checksum($safeSource);
            $expectedChecksum = StableJson::checksum($expected);

            $fixture = PurchaseOrderImportProfileFixture::query()
                ->where('profile_id', $lockedProfile->id)
                ->where('source_checksum', $sourceChecksum)
                ->lockForUpdate()
                ->first();
            $created = false;
            if ($fixture) {
                if (! hash_equals((string) $fixture->expected_checksum, $expectedChecksum)
                    || ! hash_equals((string) $fixture->source_checksum, $sourceChecksum)) {
                    throw ValidationException::withMessages([
                        'purchase_order_import_id' => 'This source already has a different protected expectation.',
                    ]);
                }
            } else {
                $fixture = PurchaseOrderImportProfileFixture::query()->create([
                    'profile_id' => $lockedProfile->id,
                    'profile_version_id' => $lockedVersion->id,
                    'name' => $fixtureName,
                    'fixture_type' => 'reviewed_import',
                    'is_protected' => true,
                    'safe_source_snapshot' => $safeSource,
                    'expected_document' => $expected,
                    'source_checksum' => $sourceChecksum,
                    'expected_checksum' => $expectedChecksum,
                    'created_by' => $actor->id,
                ]);
                $created = true;
            }

            $replay = $this->replayFixtures->handle($lockedVersion, true);

            return compact('fixture', 'replay', 'created');
        });
    }

    private function authorize(User $actor): void
    {
        if (! $actor->isActive() || ! $actor->can('storage.purchase_import_profile_manage')) {
            throw ValidationException::withMessages([
                'fixture' => 'Protected fixture creation requires supplier-profile management permission.',
            ]);
        }
    }

    private function ensureOwnership(
        PurchaseOrderImportProfile $profile,
        PurchaseOrderImportProfileVersion $version,
        PurchaseOrderImport $import,
    ): void {
        if ((int) $version->profile_id !== (int) $profile->id) {
            throw ValidationException::withMessages([
                'profile_version_id' => 'The selected version does not belong to this profile.',
            ]);
        }
        if ((int) $import->profile_id !== (int) $profile->id) {
            throw ValidationException::withMessages([
                'purchase_order_import_id' => 'The selected import does not belong to this profile.',
            ]);
        }
        if (! in_array($import->status, [
            PurchaseOrderImport::STATUS_NEEDS_ATTENTION,
            PurchaseOrderImport::STATUS_IMPORTED,
        ], true)) {
            throw ValidationException::withMessages([
                'purchase_order_import_id' => 'Select a reviewed or imported supplier-order import.',
            ]);
        }
    }

    private function pinnedEffectivePolicy(PurchaseOrderImport $import): PurchaseOrderAutomationPolicy
    {
        if (! is_array($import->effective_policy_snapshot) || ! $import->policyRevision) {
            throw ValidationException::withMessages([
                'purchase_order_import_id' => 'The selected import has no pinned effective policy.',
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
     * Keep only critical canonical facts required to detect profile regressions.
     *
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    private function expectedSubset(array $document, int $maxLines): array
    {
        $lines = collect($document['lines'] ?? [])
            ->take(max(1, min(500, $maxLines)))
            ->map(function (mixed $line): array {
                $line = is_array($line) ? $line : [];

                return collect([
                    'supplier_sku' => $this->boundedText($line['supplier_sku'] ?? null, 255),
                    'description' => $this->boundedText($line['description'] ?? null, 2000),
                    'quantity' => $line['quantity'] ?? null,
                    'unit_price' => $line['unit_price'] ?? null,
                    'line_total' => $line['line_total'] ?? null,
                    'tax_rate' => $line['tax_rate'] ?? null,
                    'currency' => $this->boundedText($line['currency'] ?? null, 3),
                ])->reject(fn (mixed $value): bool => $value === null || $value === '')->all();
            })
            ->values()
            ->all();
        $totals = collect((array) ($document['totals'] ?? []))
            ->only([
                'goods_subtotal',
                'freight',
                'discount',
                'other_charges',
                'total_ex_tax',
            ])
            ->reject(fn (mixed $value): bool => $value === null || $value === '')
            ->all();

        return [
            'schema_version' => 'storage.supplier_order.v1',
            'document_type' => 'supplier_order_confirmation',
            'supplier' => [
                'name' => $this->boundedText(data_get($document, 'supplier.name'), 500),
            ],
            'external_order_number' => $this->boundedText($document['external_order_number'] ?? null, 255),
            'ordered_at' => $this->boundedText($document['ordered_at'] ?? null, 100),
            'currency' => strtoupper((string) $this->boundedText($document['currency'] ?? null, 3)),
            'lines' => $lines,
            'totals' => $totals,
        ];
    }

    private function boundedText(mixed $value, int $limit): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : Str::limit($value, $limit, '');
    }
}
