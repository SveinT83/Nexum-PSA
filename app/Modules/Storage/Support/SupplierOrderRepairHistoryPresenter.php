<?php

namespace App\Modules\Storage\Support;

use App\Models\Core\User;
use App\Modules\Storage\Models\PurchaseOrderImport;
use App\Modules\Storage\Models\PurchaseOrderImportRepair;
use Illuminate\Support\Str;

final class SupplierOrderRepairHistoryPresenter
{
    private const MAX_LINES = 500;

    private const MAX_DIFF_ROWS = 750;

    private const MAX_EVIDENCE_ROWS = 200;

    /** @return list<array<string, mixed>> */
    public function present(PurchaseOrderImport $import, User $viewer): array
    {
        $repairs = $import->repairs->sortBy('sequence')->values();
        $currentChecksum = is_array($import->normalized_document)
            ? StableJson::checksum($import->normalized_document)
            : null;
        $latestSuccessfulSequence = $repairs
            ->filter(fn (PurchaseOrderImportRepair $repair): bool => in_array($repair->status, [
                PurchaseOrderImportRepair::STATUS_READY_FOR_REPROCESS,
                PurchaseOrderImportRepair::STATUS_APPLIED_PRE_HISTORY_PURCHASE_ORDER,
            ], true))
            ->max('sequence');
        $previousSuccessfulRepair = null;
        $history = [];

        foreach ($repairs as $repair) {
            $decision = (array) ($repair->decision_summary ?? []);
            $storedBefore = $decision['before_document'] ?? null;
            $before = null;
            $beforeSource = 'Exact before snapshot unavailable for this legacy repair.';
            $beforeExact = false;

            if (is_array($storedBefore)) {
                $before = $this->projectDocument($storedBefore);
                $beforeSource = 'Exact bounded snapshot captured before the repair.';
                $beforeExact = true;
            } elseif ($previousSuccessfulRepair instanceof PurchaseOrderImportRepair) {
                $before = $this->projectDocument((array) $previousSuccessfulRepair->corrected_document);
                $beforeSource = 'Previous repair projection used as a legacy fallback; not an exact captured before snapshot.';
            }

            $after = $this->projectDocument((array) $repair->corrected_document);
            [$diff, $diffTruncated] = $before === null
                ? [[], false]
                : $this->diff($before, $after);
            [$evidence, $evidenceTruncated] = $this->evidence((array) $repair->corrected_document);
            $outcome = $this->outcome(
                $repair,
                $currentChecksum,
                is_numeric($latestSuccessfulSequence) ? (int) $latestSuccessfulSequence : null,
                $decision,
            );
            $candidate = $repair->profileCandidateVersion;
            $candidateProfile = $candidate?->profile;
            $validation = (array) ($repair->validation_results ?? []);

            $history[] = [
                'id' => (int) $repair->id,
                'sequence' => (int) $repair->sequence,
                'method' => (string) ($decision['method'] ?? ($repair->ai_execution_uuid ? 'ai' : 'manual')),
                'persisted_status' => (string) $repair->status,
                'outcome' => $outcome['key'],
                'outcome_label' => $outcome['label'],
                'outcome_badge' => $outcome['badge'],
                'outcome_reason' => $outcome['reason'],
                'reason' => $this->boundedScalar($decision['reason'] ?? null, 500),
                'diagnosis' => $this->boundedScalar($decision['diagnosis'] ?? null, 500),
                'change_summary' => collect(is_array($decision['change_summary'] ?? null) ? $decision['change_summary'] : [])
                    ->filter(fn (mixed $value): bool => is_scalar($value))
                    ->take(20)
                    ->map(fn (mixed $value): string => Str::limit(trim((string) $value), 500, ''))
                    ->filter()
                    ->values()
                    ->all(),
                'blocked_reason' => $this->boundedScalar($decision['blocked_reason'] ?? null, 255),
                'source_fingerprint' => $this->boundedScalar($decision['source_fingerprint'] ?? null, 64),
                'original_document_checksum' => $this->boundedScalar($repair->original_document_checksum, 64),
                'corrected_document_checksum' => $this->boundedScalar($repair->corrected_document_checksum, 64),
                'ai_execution_uuid' => $this->boundedScalar($repair->ai_execution_uuid, 64),
                'actor_name' => $repair->actor?->name ?: 'System',
                'recorded_at' => $repair->created_at,
                'before_available' => $before !== null,
                'before_exact' => $beforeExact,
                'before_source' => $beforeSource,
                'diff' => $diff,
                'diff_truncated' => $diffTruncated,
                'evidence' => $evidence,
                'evidence_truncated' => $evidenceTruncated,
                'validation_recorded' => array_key_exists('valid', $validation),
                'validation_valid' => array_key_exists('valid', $validation) ? (bool) $validation['valid'] : null,
                'validation_errors' => $this->validationIssues($validation['errors'] ?? []),
                'validation_warnings' => $this->validationIssues($validation['warnings'] ?? []),
                'confidence_dimensions' => $this->confidenceDimensions($validation['confidence_dimensions'] ?? []),
                'ai_governance' => $this->aiGovernance($decision),
                'candidate_reproduction' => $this->candidateReproduction($decision['candidate_reproduction'] ?? null),
                'profile_candidate' => $candidate && $candidateProfile ? [
                    'id' => (int) $candidate->id,
                    'profile_id' => (int) $candidateProfile->id,
                    'profile_name' => (string) $candidateProfile->name,
                    'version_number' => (int) $candidate->version_number,
                    'status' => (string) $candidate->status,
                    'can_open' => $viewer->can('storage.purchase_import_profile_manage')
                        && $viewer->hasRole('Admin'),
                ] : null,
                'purchase_order_id' => $import->purchase_order_id ? (int) $import->purchase_order_id : null,
                'can_open_purchase_order' => $import->purchase_order_id !== null
                    && $viewer->can('storage.purchase_view'),
                'can_retry' => $outcome['key'] === 'applied'
                    && $import->purchase_order_id === null
                    && in_array($import->status, [
                        PurchaseOrderImport::STATUS_NEEDS_ATTENTION,
                        PurchaseOrderImport::STATUS_FAILED,
                        PurchaseOrderImport::STATUS_RETRY_SCHEDULED,
                    ], true)
                    && $viewer->can('storage.purchase_import_execute'),
            ];

            if (in_array($repair->status, [
                PurchaseOrderImportRepair::STATUS_READY_FOR_REPROCESS,
                PurchaseOrderImportRepair::STATUS_APPLIED_PRE_HISTORY_PURCHASE_ORDER,
            ], true)) {
                $previousSuccessfulRepair = $repair;
            }
        }

        return $history;
    }

    /**
     * Derive the operational outcome without mutating the immutable repair row.
     *
     * @return array{key: string, label: string, badge: string, reason: string}
     */
    private function outcome(
        PurchaseOrderImportRepair $repair,
        ?string $currentChecksum,
        ?int $latestSuccessfulSequence,
        array $decision,
    ): array {
        if (in_array($repair->status, [
            PurchaseOrderImportRepair::STATUS_PROPOSAL_ONLY_LOCKED_PURCHASE_ORDER,
            PurchaseOrderImportRepair::STATUS_PROPOSAL_ONLY_STATE_CHANGED,
        ], true) || filled($decision['blocked_reason'] ?? null)) {
            $reason = (string) ($decision['blocked_reason'] ?? $repair->status);

            return [
                'key' => 'blocked',
                'label' => 'Blocked',
                'badge' => 'text-bg-warning',
                'reason' => 'The proposal was retained without applying it: '
                    .str($reason)->replace('_', ' ')->lower().'.',
            ];
        }

        if (in_array($repair->status, [
            PurchaseOrderImportRepair::STATUS_READY_FOR_REPROCESS,
            PurchaseOrderImportRepair::STATUS_APPLIED_PRE_HISTORY_PURCHASE_ORDER,
        ], true)) {
            if ($latestSuccessfulSequence !== null && $repair->sequence !== $latestSuccessfulSequence) {
                return [
                    'key' => 'superseded',
                    'label' => 'Superseded',
                    'badge' => 'text-bg-secondary',
                    'reason' => 'A newer successful repair replaced this corrected document.',
                ];
            }

            $matchesCurrent = $currentChecksum !== null
                && is_string($repair->corrected_document_checksum)
                && hash_equals($currentChecksum, $repair->corrected_document_checksum);
            if ($matchesCurrent
                || $repair->status === PurchaseOrderImportRepair::STATUS_APPLIED_PRE_HISTORY_PURCHASE_ORDER) {
                return [
                    'key' => 'applied',
                    'label' => 'Applied',
                    'badge' => 'text-bg-success',
                    'reason' => $repair->status === PurchaseOrderImportRepair::STATUS_APPLIED_PRE_HISTORY_PURCHASE_ORDER
                        ? 'The bounded correction was applied to the pre-history Purchase Order and import.'
                        : 'The import currently contains this corrected document.',
                ];
            }

            return [
                'key' => 'superseded',
                'label' => 'Superseded',
                'badge' => 'text-bg-secondary',
                'reason' => 'The import no longer contains this corrected document.',
            ];
        }

        return [
            'key' => 'proposal',
            'label' => 'Proposal',
            'badge' => 'text-bg-light border',
            'reason' => 'No supported apply or block state is recorded for this legacy repair.',
        ];
    }

    /** @return array<string, mixed> */
    private function projectDocument(array $document): array
    {
        return [
            'supplier' => [
                'name' => $this->boundedScalar(data_get($document, 'supplier.name'), 255),
            ],
            'external_order_number' => $this->boundedScalar($document['external_order_number'] ?? null, 255),
            'ordered_at' => $this->boundedScalar($document['ordered_at'] ?? null, 64),
            'currency' => $this->boundedScalar($document['currency'] ?? null, 8),
            'delivery' => [
                'method' => $this->boundedScalar(data_get($document, 'delivery.method'), 255),
                'expected_at' => $this->boundedScalar(data_get($document, 'delivery.expected_at'), 64),
            ],
            'lines' => collect($document['lines'] ?? [])
                ->filter(fn (mixed $line): bool => is_array($line))
                ->take(self::MAX_LINES)
                ->values()
                ->map(fn (array $line, int $index): array => [
                    'position' => $index + 1,
                    'supplier_sku' => $this->boundedScalar($line['supplier_sku'] ?? null, 255),
                    'description' => $this->boundedScalar($line['description'] ?? null, 500),
                    'quantity' => $this->boundedScalar($line['quantity'] ?? null, 64),
                    'unit_price' => $this->boundedScalar($line['unit_price'] ?? null, 64),
                    'line_total' => $this->boundedScalar($line['line_total'] ?? null, 64),
                    'tax_rate' => $this->boundedScalar($line['tax_rate'] ?? null, 64),
                    'currency' => $this->boundedScalar($line['currency'] ?? null, 8),
                ])
                ->all(),
            'totals' => collect((array) ($document['totals'] ?? []))
                ->only([
                    'goods_subtotal',
                    'freight',
                    'discount',
                    'other_charges',
                    'tax_total',
                    'total_ex_tax',
                    'total_inc_tax',
                ])
                ->map(fn (mixed $value): ?string => $this->boundedScalar($value, 64))
                ->all(),
        ];
    }

    /** @return array{0: list<array{field: string, before: string, after: string}>, 1: bool} */
    private function diff(array $before, array $after): array
    {
        $beforeFlat = $this->flatten($before);
        $afterFlat = $this->flatten($after);
        $paths = array_values(array_unique([...array_keys($beforeFlat), ...array_keys($afterFlat)]));
        $rows = [];
        $truncated = false;

        foreach ($paths as $path) {
            $beforeValue = $beforeFlat[$path] ?? null;
            $afterValue = $afterFlat[$path] ?? null;
            if ($beforeValue === $afterValue) {
                continue;
            }
            if (count($rows) >= self::MAX_DIFF_ROWS) {
                $truncated = true;

                break;
            }
            $rows[] = [
                'field' => $this->fieldLabel($path),
                'before' => $this->displayValue($beforeValue),
                'after' => $this->displayValue($afterValue),
            ];
        }

        return [$rows, $truncated];
    }

    /** @return array<string, mixed> */
    private function flatten(array $document): array
    {
        $flat = [];
        foreach ([
            'supplier.name',
            'external_order_number',
            'ordered_at',
            'currency',
            'delivery.method',
            'delivery.expected_at',
            'totals.goods_subtotal',
            'totals.freight',
            'totals.discount',
            'totals.other_charges',
            'totals.tax_total',
            'totals.total_ex_tax',
            'totals.total_inc_tax',
        ] as $path) {
            $flat[$path] = data_get($document, $path);
        }
        foreach ((array) ($document['lines'] ?? []) as $index => $line) {
            foreach (['supplier_sku', 'description', 'quantity', 'unit_price', 'line_total', 'tax_rate', 'currency'] as $field) {
                $flat['lines.'.($index + 1).'.'.$field] = is_array($line) ? ($line[$field] ?? null) : null;
            }
        }

        return $flat;
    }

    private function fieldLabel(string $path): string
    {
        if (preg_match('/^lines\.(\d+)\.(.+)$/', $path, $matches) === 1) {
            return 'Line '.$matches[1].' / '.str($matches[2])->replace('_', ' ')->title();
        }

        return str($path)->replace('.', ' / ')->replace('_', ' ')->title()->toString();
    }

    private function displayValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        return Str::limit((string) $value, 500, '');
    }

    /** @return array{0: list<array<string, string|null>>, 1: bool} */
    private function evidence(array $document): array
    {
        $rows = [];
        $found = 0;
        $this->collectEvidence((array) ($document['evidence'] ?? []), 'document', $rows, $found);
        foreach (collect($document['lines'] ?? [])->take(self::MAX_LINES)->values() as $index => $line) {
            if (is_array($line)) {
                $this->collectEvidence(
                    (array) ($line['evidence'] ?? []),
                    'line '.($index + 1),
                    $rows,
                    $found,
                );
            }
        }

        return [$rows, $found > self::MAX_EVIDENCE_ROWS];
    }

    /** @param list<array<string, string|null>> $rows */
    private function collectEvidence(mixed $value, string $path, array &$rows, int &$found): void
    {
        if (! is_array($value)) {
            return;
        }
        $isAnchor = array_key_exists('block_id', $value)
            || array_key_exists('provenance', $value)
            || array_key_exists('source_fingerprint', $value);
        if ($isAnchor) {
            $found++;
            if (count($rows) < self::MAX_EVIDENCE_ROWS) {
                $locator = collect([
                    $this->boundedScalar($value['block_id'] ?? null, 255),
                    $this->boundedScalar($value['row_id'] ?? null, 255),
                    $this->boundedScalar($value['column'] ?? null, 255),
                ])->filter()->implode(' / ');
                $rows[] = [
                    'field' => Str::limit($path, 255, ''),
                    'source' => $this->boundedScalar(
                        $value['source'] ?? $value['provenance'] ?? 'source_evidence',
                        80,
                    ),
                    'locator' => $locator !== '' ? $locator : null,
                    'quote' => $this->boundedScalar($value['quote'] ?? null, 240),
                    'source_fingerprint' => $this->boundedScalar($value['source_fingerprint'] ?? null, 64),
                ];
            }

            return;
        }

        foreach ($value as $key => $nested) {
            $this->collectEvidence($nested, $path.'.'.Str::limit((string) $key, 80, ''), $rows, $found);
        }
    }

    /** @return list<array{code: string, path: string, message: string}> */
    private function validationIssues(mixed $issues): array
    {
        return collect(is_array($issues) ? $issues : [])
            ->filter(fn (mixed $issue): bool => is_array($issue))
            ->take(50)
            ->map(fn (array $issue): array => [
                'code' => $this->boundedScalar($issue['code'] ?? 'validation_issue', 120) ?? 'validation_issue',
                'path' => $this->boundedScalar($issue['path'] ?? 'document', 255) ?? 'document',
                'message' => $this->boundedScalar($issue['message'] ?? '', 500) ?? '',
            ])
            ->values()
            ->all();
    }

    /** @return array<string, string> */
    private function confidenceDimensions(mixed $dimensions): array
    {
        return collect(is_array($dimensions) ? $dimensions : [])
            ->filter(fn (mixed $value, mixed $key): bool => is_string($key) && is_scalar($value))
            ->take(20)
            ->mapWithKeys(fn (mixed $value, string $key): array => [
                Str::limit($key, 120, '') => $this->displayValue($value),
            ])
            ->all();
    }

    /** @return array<string, string|null>|null */
    private function aiGovernance(array $decision): ?array
    {
        $budget = is_array($decision['ai_budget'] ?? null) ? $decision['ai_budget'] : [];
        $primary = is_array($decision['primary_execution'] ?? null) ? $decision['primary_execution'] : [];
        $consensusPresent = array_key_exists('consensus', $decision);
        $consensus = is_array($decision['consensus'] ?? null) ? $decision['consensus'] : [];
        if ($budget === [] && $primary === [] && ! $consensusPresent) {
            return null;
        }

        return [
            'budget_limit' => $this->boundedScalar($budget['limit'] ?? null, 64),
            'budget_currency' => $this->boundedScalar($budget['currency'] ?? null, 8),
            'budget_spent' => $this->boundedScalar($budget['spent'] ?? null, 64),
            'budget_remaining' => $this->boundedScalar($budget['remaining'] ?? null, 64),
            'budget_reason_code' => $this->boundedScalar($budget['reason_code'] ?? null, 120),
            'primary_workload' => $this->boundedScalar(
                $primary['workload_slug'] ?? $primary['workload_id'] ?? null,
                191,
            ),
            'primary_execution_id' => $this->boundedScalar($primary['execution_id'] ?? null, 64),
            'primary_provider_id' => $this->boundedScalar($primary['provider_id'] ?? null, 64),
            'primary_agent_id' => $this->boundedScalar($primary['agent_id'] ?? null, 64),
            'primary_access_event_id' => $this->boundedScalar($primary['access_event_id'] ?? null, 64),
            'primary_cost' => $this->boundedScalar($primary['provider_reported_cost'] ?? null, 64),
            'primary_cost_currency' => $this->boundedScalar($primary['cost_currency'] ?? null, 8),
            'consensus_status' => $this->boundedScalar(
                $consensus['status'] ?? ($consensusPresent ? 'not_requested' : null),
                80,
            ),
            'consensus_workload' => $this->boundedScalar(
                $consensus['workload_slug'] ?? $consensus['workload_id'] ?? null,
                191,
            ),
            'consensus_execution_id' => $this->boundedScalar($consensus['execution_id'] ?? null, 64),
            'consensus_provider_id' => $this->boundedScalar($consensus['provider_id'] ?? null, 64),
            'consensus_agent_id' => $this->boundedScalar($consensus['agent_id'] ?? null, 64),
            'consensus_access_event_id' => $this->boundedScalar($consensus['access_event_id'] ?? null, 64),
            'consensus_cost' => $this->boundedScalar($consensus['provider_reported_cost'] ?? null, 64),
            'consensus_cost_currency' => $this->boundedScalar($consensus['cost_currency'] ?? null, 8),
            'primary_checksum' => $this->boundedScalar($consensus['primary_checksum'] ?? null, 64),
            'secondary_checksum' => $this->boundedScalar($consensus['secondary_checksum'] ?? null, 64),
        ];
    }

    /** @return array{current_samples: int, protected_fixture_samples: int, historical_samples: int}|null */
    private function candidateReproduction(mixed $reproduction): ?array
    {
        if (! is_array($reproduction)) {
            return null;
        }

        return [
            'current_samples' => max(0, (int) ($reproduction['current_samples'] ?? 0)),
            'protected_fixture_samples' => max(0, (int) ($reproduction['protected_fixture_samples'] ?? 0)),
            'historical_samples' => max(0, (int) ($reproduction['historical_samples'] ?? 0)),
        ];
    }

    private function boundedScalar(mixed $value, int $limit): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : Str::limit($value, $limit, '');
    }
}
