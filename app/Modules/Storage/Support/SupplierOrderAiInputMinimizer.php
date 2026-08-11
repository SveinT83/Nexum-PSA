<?php

namespace App\Modules\Storage\Support;

class SupplierOrderAiInputMinimizer
{
    /** @var list<string> */
    private const NON_ORDER_LINE_PATTERNS = [
        '/\b(?:betaling|payment|betalingsmåte|payment method)\s*:/iu',
        '/\b(?:kontakt oss|contact us|åpningstider|opening hours|unsubscribe|avmeld|personvern|privacy policy)\b/iu',
        '/\b(?:e-?post|email|telefon|telephone|phone|mobil|mobile)\s*:/iu',
        '/\b(?:takk for (?:din|at du)|thank you for)\b/iu',
        '/\b(?:ignore (?:all |any )?(?:previous|prior) instructions|system prompt|developer message|you are chatgpt|prompt injection)\b/iu',
        '/\b(?:disregard|forget|override|bypass)\b.{0,80}\b(?:instructions|rules|system|policy)\b/iu',
        '/\b(?:act as|pretend to be|roleplay as)\b/iu',
        '/<\s*(?:system|assistant|developer)\s*>/iu',
        '/\b(?:api[_ -]?key|password|passwd|secret|bearer token)\s*[:=]/iu',
        '~https?://|\bwww\.~iu',
        '/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/iu',
    ];

    /** @var list<string> */
    private const FOOTER_START_PATTERNS = [
        '/^(?:med\s+)?vennlig hilsen\b/iu',
        '/^(?:kind|best) regards\b/iu',
        '/^(?:sincerely|yours sincerely|yours faithfully)\b/iu',
    ];

    /** @var list<string> */
    private const ADDRESS_HEADER_PATTERNS = [
        '/^(?:leveringsadresse|fakturaadresse|postadresse|delivery address|shipping address|billing address|postal address|adresse|address)\s*:/iu',
    ];

    /** @var list<string> */
    private const ADDRESS_LINE_PATTERNS = [
        '/^\d{4,6}\s+[\p{L}][\p{L} .\'-]{1,80}$/u',
        '/^[\p{L}][\p{L} .\'-]{1,80}(?:gata|gaten|gate|veien|vegen|veg|vei|allé|alle|plassen|street|road|avenue|lane|drive|boulevard)\s+\d{1,6}[A-Z]?\b/iu',
        '/^\d{1,6}\s+[\p{L}][\p{L} .\'-]{1,80}(?:street|road|avenue|lane|drive|boulevard)\b/iu',
    ];

    /** @var list<string> */
    private const ORDER_SECTION_BOUNDARY_PATTERNS = [
        '/^(?:ordresammendrag|order summary|vare|varer|item|items|produkt|produkter|product|products|antall|qty|quantity|varenr\.?|sku|total|totalt|subtotal|frakt|freight)\b/iu',
    ];

    /** @var list<string> */
    private const DOCUMENT_LINE_FIELDS = [
        'source_row_identifier',
        'supplier_sku',
        'description',
        'quantity',
        'unit_price',
        'line_total',
        'tax_rate',
        'currency',
    ];

    /** @var list<string> */
    private const DOCUMENT_TOTAL_FIELDS = [
        'goods_subtotal',
        'freight',
        'discount',
        'other_charges',
        'tax_total',
        'total_ex_tax',
        'total_inc_tax',
    ];

    /** @var list<string> */
    private const PROFILE_FIELD_PATHS = [
        'external_order_number',
        'supplier.name',
        'ordered_at',
        'currency',
        'buyer_reference',
        'po_reference',
        'delivery_method',
        'expected_at',
        'totals.goods_subtotal',
        'totals.freight',
        'totals.discount',
        'totals.other_charges',
        'totals.total_ex_tax',
        'totals.tax_total',
        'totals.total_inc_tax',
    ];

    /** @var list<string> */
    private const PROFILE_LINE_FIELDS = [
        'supplier_sku',
        'description',
        'quantity',
        'unit_price',
        'line_total',
        'tax_rate',
    ];

    /**
     * Remove clearly non-order contact, payment, address, tracking-link, footer,
     * and instruction content before the generic privacy gateway sees source data.
     *
     * @param  array<string, mixed>  $normalized
     * @return array<string, mixed>
     */
    public function minimize(array $normalized): array
    {
        $blocks = [];
        $footerReached = false;
        $addressLinesRemaining = 0;

        foreach ((array) ($normalized['blocks'] ?? []) as $block) {
            if ($footerReached || ! is_array($block)) {
                continue;
            }

            $rawText = trim((string) ($block['text'] ?? ''));
            if (! $this->isDelimitedLine($rawText) && $this->isFooterStart($rawText)) {
                $footerReached = true;

                continue;
            }
            if (! $this->isDelimitedLine($rawText) && $this->isAddressHeader($rawText)) {
                $addressLinesRemaining = 6;

                continue;
            }
            if ($addressLinesRemaining > 0) {
                if ($this->isOrderSectionBoundary($rawText)) {
                    $addressLinesRemaining = 0;
                } else {
                    $addressLinesRemaining--;

                    continue;
                }
            }

            $text = $this->text($rawText);
            if ($text === '') {
                continue;
            }

            $blocks[] = [
                'id' => $this->boundedText($block['id'] ?? '', 100) ?? '',
                'type' => $this->boundedText($block['type'] ?? 'text', 50) ?? 'text',
                'text' => $text,
                'source' => $this->boundedText($block['source'] ?? 'body', 50) ?? 'body',
            ];
        }

        $tables = [];
        foreach ((array) ($normalized['tables'] ?? []) as $table) {
            if (! is_array($table)) {
                continue;
            }

            $headers = [];
            $headerMap = [];
            $blockedColumns = [];
            foreach ((array) ($table['headers'] ?? []) as $header) {
                $rawHeader = trim((string) $header);
                $key = mb_strtolower($rawHeader);
                $safeHeader = $this->boundedText($rawHeader, 200);
                if ($safeHeader === null || $this->isAddressHeader($rawHeader)) {
                    $blockedColumns[$key] = true;

                    continue;
                }
                $headers[] = $safeHeader;
                $headerMap[$key] = $safeHeader;
            }

            $rows = [];
            foreach ((array) ($table['rows'] ?? []) as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $cells = [];
                foreach ((array) ($row['cells'] ?? []) as $column => $value) {
                    $rawColumn = trim((string) $column);
                    $columnKey = mb_strtolower($rawColumn);
                    if (isset($blockedColumns[$columnKey])) {
                        continue;
                    }

                    $safeColumn = $headerMap[$columnKey] ?? $this->boundedText($rawColumn, 200);
                    $safeValue = $this->boundedText($value, 500);
                    if ($safeColumn === null || $safeValue === null) {
                        continue;
                    }
                    $cells[$safeColumn] = $safeValue;
                }
                if ($cells === []) {
                    continue;
                }

                $rows[] = [
                    'id' => $this->boundedText($row['id'] ?? '', 100) ?? '',
                    'cells' => $cells,
                ];
            }
            if ($rows === []) {
                continue;
            }

            $tables[] = [
                'id' => $this->boundedText($table['id'] ?? '', 100) ?? '',
                'headers' => $headers,
                'rows' => $rows,
            ];
        }

        return ['blocks' => $blocks, 'tables' => $tables];
    }

    /**
     * Return only commercial order facts and verifiable evidence. Delivery
     * addresses and open-ended unknown fields are intentionally never projected.
     *
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public function minimizeDocument(array $document): array
    {
        $supplier = is_array($document['supplier'] ?? null) ? $document['supplier'] : [];
        $delivery = is_array($document['delivery'] ?? null) ? $document['delivery'] : [];
        $totals = is_array($document['totals'] ?? null) ? $document['totals'] : [];
        $lines = [];

        foreach (array_slice((array) ($document['lines'] ?? []), 0, 500) as $line) {
            if (! is_array($line)) {
                continue;
            }

            $projected = [];
            foreach (self::DOCUMENT_LINE_FIELDS as $field) {
                $projected[$field] = $this->safeScalar($line[$field] ?? null, $field === 'description' ? 2000 : 500);
            }
            $projected['evidence'] = $this->projectEvidenceMap(
                is_array($line['evidence'] ?? null) ? $line['evidence'] : [],
                ['supplier_sku', 'description', 'quantity', 'unit_price', 'line_total', 'tax_rate', 'currency'],
            );
            $lines[] = $projected;
        }

        $projectedTotals = [];
        foreach (self::DOCUMENT_TOTAL_FIELDS as $field) {
            $projectedTotals[$field] = $this->safeScalar($totals[$field] ?? null, 100);
        }

        return [
            'supplier' => ['name' => $this->boundedText($supplier['name'] ?? null, 500)],
            'external_order_number' => $this->boundedText($document['external_order_number'] ?? null, 255),
            'ordered_at' => $this->boundedText($document['ordered_at'] ?? null, 32),
            'ordered_at_provenance' => $this->boundedText($document['ordered_at_provenance'] ?? null, 50),
            'currency' => $this->boundedText($document['currency'] ?? null, 3),
            'buyer_reference' => $this->boundedText($document['buyer_reference'] ?? null, 500),
            'supplier_po_reference' => $this->boundedText($document['supplier_po_reference'] ?? null, 500),
            'delivery' => [
                'method' => $this->boundedText($delivery['method'] ?? null, 500),
                'expected_at' => $this->boundedText($delivery['expected_at'] ?? null, 32),
            ],
            'lines' => $lines,
            'totals' => $projectedTotals,
            'evidence' => $this->projectDocumentEvidence(
                is_array($document['evidence'] ?? null) ? $document['evidence'] : [],
            ),
        ];
    }

    /**
     * Project parser/material rules without exposing ingress identifiers, exact
     * contacts, or delivery-address rules. The server restores trusted match
     * scope on any returned candidate before validation.
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    public function minimizeProfile(array $definition): array
    {
        $match = is_array($definition['match'] ?? null) ? $definition['match'] : [];
        $selectorKinds = [];
        foreach ([
            'account_ids', 'mailboxes', 'recipients', 'senders', 'sender_domains',
            'subject_markers', 'body_markers', 'authenticated_supplier_domains',
        ] as $selector) {
            if ((array) ($match[$selector] ?? []) !== []) {
                $selectorKinds[] = $selector;
            }
        }

        $profile = [
            'schema_version' => $this->boundedText($definition['schema_version'] ?? null, 100),
            'document_type' => $this->boundedText($definition['document_type'] ?? null, 100),
            'locale' => $this->projectLocale(is_array($definition['locale'] ?? null) ? $definition['locale'] : []),
            'match' => [
                'scope_values_managed_by_server' => true,
                'selector_kinds' => $selectorKinds,
                'require_trusted_auth' => (bool) ($match['require_trusted_auth'] ?? true),
                'require_aligned' => (bool) ($match['require_aligned'] ?? true),
            ],
            'defaults' => $this->projectScalarMap(
                is_array($definition['defaults'] ?? null) ? $definition['defaults'] : [],
                ['warehouse_id', 'currency', 'ordered_date_fallback'],
            ),
            'item_defaults' => $this->projectScalarMap(
                is_array($definition['item_defaults'] ?? null) ? $definition['item_defaults'] : [],
                [
                    'vat_rate', 'has_serials', 'track_batch', 'expiry_enabled', 'becomes_asset',
                    'default_warranty_months', 'lead_time_days', 'moq',
                ],
            ),
            'fields' => $this->projectProfileFields(
                is_array($definition['fields'] ?? null) ? $definition['fields'] : [],
            ),
            'lines' => $this->projectProfileLines(
                is_array($definition['lines'] ?? null) ? $definition['lines'] : [],
            ),
            'validation' => $this->projectProfileValidation(
                is_array($definition['validation'] ?? null) ? $definition['validation'] : [],
            ),
        ];

        return $profile;
    }

    public function text(string $text): string
    {
        $lines = preg_split('/\R+/u', str_replace("\0", '', $text)) ?: [];
        $kept = [];
        $addressLinesRemaining = 0;

        foreach ($lines as $line) {
            $line = trim(preg_replace('/[\t ]+/u', ' ', $line) ?? $line);
            if ($line === '') {
                continue;
            }

            if ($this->isDelimitedLine($line)) {
                $segments = preg_split('/\s+\|\s+/u', $line) ?: [];
                $line = implode(' | ', array_values(array_filter(array_map(
                    function (string $segment): ?string {
                        $segment = trim($segment);

                        return $segment === ''
                            || $this->mustDrop($segment)
                            || $this->isFooterStart($segment)
                            || $this->isAddressHeader($segment)
                            ? null
                            : $segment;
                    },
                    $segments,
                ))));
                if ($line === '') {
                    continue;
                }
            } elseif ($this->isFooterStart($line)) {
                break;
            } elseif ($this->isAddressHeader($line)) {
                $addressLinesRemaining = 6;

                continue;
            } elseif ($addressLinesRemaining > 0) {
                if ($this->isOrderSectionBoundary($line)) {
                    $addressLinesRemaining = 0;
                } else {
                    $addressLinesRemaining--;

                    continue;
                }
            }

            if ($this->mustDrop($line)) {
                continue;
            }
            $kept[] = $line;
        }

        return trim(implode("\n", $kept));
    }

    public function boundedText(mixed $value, int $limit = 2000): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $text = $this->text((string) $value);

        return $text === '' ? null : mb_substr($text, 0, max(1, $limit));
    }

    /** @param list<string> $keys */
    private function projectScalarMap(array $source, array $keys): array
    {
        $projected = [];
        foreach ($keys as $key) {
            if (! array_key_exists($key, $source)) {
                continue;
            }
            $projected[$key] = $this->safeScalar($source[$key]);
        }

        return $projected;
    }

    private function projectLocale(array $locale): array
    {
        return [
            'language' => $this->boundedText($locale['language'] ?? null, 20),
            'decimal_separator' => $this->boundedText($locale['decimal_separator'] ?? null, 2),
            'thousands_separators' => $this->projectStringList($locale['thousands_separators'] ?? [], 4),
            'date_formats' => $this->projectStringList($locale['date_formats'] ?? [], 20),
        ];
    }

    private function projectProfileFields(array $fields): array
    {
        $projected = [];
        foreach (self::PROFILE_FIELD_PATHS as $path) {
            $rule = $fields[$path] ?? null;
            if (! is_array($rule)) {
                continue;
            }

            $safeRule = $this->projectScalarMap($rule, [
                'source', 'type', 'required', 'pattern', 'value', 'value_offset',
            ]);
            if (array_key_exists('labels', $rule)) {
                $safeRule['labels'] = $this->projectStringList($rule['labels'], 500);
            }
            $projected[$path] = $safeRule;
        }

        return $projected;
    }

    private function projectProfileLines(array $lines): array
    {
        $projected = $this->projectScalarMap($lines, ['max_matches']);
        $fieldRules = [];
        foreach (self::PROFILE_LINE_FIELDS as $field) {
            $rule = data_get($lines, 'fields.'.$field);
            if (! is_array($rule)) {
                continue;
            }
            $fieldRules[$field] = $this->projectScalarMap(
                $rule,
                ['capture', 'type', 'required', 'source_column', 'pattern'],
            );
        }
        if ($fieldRules !== []) {
            $projected['fields'] = $fieldRules;
        }

        $repeatedRegex = is_array($lines['repeated_regex'] ?? null) ? $lines['repeated_regex'] : [];
        $safePattern = $this->boundedText($repeatedRegex['pattern'] ?? null, 10000);
        if ($safePattern !== null) {
            $projected['repeated_regex'] = ['pattern' => $safePattern];
        }

        $htmlTable = is_array($lines['html_table'] ?? null) ? $lines['html_table'] : [];
        $headerAliases = [];
        foreach (self::PROFILE_LINE_FIELDS as $field) {
            $aliases = data_get($htmlTable, 'header_aliases.'.$field);
            if (is_array($aliases)) {
                $headerAliases[$field] = $this->projectStringList($aliases, 500);
            }
        }
        if ($headerAliases !== [] || array_key_exists('required_columns', $htmlTable)) {
            $projected['html_table'] = [
                'header_aliases' => $headerAliases,
                'required_columns' => array_values(array_intersect(
                    self::PROFILE_LINE_FIELDS,
                    $this->projectStringList($htmlTable['required_columns'] ?? [], 100),
                )),
            ];
        }

        return $projected;
    }

    private function projectProfileValidation(array $validation): array
    {
        $projected = $this->projectScalarMap($validation, [
            'amount_tolerance', 'max_lines', 'max_quantity', 'max_order_total',
        ]);
        $projected['required_fields'] = array_values(array_filter(
            $this->projectStringList($validation['required_fields'] ?? [], 100),
            fn (string $field): bool => $field !== 'delivery_address',
        ));

        return $projected;
    }

    /** @param list<string> $fields */
    private function projectEvidenceMap(array $evidence, array $fields): array
    {
        $projected = [];
        foreach ($fields as $field) {
            $projected[$field] = $this->projectEvidenceAnchor($evidence[$field] ?? null);
        }

        return $projected;
    }

    private function projectDocumentEvidence(array $evidence): array
    {
        $supplier = is_array($evidence['supplier'] ?? null) ? $evidence['supplier'] : [];
        $delivery = is_array($evidence['delivery'] ?? null) ? $evidence['delivery'] : [];
        $totals = is_array($evidence['totals'] ?? null) ? $evidence['totals'] : [];

        return [
            'supplier' => ['name' => $this->projectEvidenceAnchor($supplier['name'] ?? null)],
            'external_order_number' => $this->projectEvidenceAnchor($evidence['external_order_number'] ?? null),
            'ordered_at' => $this->projectEvidenceAnchor($evidence['ordered_at'] ?? null),
            'currency' => $this->projectEvidenceAnchor($evidence['currency'] ?? null),
            'buyer_reference' => $this->projectEvidenceAnchor($evidence['buyer_reference'] ?? null),
            'supplier_po_reference' => $this->projectEvidenceAnchor($evidence['supplier_po_reference'] ?? null),
            'delivery' => [
                'method' => $this->projectEvidenceAnchor($delivery['method'] ?? null),
                'expected_at' => $this->projectEvidenceAnchor($delivery['expected_at'] ?? null),
            ],
            'totals' => $this->projectEvidenceMap($totals, self::DOCUMENT_TOTAL_FIELDS),
        ];
    }

    private function projectEvidenceAnchor(mixed $anchor): ?array
    {
        if (! is_array($anchor)) {
            return null;
        }

        $blockId = $this->boundedText($anchor['block_id'] ?? null, 100);
        $quote = $this->boundedText($anchor['quote'] ?? null, 500);
        if ($blockId === null || $quote === null) {
            return null;
        }

        return [
            'block_id' => $blockId,
            'row_id' => $this->boundedText($anchor['row_id'] ?? null, 100),
            'column' => $this->boundedText($anchor['column'] ?? null, 200),
            'quote' => $quote,
        ];
    }

    /** @return list<string> */
    private function projectStringList(mixed $values, int $limit): array
    {
        if (! is_array($values)) {
            return [];
        }

        $projected = [];
        foreach ($values as $value) {
            $safe = $this->boundedText($value, $limit);
            if ($safe !== null) {
                $projected[] = $safe;
            }
        }

        return array_values(array_unique($projected));
    }

    private function safeScalar(mixed $value, int $limit = 2000): mixed
    {
        if (is_string($value) || is_numeric($value)) {
            return $this->boundedText($value, $limit);
        }

        return is_bool($value) ? $value : null;
    }

    private function mustDrop(string $line): bool
    {
        return $this->matchesAny($line, self::NON_ORDER_LINE_PATTERNS)
            || $this->matchesAny($line, self::ADDRESS_LINE_PATTERNS);
    }

    private function isFooterStart(string $line): bool
    {
        return $this->matchesAny($line, self::FOOTER_START_PATTERNS);
    }

    private function isAddressHeader(string $line): bool
    {
        return $this->matchesAny($line, self::ADDRESS_HEADER_PATTERNS);
    }

    private function isOrderSectionBoundary(string $line): bool
    {
        return $this->matchesAny($line, self::ORDER_SECTION_BOUNDARY_PATTERNS);
    }

    private function isDelimitedLine(string $line): bool
    {
        return preg_match('/\s+\|\s+/u', $line) === 1;
    }

    /** @param list<string> $patterns */
    private function matchesAny(string $value, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $value) === 1) {
                return true;
            }
        }

        return false;
    }
}
