<?php

namespace App\Modules\Storage\Support;

use Illuminate\Validation\ValidationException;
use JsonException;

class SupplierOrderProfileDefinitionValidator
{
    public const SCHEMA_VERSION = 'storage.supplier_order_profile.v1';

    private const MAX_BYTES = 65536;

    private const MAX_DEPTH = 10;

    private const MAX_NODES = 10000;

    private const TOP_LEVEL_KEYS = [
        'schema_version',
        'document_type',
        'locale',
        'match',
        'defaults',
        'item_defaults',
        'fields',
        'lines',
        'validation',
    ];

    private const FIELD_PATHS = [
        'external_order_number',
        'supplier.name',
        'ordered_at',
        'currency',
        'buyer_reference',
        'po_reference',
        'delivery_method',
        'delivery_address',
        'expected_at',
        'totals.goods_subtotal',
        'totals.freight',
        'totals.discount',
        'totals.other_charges',
        'totals.total_ex_tax',
        'totals.tax_total',
        'totals.total_inc_tax',
    ];

    private const LINE_FIELDS = [
        'supplier_sku',
        'description',
        'quantity',
        'unit_price',
        'line_total',
        'tax_rate',
    ];

    private const FORBIDDEN_KEYS = [
        'callback',
        'callbacks',
        'class',
        'classes',
        'code',
        'command',
        'endpoint',
        'exec',
        'executable',
        'javascript',
        'network',
        'php',
        'provider',
        'script',
        'shell',
        'tool',
        'tools',
        'url',
        'urls',
        'webhook',
    ];

    public function __construct(private SupplierOrderSafeRegex $safeRegex) {}

    /** @param array<string, mixed> $definition */
    public function validate(array $definition): SupplierOrderProfileValidationResult
    {
        $errors = [];
        $warnings = [];

        try {
            if (strlen(StableJson::encode($definition)) > self::MAX_BYTES) {
                $this->error($errors, 'definition_too_large', '$', 'Profile definition exceeds 64 KB.');
            }
        } catch (JsonException) {
            $this->error($errors, 'definition_json_invalid', '$', 'Profile definition is not valid JSON data.');
        }

        $nodes = 0;
        $this->validateBoundsAndForbiddenValues($definition, '$', 0, $nodes, $errors);
        $this->rejectUnknownKeys($definition, self::TOP_LEVEL_KEYS, '$', $errors);
        $this->exact($definition['schema_version'] ?? null, self::SCHEMA_VERSION, 'schema_version', $errors);
        $this->exact($definition['document_type'] ?? null, 'supplier_order_confirmation', 'document_type', $errors);
        $this->validateLocale($definition['locale'] ?? null, $errors);
        $this->validateMatch($definition['match'] ?? null, $errors);
        if (array_key_exists('defaults', $definition)) {
            $this->validateDefaults($definition['defaults'], $errors);
        }
        if (array_key_exists('item_defaults', $definition)) {
            $this->validateItemDefaults($definition['item_defaults'], $errors);
        }
        $this->validateFields($definition['fields'] ?? null, $errors);
        $this->validateLines($definition['lines'] ?? null, $errors);
        $this->validateRules($definition['validation'] ?? null, $errors);

        return new SupplierOrderProfileValidationResult(
            errors: $errors,
            warnings: $warnings,
        );
    }

    /**
     * Validate the mutable container copy of source-matching metadata with the
     * same bounded allowlist used by immutable parser definitions.
     *
     * @param  array<string, mixed>  $matchingScope
     */
    public function validateMatchingScope(array $matchingScope): SupplierOrderProfileValidationResult
    {
        $errors = [];

        try {
            if (strlen(StableJson::encode($matchingScope)) > self::MAX_BYTES) {
                $this->error(
                    $errors,
                    'definition_too_large',
                    'matching_scope',
                    'Matching scope exceeds 64 KB.',
                );
            }
        } catch (JsonException) {
            $this->error($errors, 'definition_json_invalid', 'matching_scope', 'Matching scope is not valid JSON data.');
        }

        $nodes = 0;
        $this->validateBoundsAndForbiddenValues($matchingScope, 'matching_scope', 0, $nodes, $errors);
        $this->validateMatch($matchingScope, $errors);
        $errors = array_map(function (array $error): array {
            if ($error['path'] === 'match') {
                $error['path'] = 'matching_scope';
            } elseif (str_starts_with($error['path'], 'match.')) {
                $error['path'] = 'matching_scope.'.substr($error['path'], 6);
            }

            return $error;
        }, $errors);

        return new SupplierOrderProfileValidationResult(errors: $errors);
    }

    /** @param array<string, mixed> $definition */
    public function validateOrFail(array $definition): array
    {
        $result = $this->validate($definition);
        if (! $result->valid()) {
            throw ValidationException::withMessages([
                'definition' => collect($result->errors)
                    ->map(fn (array $error): string => $error['path'].': '.$error['message'])
                    ->all(),
            ]);
        }

        return $definition;
    }

    private function validateLocale(mixed $locale, array &$errors): void
    {
        if (! is_array($locale) || array_is_list($locale)) {
            $this->error($errors, 'locale_invalid', 'locale', 'Locale must be a structured object.');

            return;
        }

        $this->rejectUnknownKeys($locale, [
            'language',
            'decimal_separator',
            'thousands_separators',
            'date_formats',
        ], 'locale', $errors);

        if (! is_string($locale['language'] ?? null)
            || preg_match('/^[a-z]{2,3}(?:-[A-Z]{2})?$/', $locale['language']) !== 1) {
            $this->error($errors, 'locale_language_invalid', 'locale.language', 'Use a bounded BCP-47 style language value.');
        }
        if (! in_array($locale['decimal_separator'] ?? null, [',', '.'], true)) {
            $this->error($errors, 'locale_decimal_separator_invalid', 'locale.decimal_separator', 'Decimal separator must be comma or period.');
        }
        $this->stringList(
            $locale['thousands_separators'] ?? null,
            'locale.thousands_separators',
            4,
            1,
            $errors,
            allowed: [' ', '.', ',', "\u{00A0}", "\u{202F}"],
        );
        $this->stringList(
            $locale['date_formats'] ?? null,
            'locale.date_formats',
            12,
            12,
            $errors,
            allowed: ['Y-m-d', 'd.m.Y', 'd.m.y', 'd/m/Y', 'd/m/y', 'm/d/Y', 'm/d/y', 'j.n.Y', 'j/n/Y'],
        );
    }

    private function validateMatch(mixed $match, array &$errors): void
    {
        if (! is_array($match) || array_is_list($match)) {
            $this->error($errors, 'match_invalid', 'match', 'Match definition must be a structured object.');

            return;
        }

        $listKeys = [
            'mailboxes',
            'recipients',
            'senders',
            'sender_domains',
            'subject_markers',
            'body_markers',
            'authenticated_supplier_domains',
        ];
        $this->rejectUnknownKeys($match, [
            ...$listKeys,
            'account_ids',
            'require_trusted_auth',
            'require_aligned',
        ], 'match', $errors);

        $accountIds = $match['account_ids'] ?? [];
        if (! is_array($accountIds) || ! array_is_list($accountIds) || count($accountIds) > 50) {
            $this->error($errors, 'match_account_ids_invalid', 'match.account_ids', 'Account IDs must be a bounded list.');
            $accountIds = [];
        } else {
            foreach ($accountIds as $index => $accountId) {
                if (! is_int($accountId) || $accountId <= 0) {
                    $this->error(
                        $errors,
                        'match_account_id_invalid',
                        "match.account_ids.$index",
                        'Account IDs must be positive integers.',
                    );
                }
            }
        }

        $selectorCount = $accountIds !== [] ? 1 : 0;
        foreach ($listKeys as $key) {
            $values = $match[$key] ?? [];
            if ($values !== []) {
                $selectorCount++;
            }
            $this->stringList($values, "match.$key", 50, 500, $errors, allowEmpty: true);

            if (in_array($key, ['recipients', 'senders'], true) && is_array($values)) {
                foreach ($values as $index => $email) {
                    if (! is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                        $this->error($errors, 'match_email_invalid', "match.$key.$index", 'Match email must be exact and valid.');
                    }
                }
            }
            if (in_array($key, ['sender_domains', 'authenticated_supplier_domains'], true) && is_array($values)) {
                foreach ($values as $index => $domain) {
                    if (! is_string($domain)
                        || preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $domain) !== 1) {
                        $this->error($errors, 'match_domain_invalid', "match.$key.$index", 'Match domain must be an exact hostname.');
                    }
                }
            }
        }
        if ($selectorCount === 0) {
            $this->error($errors, 'match_selector_missing', 'match', 'At least one exact source selector is required.');
        }
        $recipients = is_array($match['recipients'] ?? null) ? $match['recipients'] : [];
        $mailboxes = is_array($match['mailboxes'] ?? null) ? $match['mailboxes'] : [];
        if ($recipients === [] && ($accountIds === [] || $mailboxes === [])) {
            $this->error(
                $errors,
                'match_ingress_scope_missing',
                'match',
                'Use exact recipients, or combine an Email account ID with an exact mailbox.',
            );
        }
        foreach (['require_trusted_auth', 'require_aligned'] as $key) {
            if (isset($match[$key]) && ! is_bool($match[$key])) {
                $this->error($errors, 'match_boolean_invalid', "match.$key", 'Trusted-auth controls must be boolean.');
            }
        }
    }

    private function validateFields(mixed $fields, array &$errors): void
    {
        if (! is_array($fields) || array_is_list($fields) || $fields === [] || count($fields) > 40) {
            $this->error($errors, 'fields_invalid', 'fields', 'Fields must be a bounded canonical mapping object.');

            return;
        }

        foreach ($fields as $path => $rule) {
            if (! is_string($path) || ! in_array($path, self::FIELD_PATHS, true)) {
                $this->error($errors, 'field_path_unknown', 'fields.'.(string) $path, 'Unknown canonical field path.');

                continue;
            }
            $this->validateFieldRule($path, $rule, $errors);
        }
    }

    private function validateFieldRule(string $path, mixed $rule, array &$errors): void
    {
        $rulePath = "fields.$path";
        if (! is_array($rule) || array_is_list($rule)) {
            $this->error($errors, 'field_rule_invalid', $rulePath, 'Field rule must be a structured object.');

            return;
        }
        $this->rejectUnknownKeys($rule, [
            'source',
            'type',
            'required',
            'value',
            'labels',
            'pattern',
            'value_offset',
        ], $rulePath, $errors);

        $source = $rule['source'] ?? null;
        if (! in_array($source, ['fixed', 'received_at', 'label', 'regex'], true)) {
            $this->error($errors, 'field_source_invalid', "$rulePath.source", 'Unsupported deterministic field source.');

            return;
        }
        if (! in_array($rule['type'] ?? null, ['string', 'integer', 'decimal', 'date', 'currency'], true)) {
            $this->error($errors, 'field_type_invalid', "$rulePath.type", 'Unsupported deterministic field type.');
        }
        if (isset($rule['required']) && ! is_bool($rule['required'])) {
            $this->error($errors, 'field_required_invalid', "$rulePath.required", 'Required flag must be boolean.');
        }

        if ($source === 'fixed') {
            if (! array_key_exists('value', $rule) || ! is_scalar($rule['value'])) {
                $this->error($errors, 'field_fixed_value_missing', "$rulePath.value", 'Fixed source requires a scalar value.');
            }

            return;
        }
        if ($source === 'received_at') {
            if (($rule['type'] ?? null) !== 'date') {
                $this->error($errors, 'field_received_at_type_invalid', "$rulePath.type", 'Received-at source must produce a date.');
            }

            return;
        }
        if ($source === 'label') {
            $this->stringList($rule['labels'] ?? null, "$rulePath.labels", 30, 255, $errors);
            if (isset($rule['value_offset'])
                && (! is_int($rule['value_offset']) || $rule['value_offset'] < 0 || $rule['value_offset'] > 20)) {
                $this->error($errors, 'field_value_offset_invalid', "$rulePath.value_offset", 'Label value offset must be between zero and twenty blocks.');
            }
        }

        $pattern = $rule['pattern'] ?? null;
        if ($source === 'regex' && ! is_string($pattern)) {
            $this->error($errors, 'field_pattern_missing', "$rulePath.pattern", 'Regex source requires a bounded named-capture pattern.');
        }
        if (is_string($pattern)) {
            $this->validatePattern($pattern, ['value'], "$rulePath.pattern", $errors);
        }
    }

    private function validateLines(mixed $lines, array &$errors): void
    {
        if (! is_array($lines) || array_is_list($lines)) {
            $this->error($errors, 'lines_invalid', 'lines', 'Line mapping must be a structured object.');

            return;
        }
        $this->rejectUnknownKeys($lines, [
            'max_matches',
            'fields',
            'repeated_regex',
            'html_table',
        ], 'lines', $errors);

        $maxMatches = $lines['max_matches'] ?? null;
        if (! is_int($maxMatches) || $maxMatches < 1 || $maxMatches > 500) {
            $this->error($errors, 'line_match_limit_invalid', 'lines.max_matches', 'Line match limit must be between one and five hundred.');
        }

        $fieldRules = $lines['fields'] ?? null;
        if (! is_array($fieldRules) || array_is_list($fieldRules) || $fieldRules === []) {
            $this->error($errors, 'line_fields_invalid', 'lines.fields', 'Line fields must be a canonical mapping object.');
            $fieldRules = [];
        }
        foreach ($fieldRules as $field => $rule) {
            if (! is_string($field) || ! in_array($field, self::LINE_FIELDS, true)) {
                $this->error($errors, 'line_field_unknown', 'lines.fields.'.(string) $field, 'Unknown line field.');

                continue;
            }
            if (! is_array($rule) || array_is_list($rule)) {
                $this->error($errors, 'line_field_rule_invalid', "lines.fields.$field", 'Line field rule must be structured.');

                continue;
            }
            $this->rejectUnknownKeys($rule, [
                'capture',
                'type',
                'required',
                'source_column',
                'pattern',
            ], "lines.fields.$field", $errors);
            if (! is_string($rule['capture'] ?? null)
                || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $rule['capture']) !== 1) {
                $this->error($errors, 'line_capture_invalid', "lines.fields.$field.capture", 'Line capture name is invalid.');
            }
            if (! in_array($rule['type'] ?? null, ['string', 'integer', 'decimal'], true)) {
                $this->error($errors, 'line_type_invalid', "lines.fields.$field.type", 'Unsupported line field type.');
            }
            if (isset($rule['required']) && ! is_bool($rule['required'])) {
                $this->error($errors, 'line_required_invalid', "lines.fields.$field.required", 'Line required flag must be boolean.');
            }
            if (isset($rule['source_column'])
                && (! is_string($rule['source_column']) || ! in_array($rule['source_column'], self::LINE_FIELDS, true))) {
                $this->error($errors, 'line_source_column_invalid', "lines.fields.$field.source_column", 'Source column must be canonical.');
            }
            if (isset($rule['pattern'])) {
                if (! is_string($rule['pattern'])) {
                    $this->error($errors, 'line_field_pattern_invalid', "lines.fields.$field.pattern", 'Line field pattern must be a string.');
                } else {
                    $this->validatePattern($rule['pattern'], ['value'], "lines.fields.$field.pattern", $errors);
                }
            }
        }
        if (! isset($fieldRules['quantity'])
            || (! isset($fieldRules['supplier_sku']) && ! isset($fieldRules['description']))) {
            $this->error($errors, 'line_identity_or_quantity_missing', 'lines.fields', 'Lines require quantity and supplier SKU or description mappings.');
        }

        $hasExtractor = false;
        if (isset($lines['repeated_regex'])) {
            $hasExtractor = true;
            $regex = $lines['repeated_regex'];
            if (! is_array($regex) || array_is_list($regex)) {
                $this->error($errors, 'line_regex_invalid', 'lines.repeated_regex', 'Repeated-regex mapping must be structured.');
            } else {
                $this->rejectUnknownKeys($regex, ['pattern'], 'lines.repeated_regex', $errors);
                $pattern = $regex['pattern'] ?? null;
                if (! is_string($pattern)) {
                    $this->error($errors, 'line_regex_pattern_missing', 'lines.repeated_regex.pattern', 'Repeated-regex pattern is required.');
                } else {
                    $captures = collect($fieldRules)->pluck('capture')->filter()->values()->all();
                    $this->validatePattern($pattern, $captures, 'lines.repeated_regex.pattern', $errors);
                }
            }
        }
        if (isset($lines['html_table'])) {
            $hasExtractor = true;
            $this->validateHtmlTable($lines['html_table'], $errors);
        }
        if (! $hasExtractor) {
            $this->error($errors, 'line_extractor_missing', 'lines', 'Repeated-regex or HTML-table extraction is required.');
        }
    }

    private function validateHtmlTable(mixed $table, array &$errors): void
    {
        if (! is_array($table) || array_is_list($table)) {
            $this->error($errors, 'html_table_invalid', 'lines.html_table', 'HTML-table mapping must be structured.');

            return;
        }
        $this->rejectUnknownKeys($table, ['header_aliases', 'required_columns'], 'lines.html_table', $errors);
        $aliases = $table['header_aliases'] ?? null;
        if (! is_array($aliases) || array_is_list($aliases) || $aliases === []) {
            $this->error($errors, 'table_aliases_invalid', 'lines.html_table.header_aliases', 'Table header aliases are required.');
            $aliases = [];
        }
        foreach ($aliases as $field => $values) {
            if (! is_string($field) || ! in_array($field, self::LINE_FIELDS, true)) {
                $this->error($errors, 'table_alias_field_unknown', 'lines.html_table.header_aliases.'.(string) $field, 'Unknown table column mapping.');

                continue;
            }
            $this->stringList($values, "lines.html_table.header_aliases.$field", 30, 255, $errors);
        }
        $required = $table['required_columns'] ?? null;
        $this->stringList($required, 'lines.html_table.required_columns', 10, 100, $errors);
        if (is_array($required)) {
            foreach ($required as $column) {
                if (! is_string($column) || ! array_key_exists($column, $aliases)) {
                    $this->error($errors, 'table_required_column_unmapped', 'lines.html_table.required_columns', 'Every required table column needs aliases.');
                }
            }
        }
    }

    private function validateDefaults(mixed $defaults, array &$errors): void
    {
        if (! is_array($defaults) || array_is_list($defaults)) {
            $this->error($errors, 'profile_defaults_invalid', 'defaults', 'Profile defaults must be a structured object.');

            return;
        }
        $this->rejectUnknownKeys(
            $defaults,
            ['warehouse_id', 'currency', 'ordered_date_fallback'],
            'defaults',
            $errors,
        );
        if (array_key_exists('warehouse_id', $defaults)
            && $defaults['warehouse_id'] !== null
            && (! is_int($defaults['warehouse_id']) || $defaults['warehouse_id'] < 1)) {
            $this->error($errors, 'profile_default_warehouse_invalid', 'defaults.warehouse_id', 'Warehouse ID must be null or a positive integer.');
        }
        if (array_key_exists('currency', $defaults)
            && (! is_string($defaults['currency'])
                || preg_match('/^[A-Z]{3}$/', $defaults['currency']) !== 1)) {
            $this->error($errors, 'profile_default_currency_invalid', 'defaults.currency', 'Currency must be an uppercase ISO 4217 code.');
        }
        if (array_key_exists('ordered_date_fallback', $defaults)
            && ! in_array($defaults['ordered_date_fallback'], ['received_at'], true)) {
            $this->error($errors, 'profile_default_ordered_date_invalid', 'defaults.ordered_date_fallback', 'Only the pinned received-at date may be used as fallback.');
        }
    }

    private function validateItemDefaults(mixed $defaults, array &$errors): void
    {
        if (! is_array($defaults) || array_is_list($defaults)) {
            $this->error($errors, 'profile_item_defaults_invalid', 'item_defaults', 'Item defaults must be a structured object.');

            return;
        }
        $booleanKeys = ['has_serials', 'track_batch', 'expiry_enabled', 'becomes_asset'];
        $integerRanges = [
            'default_warranty_months' => [0, 1200],
            'lead_time_days' => [0, 3650],
            'moq' => [1, 1000000],
        ];
        $this->rejectUnknownKeys(
            $defaults,
            ['vat_rate', ...$booleanKeys, ...array_keys($integerRanges)],
            'item_defaults',
            $errors,
        );
        if (array_key_exists('vat_rate', $defaults)
            && $defaults['vat_rate'] !== null
            && (! is_numeric($defaults['vat_rate'])
                || (float) $defaults['vat_rate'] < 0
                || (float) $defaults['vat_rate'] > 100)) {
            $this->error($errors, 'profile_item_default_vat_invalid', 'item_defaults.vat_rate', 'VAT rate must be null or between zero and one hundred.');
        }
        foreach ($booleanKeys as $key) {
            if (array_key_exists($key, $defaults) && ! is_bool($defaults[$key])) {
                $this->error($errors, 'profile_item_default_boolean_invalid', "item_defaults.$key", 'Item tracking defaults must be boolean.');
            }
        }
        foreach ($integerRanges as $key => [$minimum, $maximum]) {
            if (! array_key_exists($key, $defaults) || $defaults[$key] === null) {
                continue;
            }
            if (! is_int($defaults[$key])
                || $defaults[$key] < $minimum
                || $defaults[$key] > $maximum) {
                $this->error($errors, 'profile_item_default_integer_invalid', "item_defaults.$key", 'Item default is outside the safe range.');
            }
        }
    }

    private function validateRules(mixed $rules, array &$errors): void
    {
        if (! is_array($rules) || array_is_list($rules)) {
            $this->error($errors, 'validation_invalid', 'validation', 'Validation rules must be structured.');

            return;
        }
        $this->rejectUnknownKeys($rules, [
            'required_fields',
            'amount_tolerance',
            'max_lines',
            'max_quantity',
            'max_order_total',
        ], 'validation', $errors);
        $this->stringList($rules['required_fields'] ?? null, 'validation.required_fields', 40, 120, $errors, allowed: self::FIELD_PATHS);
        foreach ([
            'amount_tolerance' => [0, 100],
            'max_order_total' => [0, 1000000000],
        ] as $key => [$minimum, $maximum]) {
            if (! is_numeric($rules[$key] ?? null)
                || (float) $rules[$key] < $minimum
                || (float) $rules[$key] > $maximum) {
                $this->error($errors, 'validation_numeric_invalid', "validation.$key", 'Validation amount is outside the safe range.');
            }
        }
        foreach ([
            'max_lines' => [1, 500],
            'max_quantity' => [1, 1000000],
        ] as $key => [$minimum, $maximum]) {
            if (! is_int($rules[$key] ?? null)
                || $rules[$key] < $minimum
                || $rules[$key] > $maximum) {
                $this->error($errors, 'validation_integer_invalid', "validation.$key", 'Validation limit is outside the safe range.');
            }
        }
    }

    private function validatePattern(string $pattern, array $captures, string $path, array &$errors): void
    {
        foreach ($this->safeRegex->errors($pattern, $captures) as $code) {
            $this->error($errors, $code, $path, 'Pattern uses an unsafe, unbounded, or unsupported construct.');
        }
    }

    private function validateBoundsAndForbiddenValues(
        mixed $value,
        string $path,
        int $depth,
        int &$nodes,
        array &$errors,
    ): void {
        if ($depth > self::MAX_DEPTH || ++$nodes > self::MAX_NODES) {
            $this->error($errors, 'definition_bounds_exceeded', $path, 'Profile definition exceeds safe depth or node limits.');

            return;
        }
        if (is_array($value)) {
            if (count($value) > 500) {
                $this->error($errors, 'definition_collection_too_large', $path, 'Profile collection exceeds the safe count.');
            }
            foreach ($value as $key => $item) {
                $keyPath = $path.'.'.(string) $key;
                if (is_string($key) && in_array(mb_strtolower($key), self::FORBIDDEN_KEYS, true)) {
                    $this->error($errors, 'definition_executable_key_forbidden', $keyPath, 'Executable, remote, or provider keys are forbidden.');
                }
                $this->validateBoundsAndForbiddenValues($item, $keyPath, $depth + 1, $nodes, $errors);
            }

            return;
        }
        if (is_object($value) || is_resource($value)) {
            $this->error($errors, 'definition_value_type_forbidden', $path, 'Only JSON-compatible declarative values are allowed.');

            return;
        }
        if (is_string($value)) {
            if (mb_strlen($value) > 4096) {
                $this->error($errors, 'definition_string_too_long', $path, 'Profile string exceeds the safe limit.');
            }
            if (preg_match('~https?://|(?:javascript|data):|<\?php|::class|\b(?:eval|exec|system|shell_exec|passthru|proc_open|popen)\s*\(~iu', $value) === 1) {
                $this->error($errors, 'definition_executable_value_forbidden', $path, 'Remote URLs and executable constructs are forbidden.');
            }
        }
    }

    private function rejectUnknownKeys(array $value, array $allowed, string $path, array &$errors): void
    {
        foreach (array_diff(array_keys($value), $allowed) as $key) {
            $this->error($errors, 'definition_key_unknown', $path.'.'.(string) $key, 'Unknown profile-definition key.');
        }
    }

    private function stringList(
        mixed $value,
        string $path,
        int $maxItems,
        int $maxLength,
        array &$errors,
        ?array $allowed = null,
        bool $allowEmpty = false,
    ): void {
        if (! is_array($value) || ! array_is_list($value) || (! $allowEmpty && $value === []) || count($value) > $maxItems) {
            $this->error($errors, 'definition_string_list_invalid', $path, 'Expected a non-empty bounded string list.');

            return;
        }
        foreach ($value as $index => $item) {
            if (! is_string($item)
                || (trim($item) === '' && ($allowed === null || ! in_array($item, $allowed, true)))
                || mb_strlen($item) > $maxLength
                || ($allowed !== null && ! in_array($item, $allowed, true))) {
                $this->error($errors, 'definition_string_list_item_invalid', "$path.$index", 'String-list item is invalid or outside the allowlist.');
            }
        }
        if (count($value) !== count(array_unique($value))) {
            $this->error($errors, 'definition_string_list_duplicate', $path, 'String lists cannot contain duplicates.');
        }
    }

    private function exact(mixed $actual, string $expected, string $path, array &$errors): void
    {
        if ($actual !== $expected) {
            $this->error($errors, 'definition_value_invalid', $path, "Expected {$expected}.");
        }
    }

    private function error(array &$errors, string $code, string $path, string $message): void
    {
        $errors[] = compact('code', 'path', 'message');
    }
}
