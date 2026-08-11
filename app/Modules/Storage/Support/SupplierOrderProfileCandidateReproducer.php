<?php

namespace App\Modules\Storage\Support;

use App\Modules\Storage\Models\PurchaseOrderImport;
use App\Modules\Storage\Models\PurchaseOrderImportProfile;
use App\Modules\Storage\Models\PurchaseOrderImportProfileFixture;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/**
 * Proves that an AI-proposed declarative profile reproduces known commercial facts.
 *
 * This runs before a candidate version is stored, validated, or activated. Comparisons
 * intentionally report only reason codes and paths so supplier values are not copied
 * into exception or audit messages.
 */
class SupplierOrderProfileCandidateReproducer
{
    private const HEADER_PATHS = [
        'external_order_number',
        'supplier.name',
        'currency',
    ];

    private const LINE_PATHS = [
        'supplier_sku',
        'quantity',
        'unit_price',
        'line_total',
    ];

    private const TOTAL_PATHS = [
        'goods_subtotal',
        'freight',
        'discount',
        'other_charges',
        'tax_total',
        'total_ex_tax',
        'total_inc_tax',
    ];

    public function __construct(
        private readonly SupplierOrderDeterministicExtractor $extractor,
        private readonly SupplierOrderSourceIntegrity $sourceIntegrity,
    ) {}

    /**
     * The caller must execute this method inside the transaction that owns the current
     * import lock. Historical rows and protected fixtures are locked here before use.
     *
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $currentDocument
     */
    public function verifyOrFail(
        array $definition,
        PurchaseOrderImport $currentImport,
        array $currentDocument,
        ?PurchaseOrderImportProfile $profile,
    ): SupplierOrderProfileCandidateReproductionResult {
        $this->sourceIntegrity->validateOrFail(
            (array) $currentImport->safe_source_snapshot,
            (string) $currentImport->source_fingerprint,
            (array) $currentImport->trusted_auth_snapshot,
        );

        $errors = $this->reproductionErrors(
            $definition,
            (array) $currentImport->safe_source_snapshot,
            $currentDocument,
            'current',
            false,
        );
        $protectedCount = 0;
        $historicalCount = 0;

        if ($profile !== null) {
            $fixtures = PurchaseOrderImportProfileFixture::query()
                ->where('profile_id', $profile->id)
                ->where('is_protected', true)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            foreach ($fixtures as $fixture) {
                $protectedCount++;
                $source = (array) $fixture->safe_source_snapshot;
                $expected = (array) $fixture->expected_document;
                if (! hash_equals((string) $fixture->source_checksum, StableJson::checksum($source))) {
                    $errors[] = 'protected_fixture:source_checksum_mismatch';

                    continue;
                }
                if (! hash_equals((string) $fixture->expected_checksum, StableJson::checksum($expected))) {
                    $errors[] = 'protected_fixture:expected_checksum_mismatch';

                    continue;
                }
                $errors = [
                    ...$errors,
                    ...$this->reproductionErrors(
                        $definition,
                        $source,
                        $expected,
                        'protected_fixture',
                        true,
                    ),
                ];
            }

            $samples = PurchaseOrderImport::query()
                ->where('profile_id', $profile->id)
                ->whereKeyNot($currentImport->id)
                ->whereNotNull('normalized_document')
                ->whereIn('status', [
                    PurchaseOrderImport::STATUS_IMPORTED,
                    PurchaseOrderImport::STATUS_NEEDS_ATTENTION,
                ])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->filter(fn (PurchaseOrderImport $sample): bool => data_get(
                    $sample->validation_results,
                    'valid',
                ) === true);

            foreach ($samples as $sample) {
                $historicalCount++;
                $this->sourceIntegrity->validateOrFail(
                    (array) $sample->safe_source_snapshot,
                    (string) $sample->source_fingerprint,
                    (array) $sample->trusted_auth_snapshot,
                );
                $errors = [
                    ...$errors,
                    ...$this->reproductionErrors(
                        $definition,
                        (array) $sample->safe_source_snapshot,
                        (array) $sample->normalized_document,
                        'historical',
                        false,
                    ),
                ];
            }
        }

        $errors = array_values(array_unique($errors));
        if ($errors !== []) {
            throw ValidationException::withMessages([
                'profile_candidate' => array_slice($errors, 0, 100),
            ]);
        }

        return new SupplierOrderProfileCandidateReproductionResult(
            currentSamples: 1,
            protectedFixtureSamples: $protectedCount,
            historicalSamples: $historicalCount,
        );
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $expectedDocument
     * @return list<string>
     */
    private function reproductionErrors(
        array $definition,
        array $source,
        array $expectedDocument,
        string $scope,
        bool $expectedIsSubset,
    ): array {
        $extraction = $this->extractor->extractDefinition($definition, $source);
        if (! $extraction->valid() || $extraction->document === null) {
            $codes = collect($extraction->errors)
                ->pluck('code')
                ->filter(fn (mixed $code): bool => is_string($code) && $code !== '')
                ->unique()
                ->take(20)
                ->map(fn (string $code): string => $scope.':extraction_invalid:'.$code)
                ->values()
                ->all();

            return $codes !== [] ? $codes : [$scope.':extraction_invalid'];
        }

        $expected = $this->criticalProjection($expectedDocument, $expectedIsSubset);
        $actual = $this->criticalProjection($extraction->document, false);
        $paths = $expectedIsSubset
            ? $this->subsetMismatches($expected, $actual)
            : $this->exactMismatches($expected, $actual);

        return collect($paths)
            ->take(100)
            ->map(fn (string $path): string => $scope.':critical_mismatch:'.$path)
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    private function criticalProjection(array $document, bool $partial): array
    {
        $projection = [];
        foreach (self::HEADER_PATHS as $path) {
            if ($partial && ! Arr::has($document, $path)) {
                continue;
            }
            data_set($projection, $path, $this->normalize($path, data_get($document, $path)));
        }

        if (! $partial || array_key_exists('lines', $document)) {
            $projection['lines'] = collect($document['lines'] ?? [])->map(function (mixed $line) use ($partial): array {
                $line = is_array($line) ? $line : [];
                $projected = [];
                foreach (self::LINE_PATHS as $path) {
                    if ($partial && ! array_key_exists($path, $line)) {
                        continue;
                    }
                    $projected[$path] = $this->normalize('lines.'.$path, $line[$path] ?? null);
                }

                return $projected;
            })->values()->all();
        }

        if (! $partial || array_key_exists('totals', $document)) {
            foreach (self::TOTAL_PATHS as $path) {
                if ($partial && ! Arr::has($document, 'totals.'.$path)) {
                    continue;
                }
                data_set(
                    $projection,
                    'totals.'.$path,
                    $this->normalize('totals.'.$path, data_get($document, 'totals.'.$path)),
                );
            }
        }

        return $projection;
    }

    private function normalize(string $path, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (str_contains($path, 'quantity')
            || str_contains($path, 'unit_price')
            || str_contains($path, 'line_total')
            || str_starts_with($path, 'totals.')) {
            return is_numeric($value)
                ? rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.')
                : trim((string) $value);
        }
        if ($path === 'currency' || $path === 'lines.currency' || str_contains($path, 'supplier_sku')) {
            return mb_strtoupper(trim((string) $value));
        }

        return mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $value)) ?? trim((string) $value));
    }

    /** @return list<string> */
    private function exactMismatches(mixed $expected, mixed $actual, string $path = '$'): array
    {
        if (! is_array($expected) || ! is_array($actual)) {
            return $expected === $actual ? [] : [$path];
        }
        $keys = array_values(array_unique([...array_keys($expected), ...array_keys($actual)]));
        $mismatches = [];
        foreach ($keys as $key) {
            $child = $path.'.'.(string) $key;
            if (! array_key_exists($key, $expected) || ! array_key_exists($key, $actual)) {
                $mismatches[] = $child;

                continue;
            }
            $mismatches = [...$mismatches, ...$this->exactMismatches($expected[$key], $actual[$key], $child)];
        }

        return $mismatches;
    }

    /** @return list<string> */
    private function subsetMismatches(mixed $expected, mixed $actual, string $path = '$'): array
    {
        if (! is_array($expected) || ! is_array($actual)) {
            return $expected === $actual ? [] : [$path];
        }
        $mismatches = [];
        foreach ($expected as $key => $value) {
            $child = $path.'.'.(string) $key;
            if (! array_key_exists($key, $actual)) {
                $mismatches[] = $child;

                continue;
            }
            $mismatches = [...$mismatches, ...$this->subsetMismatches($value, $actual[$key], $child)];
        }

        return $mismatches;
    }
}
