<?php

namespace App\Modules\Storage\Actions;

use App\Modules\Storage\Models\PurchaseOrderImportProfileFixture;
use App\Modules\Storage\Models\PurchaseOrderImportProfileVersion;
use App\Modules\Storage\Support\StableJson;
use App\Modules\Storage\Support\SupplierOrderDeterministicExtractor;
use App\Modules\Storage\Support\SupplierOrderFixtureReplayResult;

class ReplaySupplierOrderProfileFixtures
{
    public function __construct(private SupplierOrderDeterministicExtractor $extractor) {}

    public function handle(
        PurchaseOrderImportProfileVersion $version,
        bool $persist = true,
    ): SupplierOrderFixtureReplayResult {
        $fixtures = PurchaseOrderImportProfileFixture::query()
            ->where('profile_id', $version->profile_id)
            ->orderByDesc('is_protected')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $passed = 0;
        $protectedTotal = 0;
        $protectedPassed = 0;
        $results = [];

        foreach ($fixtures as $fixture) {
            $isProtected = (bool) $fixture->is_protected;
            $protectedTotal += $isProtected ? 1 : 0;
            $reasonCodes = [];
            $mismatches = [];

            $source = (array) $fixture->safe_source_snapshot;
            $expected = (array) $fixture->expected_document;
            if (! hash_equals((string) $fixture->source_checksum, StableJson::checksum($source))) {
                $reasonCodes[] = 'fixture_source_checksum_mismatch';
            }
            if (! hash_equals((string) $fixture->expected_checksum, StableJson::checksum($expected))) {
                $reasonCodes[] = 'fixture_expected_checksum_mismatch';
            }

            $extraction = $this->extractor->extract($version, $source);
            if (! $extraction->valid()) {
                $reasonCodes[] = 'fixture_extraction_invalid';
            }
            if ($extraction->document !== null) {
                $mismatches = $this->subsetMismatches($expected, $extraction->document);
                if ($mismatches !== []) {
                    $reasonCodes[] = 'fixture_expected_subset_mismatch';
                }
            } else {
                $mismatches[] = '$';
            }

            $fixturePassed = $reasonCodes === [];
            $passed += $fixturePassed ? 1 : 0;
            $protectedPassed += $fixturePassed && $isProtected ? 1 : 0;

            $safeResult = [
                'fixture_id' => (int) $fixture->id,
                'name' => (string) $fixture->name,
                'protected' => $isProtected,
                'passed' => $fixturePassed,
                'reason_codes' => array_values(array_unique($reasonCodes)),
                'mismatch_paths' => array_slice($mismatches, 0, 100),
                'extraction_error_codes' => collect($extraction->errors)->pluck('code')->unique()->values()->all(),
                'extraction_warning_codes' => collect($extraction->warnings)->pluck('code')->unique()->values()->all(),
                'actual_document_checksum' => $extraction->document !== null
                    ? StableJson::checksum($extraction->document)
                    : null,
            ];
            $results[] = $safeResult;

            if ($persist) {
                $fixture->forceFill([
                    'last_result' => $fixturePassed ? 'passed' : 'failed',
                    'last_result_details' => $safeResult,
                    'last_tested_at' => now(),
                ])->save();
            }
        }

        $total = $fixtures->count();

        return new SupplierOrderFixtureReplayResult(
            total: $total,
            passed: $passed,
            failed: $total - $passed,
            protectedTotal: $protectedTotal,
            protectedPassed: $protectedPassed,
            results: $results,
        );
    }

    /**
     * Compare a fixture's expected canonical subset without allowing profile output to omit expected facts.
     *
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $actual
     * @return list<string>
     */
    private function subsetMismatches(array $expected, array $actual, string $path = '$'): array
    {
        $mismatches = [];
        foreach ($expected as $key => $expectedValue) {
            $childPath = $path.'.'.(string) $key;
            if (! array_key_exists($key, $actual)) {
                $mismatches[] = $childPath;

                continue;
            }

            $actualValue = $actual[$key];
            if (is_array($expectedValue)) {
                if (! is_array($actualValue)) {
                    $mismatches[] = $childPath;

                    continue;
                }
                $mismatches = [
                    ...$mismatches,
                    ...$this->subsetMismatches($expectedValue, $actualValue, $childPath),
                ];

                continue;
            }

            if ($actualValue !== $expectedValue) {
                $mismatches[] = $childPath;
            }
        }

        return $mismatches;
    }
}
