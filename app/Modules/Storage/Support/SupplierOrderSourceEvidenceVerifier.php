<?php

namespace App\Modules\Storage\Support;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Proves that AI claims are grounded in the immutable normalized supplier source.
 *
 * The verifier deliberately accepts only normalizer-issued block and table locators. It never
 * treats a model-provided quote, confidence score, search offset, or profile pseudo-anchor as
 * evidence by itself.
 */
class SupplierOrderSourceEvidenceVerifier
{
    /** @var array<string, array{evidence: string, type: string}> */
    private const HEADER_FIELDS = [
        'external_order_number' => ['evidence' => 'evidence.external_order_number', 'type' => 'text'],
        'supplier.name' => ['evidence' => 'evidence.supplier.name', 'type' => 'text'],
        'ordered_at' => ['evidence' => 'evidence.ordered_at', 'type' => 'date'],
        'currency' => ['evidence' => 'evidence.currency', 'type' => 'text'],
        'buyer_reference' => ['evidence' => 'evidence.buyer_reference', 'type' => 'text'],
        'supplier_po_reference' => ['evidence' => 'evidence.supplier_po_reference', 'type' => 'text'],
        'delivery.method' => ['evidence' => 'evidence.delivery.method', 'type' => 'text'],
        'delivery.address' => ['evidence' => 'evidence.delivery.address', 'type' => 'text'],
        'delivery.expected_at' => ['evidence' => 'evidence.delivery.expected_at', 'type' => 'date'],
        'totals.goods_subtotal' => ['evidence' => 'evidence.totals.goods_subtotal', 'type' => 'number'],
        'totals.freight' => ['evidence' => 'evidence.totals.freight', 'type' => 'number'],
        'totals.discount' => ['evidence' => 'evidence.totals.discount', 'type' => 'number'],
        'totals.other_charges' => ['evidence' => 'evidence.totals.other_charges', 'type' => 'number'],
        'totals.tax_total' => ['evidence' => 'evidence.totals.tax_total', 'type' => 'number'],
        'totals.total_ex_tax' => ['evidence' => 'evidence.totals.total_ex_tax', 'type' => 'number'],
        'totals.total_inc_tax' => ['evidence' => 'evidence.totals.total_inc_tax', 'type' => 'number'],
    ];

    /** @var array<string, string> */
    private const LINE_FIELDS = [
        'supplier_sku' => 'text',
        'description' => 'text',
        'quantity' => 'number',
        'unit_price' => 'number',
        'line_total' => 'number',
        'tax_rate' => 'number',
        'currency' => 'text',
    ];

    public function __construct(private readonly SupplierOrderDocumentNormalizer $normalizer) {}

    /**
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $sourceSnapshot
     * @return list<array{code: string, path: string, message: string}>
     */
    public function verify(array $document, array $sourceSnapshot, ?string $expectedFingerprint): array
    {
        $errors = [];
        if (! is_string($expectedFingerprint) || preg_match('/^[a-f0-9]{64}$/', $expectedFingerprint) !== 1) {
            $this->error(
                $errors,
                'source_snapshot_fingerprint_missing',
                'source_fingerprint',
                'AI evidence requires the immutable SHA-256 source fingerprint.',
            );

            return $errors;
        }

        try {
            $actualFingerprint = StableJson::checksum($sourceSnapshot);
        } catch (Throwable) {
            $actualFingerprint = null;
        }
        if (! is_string($actualFingerprint) || ! hash_equals($expectedFingerprint, $actualFingerprint)) {
            $this->error(
                $errors,
                'source_snapshot_fingerprint_mismatch',
                'source_fingerprint',
                'The normalized AI evidence source no longer matches its immutable fingerprint.',
            );

            return $errors;
        }

        $normalized = $this->normalizer->normalize($sourceSnapshot);
        [$blocks, $tables] = $this->evidenceIndexes($normalized);

        foreach (self::HEADER_FIELDS as $fieldPath => $rule) {
            $value = data_get($document, $fieldPath);
            if (! $this->hasClaim($value)) {
                continue;
            }
            if ($fieldPath === 'ordered_at'
                && data_get($document, 'ordered_at_provenance') === 'received_at_fallback'
                && $this->fallbackDateMatches($value, $normalized)) {
                continue;
            }

            $this->verifyClaim(
                value: $value,
                fieldPath: $fieldPath,
                evidencePath: $rule['evidence'],
                type: $rule['type'],
                document: $document,
                blocks: $blocks,
                tables: $tables,
                errors: $errors,
            );
        }

        foreach ((array) data_get($document, 'lines', []) as $index => $line) {
            if (! is_array($line)) {
                continue;
            }
            foreach (self::LINE_FIELDS as $field => $type) {
                $value = $line[$field] ?? null;
                if (! $this->hasClaim($value)) {
                    continue;
                }
                $this->verifyClaim(
                    value: $value,
                    fieldPath: "lines.$index.$field",
                    evidencePath: "lines.$index.evidence.$field",
                    type: $type,
                    document: $document,
                    blocks: $blocks,
                    tables: $tables,
                    errors: $errors,
                );
            }
        }

        return $errors;
    }

    /**
     * @return array{
     *     0: array<string, array{id: string, type: string, text: string, source: string}>,
     *     1: array<string, array{id: string, headers: list<string>, rows: list<array{id: string, cells: array<string, string>}>}>
     * }
     */
    private function evidenceIndexes(SupplierOrderNormalizedDocument $document): array
    {
        $blocks = [];
        foreach ($document->blocks as $block) {
            $blocks[$block['id']] = $block;
        }
        $tables = [];
        foreach ($document->tables as $table) {
            $tables[$table['id']] = $table;
        }

        return [$blocks, $tables];
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  array<string, array{id: string, type: string, text: string, source: string}>  $blocks
     * @param  array<string, array{id: string, headers: list<string>, rows: list<array{id: string, cells: array<string, string>}>}>  $tables
     * @param  list<array{code: string, path: string, message: string}>  $errors
     */
    private function verifyClaim(
        mixed $value,
        string $fieldPath,
        string $evidencePath,
        string $type,
        array $document,
        array $blocks,
        array $tables,
        array &$errors,
    ): void {
        $anchor = data_get($document, $evidencePath);
        if (! is_array($anchor)) {
            $this->error(
                $errors,
                'source_evidence_missing',
                $fieldPath,
                "The AI claim at {$fieldPath} has no structured source anchor.",
            );

            return;
        }

        $sourceText = $this->sourceText($anchor, $fieldPath, $blocks, $tables, $errors);
        if ($sourceText === null) {
            return;
        }
        $quote = $anchor['quote'] ?? null;
        if (! is_string($quote) || trim($quote) === '') {
            $this->error(
                $errors,
                'source_evidence_quote_missing',
                $fieldPath,
                "The AI source anchor for {$fieldPath} has no literal quote.",
            );

            return;
        }
        if (! str_contains($sourceText, $quote)) {
            $this->error(
                $errors,
                'source_evidence_quote_mismatch',
                $fieldPath,
                "The quoted AI evidence for {$fieldPath} is not present verbatim in the addressed source block or cell.",
            );

            return;
        }
        if (! $this->valueMatchesQuote($value, $quote, $type)) {
            $this->error(
                $errors,
                'source_evidence_value_mismatch',
                $fieldPath,
                "The normalized AI value at {$fieldPath} is inconsistent with its literal source quote.",
            );
        }
    }

    /**
     * @param  array<string, mixed>  $anchor
     * @param  array<string, array{id: string, type: string, text: string, source: string}>  $blocks
     * @param  array<string, array{id: string, headers: list<string>, rows: list<array{id: string, cells: array<string, string>}>}>  $tables
     * @param  list<array{code: string, path: string, message: string}>  $errors
     */
    private function sourceText(
        array $anchor,
        string $fieldPath,
        array $blocks,
        array $tables,
        array &$errors,
    ): ?string {
        $blockId = trim((string) ($anchor['block_id'] ?? ''));
        if ($blockId === '' || (! isset($blocks[$blockId]) && ! isset($tables[$blockId]))) {
            $this->error(
                $errors,
                'source_evidence_anchor_unknown',
                $fieldPath,
                "The AI evidence locator for {$fieldPath} is not a block or table issued by the source normalizer.",
            );

            return null;
        }
        if (isset($blocks[$blockId])) {
            if (filled($anchor['row_id'] ?? null) || filled($anchor['column'] ?? null)) {
                $this->error(
                    $errors,
                    'source_evidence_block_locator_invalid',
                    $fieldPath,
                    "The block evidence for {$fieldPath} must not claim a table row or column.",
                );

                return null;
            }

            return $blocks[$blockId]['text'];
        }

        $rowId = trim((string) ($anchor['row_id'] ?? ''));
        $column = trim((string) ($anchor['column'] ?? ''));
        if ($rowId === '' || $column === '') {
            $this->error(
                $errors,
                'source_evidence_table_locator_missing',
                $fieldPath,
                "Table evidence for {$fieldPath} requires an exact row ID and column name.",
            );

            return null;
        }
        $row = null;
        foreach ($tables[$blockId]['rows'] as $candidate) {
            if ($candidate['id'] === $rowId) {
                $row = $candidate;
                break;
            }
        }
        if ($row === null) {
            $this->error(
                $errors,
                'source_evidence_table_row_unknown',
                $fieldPath,
                "The AI evidence row for {$fieldPath} does not belong to the addressed table.",
            );

            return null;
        }
        if (! array_key_exists($column, $row['cells'])) {
            $this->error(
                $errors,
                'source_evidence_table_column_unknown',
                $fieldPath,
                "The AI evidence column for {$fieldPath} does not exist in the addressed row.",
            );

            return null;
        }

        return $row['cells'][$column];
    }

    private function valueMatchesQuote(mixed $value, string $quote, string $type): bool
    {
        if ($type === 'number') {
            return $this->numberMatchesQuote($value, $quote);
        }
        if ($type === 'date') {
            return $this->dateMatchesQuote($value, $quote);
        }
        if (! is_scalar($value)) {
            return false;
        }

        $claim = $this->normalizedText((string) $value);
        $quoted = $this->normalizedText($quote);

        return $claim !== '' && str_contains($quoted, $claim);
    }

    private function numberMatchesQuote(mixed $value, string $quote): bool
    {
        if (! is_numeric($value) || ! is_finite((float) $value)) {
            return false;
        }
        preg_match_all(
            '/[-+]?(?:\d{1,3}(?:[ .\x{00A0}\x{202F}]\d{3})+|\d+)(?:[.,]\d+)?/u',
            $quote,
            $matches,
        );
        foreach ($matches[0] ?? [] as $token) {
            $parsed = $this->parseNumber((string) $token);
            if ($parsed !== null && abs($parsed - (float) $value) <= 0.000001) {
                return true;
            }
        }

        return false;
    }

    private function parseNumber(string $value): ?float
    {
        $value = str_replace([' ', "\u{00A0}", "\u{202F}"], '', trim($value));
        $comma = strrpos($value, ',');
        $dot = strrpos($value, '.');
        if ($comma !== false && $dot !== false) {
            $decimal = $comma > $dot ? ',' : '.';
            $thousands = $decimal === ',' ? '.' : ',';
            $value = str_replace($thousands, '', $value);
            $value = str_replace($decimal, '.', $value);
        } elseif ($comma !== false) {
            $value = str_replace(',', '.', $value);
        }

        return is_numeric($value) && is_finite((float) $value) ? (float) $value : null;
    }

    private function dateMatchesQuote(mixed $value, string $quote): bool
    {
        try {
            $expected = CarbonImmutable::parse((string) $value)->toDateString();
        } catch (Throwable) {
            return false;
        }
        if (str_contains($quote, $expected)) {
            return true;
        }
        preg_match_all('/\b(?:\d{4}[-\/.]\d{1,2}[-\/.]\d{1,2}|\d{1,2}[-\/.]\d{1,2}[-\/.]\d{2,4})\b/u', $quote, $matches);
        foreach ($matches[0] ?? [] as $candidate) {
            try {
                if (CarbonImmutable::parse((string) $candidate)->toDateString() === $expected) {
                    return true;
                }
            } catch (Throwable) {
                // Try the next bounded date token.
            }
        }

        return false;
    }

    private function fallbackDateMatches(mixed $value, SupplierOrderNormalizedDocument $document): bool
    {
        $receivedAt = data_get($document->sourceFacts, 'received_at');
        if (! filled($receivedAt)) {
            return false;
        }

        try {
            return CarbonImmutable::parse((string) $value)->toDateString()
                === CarbonImmutable::parse((string) $receivedAt)->toDateString();
        } catch (Throwable) {
            return false;
        }
    }

    private function normalizedText(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = str_replace(["\u{00A0}", "\u{202F}"], ' ', $value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? '';

        return mb_strtolower(trim($value));
    }

    private function hasClaim(mixed $value): bool
    {
        return $value !== null && (! is_string($value) || trim($value) !== '');
    }

    /** @param list<array{code: string, path: string, message: string}> $errors */
    private function error(array &$errors, string $code, string $path, string $message): void
    {
        $errors[] = compact('code', 'path', 'message');
    }
}
