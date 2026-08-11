<?php

namespace App\Modules\Storage\Support;

use App\Modules\Storage\Models\PurchaseOrderImportProfileVersion;
use Illuminate\Support\Str;
use JsonException;

class SupplierOrderDeterministicExtractor
{
    public function __construct(
        private SupplierOrderProfileDefinitionValidator $definitionValidator,
        private SupplierOrderDocumentNormalizer $normalizer,
        private SupplierOrderLocaleParser $localeParser,
        private SupplierOrderSafeRegex $safeRegex,
    ) {}

    /** @param array<string, mixed> $sourceSnapshot */
    public function extract(
        PurchaseOrderImportProfileVersion $version,
        array $sourceSnapshot,
    ): SupplierOrderExtractionResult {
        $result = $this->extractDefinition((array) $version->definition, $sourceSnapshot);
        $errors = $result->errors;
        $expectedChecksum = StableJson::checksum((array) $version->definition);

        if (! hash_equals($expectedChecksum, (string) $version->checksum)) {
            $this->error(
                $errors,
                'profile_checksum_mismatch',
                'profile_version.checksum',
                'The immutable profile version checksum does not match its definition.',
            );
        }
        if ((string) $version->schema_version !== SupplierOrderProfileDefinitionValidator::SCHEMA_VERSION) {
            $this->error(
                $errors,
                'profile_schema_mismatch',
                'profile_version.schema_version',
                'The stored profile version uses an unsupported schema.',
            );
        }

        return new SupplierOrderExtractionResult(
            document: $result->document,
            errors: $errors,
            warnings: $result->warnings,
            normalized: $result->normalized,
            definitionChecksum: $result->definitionChecksum,
        );
    }

    /**
     * Execute only the bounded declarative profile DSL. This method never performs network or domain writes.
     *
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $sourceSnapshot
     */
    public function extractDefinition(
        array $definition,
        array $sourceSnapshot,
    ): SupplierOrderExtractionResult {
        $normalized = $this->normalizer->normalize($sourceSnapshot);
        $definitionResult = $this->definitionValidator->validate($definition);
        try {
            $definitionChecksum = StableJson::checksum($definition);
        } catch (JsonException) {
            $definitionChecksum = hash('sha256', 'invalid-profile-definition');
        }
        if (! $definitionResult->valid()) {
            return new SupplierOrderExtractionResult(
                document: null,
                errors: $definitionResult->errors,
                warnings: $definitionResult->warnings,
                normalized: $normalized,
                definitionChecksum: $definitionChecksum,
            );
        }

        $errors = [];
        $warnings = [];
        $unknownFields = [];
        $locale = (array) $definition['locale'];
        $document = [
            'schema_version' => 'storage.supplier_order.v1',
            'document_type' => 'supplier_order_confirmation',
            'lines' => [],
            'totals' => [],
            'unknown_fields' => [],
            'warnings' => [],
            'evidence' => [],
        ];

        foreach ((array) $definition['fields'] as $path => $rule) {
            $result = $this->extractField(
                (string) $path,
                (array) $rule,
                $normalized,
                $locale,
            );
            if (! $result['found']) {
                $unknownFields[] = (string) $path;
                if ((bool) ($rule['required'] ?? false)) {
                    $this->error($errors, 'required_field_missing', (string) $path, 'Required profile field was not found.');
                }

                continue;
            }
            if (! $result['parsed']) {
                $unknownFields[] = (string) $path;
                if ((bool) ($rule['required'] ?? false)) {
                    $this->error($errors, 'field_value_invalid', (string) $path, 'Source value could not be converted by the declared locale and type.');
                } else {
                    $this->error($warnings, 'field_value_invalid', (string) $path, 'Source value could not be converted by the declared locale and type.');
                }

                continue;
            }

            data_set($document, (string) $path, $result['value']);
            data_set($document, 'evidence.'.(string) $path, $result['evidence']);
            if (($rule['source'] ?? null) === 'received_at' && $path === 'ordered_at') {
                $document['ordered_at_provenance'] = 'received_at_fallback';
            }
        }

        foreach ((array) data_get($definition, 'validation.required_fields', []) as $requiredPath) {
            if (! filled(data_get($document, (string) $requiredPath))) {
                $this->error($errors, 'required_field_missing', (string) $requiredPath, 'Required canonical field was not extracted.');
            }
        }

        $lineDefinition = (array) $definition['lines'];
        $lines = [];
        if (isset($lineDefinition['html_table'])) {
            $lines = $this->extractTableLines(
                $lineDefinition,
                $normalized,
                $locale,
                $errors,
                $warnings,
            );
        }
        if ($lines === [] && isset($lineDefinition['repeated_regex'])) {
            $lines = $this->extractRegexLines(
                $lineDefinition,
                $normalized,
                $locale,
                $errors,
                $warnings,
            );
        }
        $document['lines'] = $lines;

        $this->validateDocument(
            $document,
            (array) $definition['validation'],
            $errors,
            $warnings,
        );

        $document['unknown_fields'] = array_values(array_unique($unknownFields));
        $document['warnings'] = $warnings;

        return new SupplierOrderExtractionResult(
            document: $document,
            errors: $errors,
            warnings: $warnings,
            normalized: $normalized,
            definitionChecksum: $definitionChecksum,
        );
    }

    /**
     * @param  array<string, mixed>  $rule
     * @param  array<string, mixed>  $locale
     * @return array{found: bool, parsed: bool, value: mixed, evidence: array<string, mixed>|null}
     */
    private function extractField(
        string $path,
        array $rule,
        SupplierOrderNormalizedDocument $document,
        array $locale,
    ): array {
        $source = (string) $rule['source'];
        $raw = null;
        $evidence = null;
        $found = false;

        if ($source === 'fixed') {
            $raw = $rule['value'];
            $found = true;
            $evidence = [
                'block_id' => 'profile:'.$path,
                'source' => 'profile_fixed',
                'quote' => mb_substr((string) $raw, 0, 500),
            ];
        } elseif ($source === 'received_at') {
            $raw = data_get($document->sourceFacts, 'received_at');
            $found = filled($raw);
            $evidence = $found ? [
                'block_id' => 'source:received_at',
                'source' => 'source_snapshot',
                'quote' => mb_substr((string) $raw, 0, 500),
            ] : null;
        } elseif ($source === 'regex') {
            $match = $this->captureValue(
                (string) $rule['pattern'],
                $document->searchText,
            );
            $raw = $match['value'];
            $found = $match['found'];
            $evidence = $found
                ? ($document->anchorForQuote((string) $raw) ?? [
                    'block_id' => 'search:'.$match['offset'],
                    'source' => 'normalized_text',
                    'quote' => mb_substr((string) $raw, 0, 500),
                ])
                : null;
        } elseif ($source === 'label') {
            $match = $this->labelValue(
                (array) $rule['labels'],
                (int) ($rule['value_offset'] ?? 0),
                $document,
            );
            $raw = $match['value'];
            $found = $match['found'];
            $evidence = $match['evidence'];
            if ($found && is_string($rule['pattern'] ?? null)) {
                $captured = $this->captureValue((string) $rule['pattern'], (string) $raw);
                $raw = $captured['value'];
                $found = $captured['found'];
            }
        }

        if (! $found) {
            return ['found' => false, 'parsed' => false, 'value' => null, 'evidence' => null];
        }

        $value = $source === 'received_at'
            ? $this->localeParser->receivedDate($raw)
            : $this->convert($raw, (string) $rule['type'], $locale);

        return [
            'found' => true,
            'parsed' => $value !== null,
            'value' => $value,
            'evidence' => $evidence,
        ];
    }

    /**
     * @return array{found: bool, value: mixed, offset: int}
     */
    private function captureValue(string $pattern, string $subject): array
    {
        $matches = [];
        $matched = preg_match(
            $this->safeRegex->compileOrFail($pattern),
            $subject,
            $matches,
            PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL,
        );

        $capture = $matches['value'] ?? null;
        if ($matched !== 1 || ! is_array($capture) || $capture[0] === null) {
            return ['found' => false, 'value' => null, 'offset' => -1];
        }

        return [
            'found' => true,
            'value' => trim((string) $capture[0]),
            'offset' => (int) $capture[1],
        ];
    }

    /**
     * @param  array<int, mixed>  $labels
     * @return array{found: bool, value: mixed, evidence: array<string, mixed>|null}
     */
    private function labelValue(
        array $labels,
        int $offset,
        SupplierOrderNormalizedDocument $document,
    ): array {
        foreach ($document->blocks as $index => $block) {
            foreach ($labels as $label) {
                if (! is_string($label) || trim($label) === '') {
                    continue;
                }

                $position = mb_stripos($block['text'], trim($label));
                if ($position === false) {
                    continue;
                }

                $target = $document->blocks[$index + $offset] ?? null;
                if ($target === null) {
                    continue;
                }

                $raw = $offset === 0
                    ? mb_substr($block['text'], $position + mb_strlen(trim($label)))
                    : $target['text'];
                $raw = trim($raw, " \t\n\r\0\x0B:-");
                if ($raw === '') {
                    continue;
                }

                return [
                    'found' => true,
                    'value' => $raw,
                    'evidence' => [
                        'block_id' => $target['id'],
                        'source' => $target['source'],
                        'quote' => mb_substr($raw, 0, 500),
                    ],
                ];
            }
        }

        return ['found' => false, 'value' => null, 'evidence' => null];
    }

    /**
     * @param  array<string, mixed>  $lineDefinition
     * @param  array<string, mixed>  $locale
     * @param  list<array{code: string, path: string, message: string}>  $errors
     * @param  list<array{code: string, path: string, message: string}>  $warnings
     * @return list<array<string, mixed>>
     */
    private function extractRegexLines(
        array $lineDefinition,
        SupplierOrderNormalizedDocument $document,
        array $locale,
        array &$errors,
        array &$warnings,
    ): array {
        $pattern = (string) data_get($lineDefinition, 'repeated_regex.pattern', '');
        $matches = [];
        $count = preg_match_all(
            $this->safeRegex->compileOrFail($pattern),
            $document->searchText,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL,
        );
        if ($count === false) {
            $this->error($errors, 'line_pattern_failed', 'lines', 'The bounded line pattern could not be evaluated.');

            return [];
        }

        $lines = [];
        $max = (int) $lineDefinition['max_matches'];
        foreach (array_slice($matches, 0, $max) as $matchIndex => $match) {
            $line = ['evidence' => []];
            foreach ((array) $lineDefinition['fields'] as $field => $rule) {
                $capture = $match[(string) $rule['capture']] ?? null;
                $raw = is_array($capture) ? $capture[0] : null;
                if ($raw === null || trim((string) $raw) === '') {
                    if ((bool) ($rule['required'] ?? false)) {
                        $this->error($errors, 'line_field_missing', "lines.$matchIndex.$field", 'Required line capture is missing.');
                    }

                    continue;
                }

                $value = $this->convertLineValue((string) $raw, (array) $rule, $locale);
                if ($value === null) {
                    if ((bool) ($rule['required'] ?? false)) {
                        $this->error($errors, 'line_value_invalid', "lines.$matchIndex.$field", 'Line value could not be converted.');
                    } else {
                        $this->error($warnings, 'line_value_invalid', "lines.$matchIndex.$field", 'Line value could not be converted.');
                    }

                    continue;
                }

                $line[(string) $field] = $value;
                $line['evidence'][(string) $field] = $document->anchorForQuote((string) $raw) ?? [
                    'block_id' => 'search:'.(int) ($capture[1] ?? -1),
                    'source' => 'normalized_text',
                    'quote' => mb_substr(trim((string) $raw), 0, 500),
                ];
            }
            if (count($line) > 1) {
                $lines[] = $line;
            }
        }

        if ($count > $max) {
            $this->error($errors, 'line_match_limit_exceeded', 'lines', 'The profile line-match limit was exceeded.');
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $lineDefinition
     * @param  array<string, mixed>  $locale
     * @param  list<array{code: string, path: string, message: string}>  $errors
     * @param  list<array{code: string, path: string, message: string}>  $warnings
     * @return list<array<string, mixed>>
     */
    private function extractTableLines(
        array $lineDefinition,
        SupplierOrderNormalizedDocument $document,
        array $locale,
        array &$errors,
        array &$warnings,
    ): array {
        $tableRule = (array) $lineDefinition['html_table'];
        $aliases = (array) $tableRule['header_aliases'];
        $requiredColumns = (array) $tableRule['required_columns'];
        $lines = [];
        $max = (int) $lineDefinition['max_matches'];

        foreach ($document->tables as $table) {
            $columnMap = $this->tableColumnMap($table['headers'], $aliases);
            if (array_diff($requiredColumns, array_keys($columnMap)) !== []) {
                continue;
            }

            foreach ($table['rows'] as $row) {
                if (count($lines) >= $max) {
                    $this->error($errors, 'line_match_limit_exceeded', 'lines', 'The profile line-match limit was exceeded.');

                    return $lines;
                }

                $lineIndex = count($lines);
                $line = ['evidence' => []];
                foreach ((array) $lineDefinition['fields'] as $field => $rule) {
                    $sourceColumn = (string) ($rule['source_column'] ?? $field);
                    $header = $columnMap[$sourceColumn] ?? null;
                    $raw = $header !== null ? ($row['cells'][$header] ?? null) : null;
                    if (! is_string($raw) || trim($raw) === '') {
                        if ((bool) ($rule['required'] ?? false)) {
                            $this->error($errors, 'line_field_missing', "lines.$lineIndex.$field", 'Required table value is missing.');
                        }

                        continue;
                    }

                    $value = $this->convertLineValue($raw, (array) $rule, $locale);
                    if ($value === null) {
                        if ((bool) ($rule['required'] ?? false)) {
                            $this->error($errors, 'line_value_invalid', "lines.$lineIndex.$field", 'Table value could not be converted.');
                        } else {
                            $this->error($warnings, 'line_value_invalid', "lines.$lineIndex.$field", 'Table value could not be converted.');
                        }

                        continue;
                    }

                    $line[(string) $field] = $value;
                    $line['evidence'][(string) $field] = [
                        'block_id' => $table['id'],
                        'row_id' => $row['id'],
                        'column' => $header,
                        'source' => 'html_table',
                        'quote' => mb_substr(trim($raw), 0, 500),
                    ];
                }
                if (count($line) > 1) {
                    $lines[] = $line;
                }
            }
        }

        return $lines;
    }

    /**
     * @param  list<string>  $headers
     * @param  array<string, mixed>  $aliases
     * @return array<string, string>
     */
    private function tableColumnMap(array $headers, array $aliases): array
    {
        $map = [];
        foreach ($aliases as $field => $fieldAliases) {
            $normalizedAliases = collect((array) $fieldAliases)
                ->filter(fn (mixed $value): bool => is_string($value))
                ->map(fn (string $alias): string => $this->headerKey($alias))
                ->all();
            foreach ($headers as $header) {
                if (in_array($this->headerKey($header), $normalizedAliases, true)) {
                    $map[(string) $field] = $header;
                    break;
                }
            }
        }

        return $map;
    }

    private function headerKey(string $value): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($value))) ?? '');
    }

    /** @param array<string, mixed> $rule
     * @param  array<string, mixed>  $locale
     */
    private function convertLineValue(string $raw, array $rule, array $locale): mixed
    {
        if (is_string($rule['pattern'] ?? null)) {
            $captured = $this->captureValue((string) $rule['pattern'], $raw);
            if (! $captured['found']) {
                return null;
            }
            $raw = (string) $captured['value'];
        }

        return $this->convert($raw, (string) $rule['type'], $locale);
    }

    /** @param array<string, mixed> $locale */
    private function convert(mixed $value, string $type, array $locale): mixed
    {
        return match ($type) {
            'string' => is_scalar($value) && trim((string) $value) !== ''
                ? mb_substr(trim((string) $value), 0, 2000)
                : null,
            'integer' => $this->localeParser->integer($value, $locale),
            'decimal' => $this->localeParser->decimal($value, $locale),
            'date' => $this->localeParser->date($value, $locale),
            'currency' => $this->currency($value),
            default => null,
        };
    }

    private function currency(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $currency = mb_strtoupper(trim((string) $value));

        return preg_match('/^[A-Z]{3}$/', $currency) === 1 ? $currency : null;
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $rules
     * @param  list<array{code: string, path: string, message: string}>  $errors
     * @param  list<array{code: string, path: string, message: string}>  $warnings
     */
    private function validateDocument(
        array $document,
        array $rules,
        array &$errors,
        array &$warnings,
    ): void {
        $lines = (array) ($document['lines'] ?? []);
        $tolerance = max(0.0, (float) $rules['amount_tolerance']);
        foreach (['external_order_number', 'supplier.name', 'currency'] as $requiredPath) {
            if (! filled(data_get($document, $requiredPath))) {
                $this->error($errors, 'canonical_field_missing', $requiredPath, 'Canonical supplier order identity is required.');
            }
        }
        $currency = (string) data_get($document, 'currency', '');
        if ($currency !== '' && preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            $this->error($errors, 'currency_invalid', 'currency', 'Currency must be a three-letter uppercase code.');
        }
        if (data_get($document, 'ordered_at') === null
            && data_get($document, 'ordered_at_provenance') !== 'received_at_fallback') {
            $this->error($errors, 'ordered_at_unknown', 'ordered_at', 'Order date or an explicit received-date fallback is required.');
        }
        if ($lines === []) {
            $this->error($errors, 'lines_missing', 'lines', 'At least one supplier order line must be extracted.');
        }
        if (count($lines) > (int) $rules['max_lines']) {
            $this->error($errors, 'line_limit_exceeded', 'lines', 'The configured canonical line limit was exceeded.');
        }

        $calculatedSubtotal = 0.0;
        foreach ($lines as $index => $line) {
            $sku = trim((string) ($line['supplier_sku'] ?? ''));
            $description = trim((string) ($line['description'] ?? ''));
            if ($sku === '' && $description === '') {
                $this->error($errors, 'line_identity_missing', "lines.$index", 'Supplier SKU or description is required.');
            }
            $quantity = $this->numeric($line['quantity'] ?? null);
            if ($quantity === null || $quantity <= 0 || floor($quantity) !== $quantity) {
                $this->error($errors, 'quantity_invalid', "lines.$index.quantity", 'Quantity must be a positive whole number.');
            } elseif ($quantity > (int) $rules['max_quantity']) {
                $this->error($errors, 'quantity_limit_exceeded', "lines.$index.quantity", 'Configured quantity limit was exceeded.');
            }

            foreach ($line as $field => $value) {
                if ($field === 'evidence' || $value === null || $value === '') {
                    continue;
                }
                if (! filled(data_get($line, "evidence.$field.block_id"))) {
                    $this->error($errors, 'line_evidence_missing', "lines.$index.$field", 'Every populated line value requires source evidence.');
                }
            }

            $unitPrice = $this->numeric($line['unit_price'] ?? null);
            $lineTotal = $this->numeric($line['line_total'] ?? null);
            if (($unitPrice !== null && $unitPrice < 0) || ($lineTotal !== null && $lineTotal < 0)) {
                $this->error($errors, 'line_amount_negative', "lines.$index", 'Line amounts cannot be negative.');
            }
            if ($quantity !== null && $unitPrice !== null && $lineTotal !== null
                && ! $this->withinTolerance($quantity * $unitPrice, $lineTotal, $tolerance)) {
                $this->error($errors, 'line_arithmetic_mismatch', "lines.$index", 'Quantity, unit price, and line total do not reconcile.');
            }
            if ($lineTotal !== null) {
                $calculatedSubtotal += $lineTotal;
            } elseif ($quantity !== null && $unitPrice !== null) {
                $calculatedSubtotal += $quantity * $unitPrice;
            }
        }

        $goodsSubtotal = $this->numeric(data_get($document, 'totals.goods_subtotal'));
        if ($goodsSubtotal !== null && ! $this->withinTolerance($calculatedSubtotal, $goodsSubtotal, $tolerance)) {
            $this->error($errors, 'subtotal_mismatch', 'totals.goods_subtotal', 'Extracted lines do not reconcile with goods subtotal.');
        }

        $freight = $this->numeric(data_get($document, 'totals.freight')) ?? 0.0;
        $discount = $this->numeric(data_get($document, 'totals.discount')) ?? 0.0;
        $otherCharges = $this->numeric(data_get($document, 'totals.other_charges')) ?? 0.0;
        $totalExTax = $this->numeric(data_get($document, 'totals.total_ex_tax'));
        $base = $goodsSubtotal ?? $calculatedSubtotal;
        if ($totalExTax !== null
            && ! $this->withinTolerance($base + $freight + $otherCharges - $discount, $totalExTax, $tolerance)) {
            $this->error($errors, 'total_ex_tax_mismatch', 'totals.total_ex_tax', 'Extracted charges do not reconcile with total excluding tax.');
        }
        $orderTotalForLimit = $totalExTax ?? ($base + $freight + $otherCharges - $discount);
        if ($orderTotalForLimit > (float) $rules['max_order_total']) {
            $this->error($errors, 'order_total_limit_exceeded', 'totals.total_ex_tax', 'Configured order-total limit was exceeded.');
        }

        $taxTotal = $this->numeric(data_get($document, 'totals.tax_total'));
        $totalIncTax = $this->numeric(data_get($document, 'totals.total_inc_tax'));
        if ($totalExTax !== null && $taxTotal !== null && $totalIncTax !== null
            && ! $this->withinTolerance($totalExTax + $taxTotal, $totalIncTax, $tolerance)) {
            $this->error($errors, 'total_inc_tax_mismatch', 'totals.total_inc_tax', 'Tax and totals do not reconcile.');
        }

        foreach (['external_order_number', 'supplier.name'] as $criticalPath) {
            if (filled(data_get($document, $criticalPath))
                && ! filled(data_get($document, 'evidence.'.$criticalPath.'.block_id'))) {
                $this->error($errors, 'critical_evidence_missing', $criticalPath, 'Critical field requires source evidence.');
            }
        }
    }

    private function numeric(mixed $value): ?float
    {
        return is_numeric($value) && is_finite((float) $value) ? (float) $value : null;
    }

    private function withinTolerance(float $expected, float $actual, float $tolerance): bool
    {
        return abs($expected - $actual) <= $tolerance;
    }

    private function error(array &$target, string $code, string $path, string $message): void
    {
        $target[] = compact('code', 'path', 'message');
    }
}
