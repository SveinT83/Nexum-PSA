<?php

namespace App\Modules\Commercial\Support;

use App\Modules\Commercial\Models\Contracts\ContractItem;
use App\Modules\Commercial\Models\Contracts\Contracts;
use App\Modules\System\Support\CompanyProfileSettings;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use UnexpectedValueException;

/**
 * Project one immutable, customer-safe contract document for web, API and PDF.
 */
final class ContractCustomerDocument
{
    public const SCHEMA_VERSION = 1;

    public const COLUMNS = [
        'service' => 'Tjeneste',
        'short_description' => 'Kort beskrivelse',
        'scope' => 'Omfang',
        'unit_price' => 'Enhetspris',
        'billing' => 'Fakturering',
        'total' => 'Sum',
    ];

    private const DATE_LABELS = [
        'start' => 'Avtalestart',
        'end' => 'Avtalens sluttdato',
        'binding_end' => 'Bindingstid til',
        'auto_renew' => 'Automatisk fornyelse',
    ];

    private const PARTY_LABELS = [
        'supplier' => 'Leverandør',
        'customer' => 'Kunde',
    ];

    private const RATES_TITLE = 'Satser for arbeid utenfor avtalt omfang';

    private const SUPPORT_TITLE = 'Support og responstid';

    private const SNAPSHOT_KEYS = [
        'schema_version',
        'document',
        'description',
        'dates',
        'parties',
        'approval',
        'columns',
        'lines',
        'totals',
        'rates',
        'support',
        'appendices',
    ];

    public function __construct(
        private readonly ContractPricing $pricing,
        private readonly CompanyProfileSettings $companyProfile,
        private readonly ContractLegacyDocumentReadiness $legacyReadiness,
    ) {}

    /**
     * Prefer the captured document for sent/accepted contracts and only overlay
     * the factual acceptance event, which occurs after the economic snapshot.
     */
    public function resolve(
        Contracts $contract,
        ?array $companyProfile = null,
        ?string $statusOverride = null,
    ): array {
        $snapshot = $this->storedSnapshot($contract);

        if ($snapshot !== null) {
            return $this->resolveVersionOne($snapshot, $contract, $statusOverride);
        }

        $this->legacyReadiness->assertSafeProjection($contract);

        return $this->build($contract, $companyProfile, $statusOverride);
    }

    /**
     * Build an explicitly internal, read-only reconstruction aid when a legacy
     * document has no immutable snapshot. Customer delivery must use resolve().
     */
    public function previewForInternalReview(
        Contracts $contract,
        ?array $companyProfile = null,
        ?string $statusOverride = null,
    ): array {
        $snapshot = $this->storedSnapshot($contract);

        if ($snapshot !== null) {
            return $this->resolveVersionOne($snapshot, $contract, $statusOverride);
        }

        return $this->build($contract, $companyProfile, $statusOverride);
    }

    /**
     * Build the exact stable document a technician must compare with original
     * evidence before attesting a legacy reconstruction.
     */
    public function previewForLegacyAttestation(
        Contracts $contract,
        ?array $companyProfile = null,
        ?string $documentType = null,
    ): array {
        $snapshot = $this->storedSnapshot($contract);

        if ($snapshot !== null) {
            return $this->resolveVersionOne($snapshot, $contract, null);
        }

        $preview = $this->build(
            $contract,
            $companyProfile,
            null,
            $this->legacyAttestationTimestamp($contract),
        );

        if ($documentType !== null) {
            if (! in_array($documentType, ['Tilbud', 'Avtale'], true)) {
                $this->unsupportedSnapshot();
            }

            $preview['document']['type'] = $documentType;
            $preview['approval'] = $this->approval($contract, $documentType);
        }

        return $preview;
    }

    /**
     * Hash a complete v1 document exactly as presented for manual review.
     */
    public function fingerprint(array $snapshot): string
    {
        $this->assertSupportedSnapshot($snapshot);

        return hash('sha256', json_encode(
            $snapshot,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
    }

    /**
     * Treat every non-null database value as immutable snapshot evidence.
     * Invalid JSON shapes must fail closed instead of falling back to live rows.
     */
    public function hasStoredSnapshot(Contracts $contract): bool
    {
        $attributes = $contract->getAttributes();

        return array_key_exists('customer_document_snapshot', $attributes)
            && $attributes['customer_document_snapshot'] !== null;
    }

    /**
     * Return a supported stored snapshot, or null only when the column is null.
     */
    public function storedSnapshot(Contracts $contract): ?array
    {
        if (! $this->hasStoredSnapshot($contract)) {
            return null;
        }

        $snapshot = $contract->customer_document_snapshot;

        if (! is_array($snapshot)) {
            $this->unsupportedSnapshot();
        }

        $this->assertSupportedSnapshot($snapshot);

        return $snapshot;
    }

    /**
     * Validate the complete immutable v1 envelope before it is trusted or
     * persisted. A schema marker alone is never a customer document.
     */
    public function assertSupportedSnapshot(array $snapshot): void
    {
        $rules = [
            'schema_version' => ['required', 'integer', 'in:'.self::SCHEMA_VERSION],
            'document' => ['required', 'array:type,status,contract_number,generated_at,generated_date'],
            'document.type' => ['required', 'string', 'in:Tilbud,Avtaleutkast,Avtale'],
            'document.status' => ['required', 'string'],
            'document.contract_number' => ['required', 'string'],
            'document.generated_at' => ['required', 'string', 'regex:/^\d{2}\.\d{2}\.\d{4} \d{2}:\d{2}$/D'],
            'document.generated_date' => ['required', 'string', 'regex:/^\d{2}\.\d{2}\.\d{4}$/D'],
            'description' => ['present', 'string'],
            'dates' => ['required', 'array:start,end,binding_end,auto_renew', 'size:4'],
            'dates.start' => ['required', 'array:label,value'],
            'dates.start.label' => ['required', 'string'],
            'dates.start.value' => ['required', 'string', 'regex:/^\d{2}\.\d{2}\.\d{4}$/D'],
            'dates.end' => ['required', 'array:label,value'],
            'dates.end.label' => ['required', 'string'],
            'dates.end.value' => ['present', 'nullable', 'string', 'regex:/^\d{2}\.\d{2}\.\d{4}$/D'],
            'dates.binding_end' => ['required', 'array:label,value'],
            'dates.binding_end.label' => ['required', 'string'],
            'dates.binding_end.value' => ['present', 'nullable', 'string', 'regex:/^\d{2}\.\d{2}\.\d{4}$/D'],
            'dates.auto_renew' => ['required', 'array:label,value'],
            'dates.auto_renew.label' => ['required', 'string'],
            'dates.auto_renew.value' => ['required', 'string'],
            'parties' => ['required', 'array:supplier,customer', 'size:2'],
            'parties.supplier' => ['required', 'array:label,name,organization_number'],
            'parties.supplier.label' => ['required', 'string'],
            'parties.supplier.name' => ['required', 'string'],
            'parties.supplier.organization_number' => ['required', 'string'],
            'parties.customer' => ['required', 'array:label,name,organization_number'],
            'parties.customer.label' => ['required', 'string'],
            'parties.customer.name' => ['required', 'string'],
            'parties.customer.organization_number' => ['required', 'string'],
            'approval' => ['required', 'array:accepted,title,name,date,text'],
            'approval.accepted' => ['required', 'boolean'],
            'approval.title' => ['required', 'string'],
            'approval.name' => ['present', 'nullable', 'string'],
            'approval.date' => ['present', 'nullable', 'string', 'regex:/^\d{2}\.\d{2}\.\d{4} \d{2}:\d{2}$/D'],
            'approval.text' => ['required', 'string'],
            'columns' => ['required', 'array:service,short_description,scope,unit_price,billing,total', 'size:6'],
            'columns.service' => ['required', 'string'],
            'columns.short_description' => ['required', 'string'],
            'columns.scope' => ['required', 'string'],
            'columns.unit_price' => ['required', 'string'],
            'columns.billing' => ['required', 'string'],
            'columns.total' => ['required', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*' => ['required', 'array:service,short_description,scope,unit_price,billing,total'],
            'lines.*.service' => ['required', 'string'],
            'lines.*.short_description' => ['required', 'string'],
            'lines.*.scope' => ['required', 'string'],
            'lines.*.billing' => ['required', 'array:cadence,label,setup_fee'],
            'lines.*.billing.cadence' => ['required', 'string', 'in:monthly,quarterly,yearly,one_time'],
            'lines.*.billing.label' => ['required', 'string'],
            'lines.*.total.included' => ['required', 'boolean'],
            'totals' => ['required', 'array:monthly,quarterly,yearly,one_time', 'size:4'],
            'rates' => ['present', 'nullable', 'array:title,items', 'min:2'],
            'rates.title' => ['required_with:rates', 'string'],
            'rates.items' => ['required_with:rates', 'array', 'min:1'],
            'rates.items.*' => ['required', 'array:name,type,amount,currency,unit,unit_label,display'],
            'rates.items.*.name' => ['required', 'string'],
            'rates.items.*.type' => ['required', 'string'],
            'rates.items.*.currency' => ['required', 'string', 'size:3'],
            'rates.items.*.unit' => ['required', 'string'],
            'rates.items.*.unit_label' => ['required', 'string'],
            'rates.items.*.display' => ['required', 'string'],
            'support' => ['present', 'nullable', 'array:title,content', 'min:2'],
            'support.title' => ['required_with:support', 'string'],
            'support.content' => ['required_with:support', 'string'],
            'appendices' => ['required', 'array', 'min:1'],
            'appendices.*' => ['required', 'array:number,title,version,date,content'],
            'appendices.*.number' => ['required', 'integer', 'min:1'],
            'appendices.*.title' => ['required', 'string'],
            'appendices.*.version' => ['required', 'string'],
            'appendices.*.date' => ['required', 'string', 'regex:/^\d{2}\.\d{2}\.\d{4}$/D'],
            'appendices.*.content' => ['required', 'string'],
        ];

        foreach (['monthly', 'quarterly', 'yearly', 'one_time'] as $cadence) {
            $this->addAmountRules($rules, 'totals.'.$cadence);
        }

        foreach (['lines.*.unit_price', 'lines.*.billing.setup_fee', 'rates.items.*.amount'] as $path) {
            $this->addAmountRules($rules, $path);
        }
        $this->addAmountRules($rules, 'lines.*.total', true);

        if (Validator::make($snapshot, $rules)->fails()) {
            $this->unsupportedSnapshot();
        }

        // Column labels and order are part of the versioned presentation
        // contract. A shape-compatible but reordered table is not schema v1.
        if (($snapshot['columns'] ?? null) !== self::COLUMNS) {
            $this->unsupportedSnapshot();
        }

        $this->assertCanonicalSnapshot($snapshot);
    }

    /** @param array<string, array<int, string>> $rules */
    private function addAmountRules(array &$rules, string $path, bool $includesIncluded = false): void
    {
        $keys = $includesIncluded
            ? 'array:minor,decimal,display,included'
            : 'array:minor,decimal,display';
        $rules[$path] = ['required', $keys];
        $rules[$path.'.minor'] = ['required', 'integer', 'min:0'];
        $rules[$path.'.decimal'] = ['required', 'string', 'regex:/^\d+\.\d{2}$/D'];
        $rules[$path.'.display'] = ['required', 'string'];
    }

    /**
     * Require every redundant money representation in immutable evidence to
     * describe the same exact amount. Never repair a stored snapshot in place.
     */
    private function assertCanonicalSnapshot(array $snapshot): void
    {
        $this->assertExactKeys($snapshot, self::SNAPSHOT_KEYS);

        if ($snapshot['schema_version'] !== self::SCHEMA_VERSION
            || ! is_bool($snapshot['approval']['accepted'])
            || ! array_is_list($snapshot['lines'])
            || ! array_is_list($snapshot['appendices'])
            || ($snapshot['rates'] !== null && ! array_is_list($snapshot['rates']['items']))) {
            $this->unsupportedSnapshot();
        }

        if (array_keys($snapshot['dates']) !== array_keys(self::DATE_LABELS)
            || array_keys($snapshot['parties']) !== array_keys(self::PARTY_LABELS)) {
            $this->unsupportedSnapshot();
        }

        foreach (self::DATE_LABELS as $key => $label) {
            if ($snapshot['dates'][$key]['label'] !== $label) {
                $this->unsupportedSnapshot();
            }
        }

        foreach (self::PARTY_LABELS as $key => $label) {
            if ($snapshot['parties'][$key]['label'] !== $label) {
                $this->unsupportedSnapshot();
            }
        }

        if (($snapshot['rates']['title'] ?? self::RATES_TITLE) !== self::RATES_TITLE
            || ($snapshot['support']['title'] ?? self::SUPPORT_TITLE) !== self::SUPPORT_TITLE) {
            $this->unsupportedSnapshot();
        }

        foreach ($snapshot['totals'] as $amount) {
            $this->assertCanonicalAmount($amount);
        }

        $calculatedTotals = array_fill_keys(['monthly', 'quarterly', 'yearly', 'one_time'], 0);

        foreach ($snapshot['lines'] as $line) {
            $included = $line['total']['included'];
            $includedDisplay = $included ? 'Inkludert' : null;
            $cadence = $line['billing']['cadence'];

            if (! is_bool($included)
                || $line['billing']['label'] !== $this->billingLabel($cadence)) {
                $this->unsupportedSnapshot();
            }

            $this->assertCanonicalAmount($line['unit_price'], 'NOK', $includedDisplay);
            $this->assertCanonicalAmount($line['billing']['setup_fee']);
            $this->assertCanonicalAmount($line['total'], 'NOK', $includedDisplay);

            $mustBeIncluded = $line['total']['minor'] === 0
                && $line['billing']['setup_fee']['minor'] === 0;

            if ($included !== $mustBeIncluded) {
                $this->unsupportedSnapshot();
            }

            $calculatedTotals[$cadence] = $this->addSnapshotMinor(
                $calculatedTotals[$cadence],
                $line['total']['minor'],
            );
            $calculatedTotals['one_time'] = $this->addSnapshotMinor(
                $calculatedTotals['one_time'],
                $line['billing']['setup_fee']['minor'],
            );
        }

        $rateIdentities = [];
        foreach (($snapshot['rates']['items'] ?? []) as $rate) {
            $this->assertCanonicalAmount($rate['amount'], $rate['currency']);

            $expectedDisplay = $rate['amount']['display'].' / '.$rate['unit_label'];
            if ($rate['currency'] !== strtoupper($rate['currency'])
                || $rate['unit_label'] !== $this->rateUnitLabel($rate['unit'])
                || $rate['display'] !== $expectedDisplay) {
                $this->unsupportedSnapshot();
            }

            $identity = implode('|', [
                mb_strtolower(trim($rate['name']), 'UTF-8'),
                mb_strtolower(trim($rate['type']), 'UTF-8'),
                (string) $rate['amount']['minor'],
                $rate['currency'],
                mb_strtolower(trim($rate['unit']), 'UTF-8'),
            ]);
            if (isset($rateIdentities[$identity])) {
                $this->unsupportedSnapshot();
            }
            $rateIdentities[$identity] = true;
        }

        foreach ($calculatedTotals as $cadence => $minor) {
            if ($snapshot['totals'][$cadence]['minor'] !== $minor) {
                $this->unsupportedSnapshot();
            }
        }

        foreach (array_values($snapshot['appendices']) as $index => $appendix) {
            if ($appendix['number'] !== $index + 1
                || strcasecmp(trim($appendix['version']), 'Unversioned') === 0) {
                $this->unsupportedSnapshot();
            }
        }
    }

    /**
     * Unknown fields are an unsupported future schema, not customer-safe v1.
     * Key order is presentation-significant only where checked separately.
     *
     * @param  array<string, mixed>  $value
     * @param  array<int, string>  $expected
     */
    private function assertExactKeys(array $value, array $expected): void
    {
        $actual = array_keys($value);
        sort($actual);
        sort($expected);

        if ($actual !== $expected) {
            $this->unsupportedSnapshot();
        }
    }

    private function addSnapshotMinor(int $current, int $amount): int
    {
        if ($amount > PHP_INT_MAX - $current) {
            $this->unsupportedSnapshot();
        }

        return $current + $amount;
    }

    /** @param array{minor: int, decimal: string, display: string} $amount */
    private function assertCanonicalAmount(
        array $amount,
        string $currency = 'NOK',
        ?string $displayOverride = null,
    ): void {
        $minor = $amount['minor'];
        $expectedDisplay = $displayOverride ?? $this->pricing->formatMinor($minor, $currency);

        if (! is_int($minor)
            || $amount['decimal'] !== $this->pricing->decimalFromMinor($minor)
            || $amount['display'] !== $expectedDisplay) {
            $this->unsupportedSnapshot();
        }
    }

    private function unsupportedSnapshot(): never
    {
        throw new UnexpectedValueException(
            'Unsupported customer document snapshot schema; refusing to rebuild an immutable document.'
        );
    }

    private function resolveVersionOne(array $snapshot, Contracts $contract, ?string $statusOverride): array
    {
        $status = $statusOverride ?? (string) $contract->approval_status;
        $documentType = (string) ($snapshot['document']['type'] ?? $this->documentType($status));
        $snapshot['document']['status'] = $this->statusLabel($status);
        $snapshot['approval'] = $this->approval($contract, $documentType);

        return $snapshot;
    }

    /**
     * Build a point-in-time projection. Catalogue fallbacks are permitted only
     * while the contract is editable; legacy accepted rows fall back to their
     * own snapshotted line name.
     */
    public function build(
        Contracts $contract,
        ?array $companyProfile = null,
        ?string $statusOverride = null,
        ?CarbonInterface $capturedAt = null,
    ): array {
        $relations = ['client', 'items.timeRates', 'termSnapshots.termVersion'];

        if ($contract->isEditable()) {
            $relations[] = 'items.service';
        }

        $contract->loadMissing($relations);
        $profile = $companyProfile ?? $this->companyProfile->get();
        $status = $statusOverride ?? (string) $contract->approval_status;
        $capturedAt ??= now();
        $documentType = $this->documentType($status);
        $items = $contract->items
            ->sortBy('id')
            ->values();
        $lines = $items
            ->map(fn (ContractItem $item): array => $this->line($item, $contract->isEditable()))
            ->all();
        $totals = $this->pricing->calculateTotals($items);
        $rates = $this->rates($items);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'document' => [
                'type' => $documentType,
                'status' => $this->statusLabel($status),
                'contract_number' => (string) $contract->id,
                'generated_at' => $capturedAt->format('d.m.Y H:i'),
                'generated_date' => $capturedAt->format('d.m.Y'),
            ],
            'description' => $this->plainText($contract->description),
            'dates' => [
                'start' => $this->dateValue('Avtalestart', $contract->start_date),
                'end' => $this->dateValue('Avtalens sluttdato', $contract->end_date),
                'binding_end' => $this->dateValue('Bindingstid til', $contract->binding_end_date),
                'auto_renew' => [
                    'label' => 'Automatisk fornyelse',
                    'value' => $contract->auto_renew
                        ? 'Ja, med '.((int) ($contract->renewal_months ?: 12)).' måneder'
                        : 'Nei',
                ],
            ],
            'parties' => [
                'supplier' => [
                    'label' => 'Leverandør',
                    'name' => $profile['legal_name'] ?: ($profile['company_name'] ?? config('app.name')),
                    'organization_number' => $profile['organization_number'] ?? null,
                ],
                'customer' => [
                    'label' => 'Kunde',
                    'name' => $contract->client?->name,
                    'organization_number' => $contract->client?->org_no,
                ],
            ],
            'approval' => $this->approval($contract, $documentType),
            'columns' => self::COLUMNS,
            'lines' => $lines,
            'totals' => $totals,
            'rates' => $rates === [] ? null : [
                'title' => self::RATES_TITLE,
                'items' => $rates,
            ],
            'support' => $this->support($contract),
            'appendices' => $this->appendices($contract, $capturedAt),
        ];
    }

    /**
     * Convert catalogue/editor text to safe UTF-8 plain text while retaining
     * meaningful customer-authored line breaks.
     */
    public function plainText(?string $value): string
    {
        if ($value === null || trim($value) === '') {
            return '';
        }

        $plain = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plain = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', '', $plain) ?? $plain;
        $plain = preg_replace('/<br\s*\/?>/i', "\n", $plain) ?? $plain;
        $plain = preg_replace('/<\/(p|div|li|h[1-6])>/i', "\n", $plain) ?? $plain;
        $plain = strip_tags($plain);
        $plain = preg_replace("/\r\n?/", "\n", $plain) ?? $plain;
        $plain = preg_replace('/[ \t\x{00A0}]+/u', ' ', $plain) ?? $plain;
        $plain = preg_replace('/ *\n */u', "\n", $plain) ?? $plain;
        $plain = preg_replace('/\n{3,}/', "\n\n", $plain) ?? $plain;

        return trim($plain);
    }

    private function line(ContractItem $item, bool $mayUseCatalogue): array
    {
        $calculation = $this->pricing->calculateLine($item);
        $description = $this->plainText($item->customer_description);

        if ($description === '' && $mayUseCatalogue) {
            $description = $this->plainText($item->service?->short_description);
        }

        if ($description === '') {
            $description = $this->plainText($item->name);
        }

        $quantity = (int) $calculation['quantity'];
        $unit = $quantity === 1
            ? ($item->customer_unit_singular ?: $item->unit)
            : ($item->customer_unit_plural ?: $item->unit);
        $scope = trim($quantity.' '.$this->plainText((string) $unit));

        $unitPrice = $calculation['unit_price'];
        $unitPrice['display'] = $calculation['included']
            ? 'Inkludert'
            : $unitPrice['display'];
        $total = $calculation['line_total'];
        $total['display'] = $calculation['included']
            ? 'Inkludert'
            : $total['display'];
        $total['included'] = $calculation['included'];

        return [
            'service' => $this->plainText($item->name),
            'short_description' => $description,
            'scope' => $scope,
            'unit_price' => $unitPrice,
            'billing' => [
                'cadence' => $calculation['cadence'],
                'label' => $this->billingLabel($calculation['cadence']),
                'setup_fee' => $calculation['setup_fee'],
            ],
            'total' => $total,
        ];
    }

    private function rates(Collection $items): array
    {
        $unique = [];

        foreach ($items as $item) {
            foreach ($item->timeRates as $rate) {
                if (! $rate->is_active || ! $rate->is_customer_visible) {
                    continue;
                }

                $amount = $this->pricing->calculateLine([
                    'unit_price' => $rate->amount_ex_vat,
                    'quantity' => 1,
                    'billing_interval' => 'one_time',
                    'discount_value' => 0,
                    'discount_type' => null,
                    'setup_fee' => 0,
                ])['unit_price'];

                if ($amount['minor'] <= 0) {
                    continue;
                }

                $currency = strtoupper(trim((string) $rate->currency)) ?: 'NOK';
                $amount['display'] = $this->pricing->formatMinor($amount['minor'], $currency);
                $key = implode('|', [
                    mb_strtolower(trim((string) $rate->name), 'UTF-8'),
                    mb_strtolower(trim((string) $rate->rate_type), 'UTF-8'),
                    (string) $amount['minor'],
                    $currency,
                    mb_strtolower(trim((string) $rate->unit), 'UTF-8'),
                ]);

                if (isset($unique[$key])) {
                    continue;
                }

                $unique[$key] = [
                    'name' => $this->plainText($rate->name),
                    'type' => (string) $rate->rate_type,
                    'amount' => $amount,
                    'currency' => $currency,
                    'unit' => (string) $rate->unit,
                    'unit_label' => $this->rateUnitLabel((string) $rate->unit),
                    'display' => $amount['display'].' / '.$this->rateUnitLabel((string) $rate->unit),
                ];
            }
        }

        ksort($unique, SORT_STRING);

        return array_values($unique);
    }

    private function support(Contracts $contract): ?array
    {
        $content = $this->plainText($contract->sla_snapshot);

        if ($content === '') {
            return null;
        }

        return [
            'title' => self::SUPPORT_TITLE,
            'content' => $content,
        ];
    }

    private function appendices(Contracts $contract, CarbonInterface $capturedAt): array
    {
        $definitions = [
            'terms' => ['field' => 'terms_snapshot', 'title' => 'Alminnelige avtalevilkår'],
            'dpa' => ['field' => 'dpa_snapshot', 'title' => 'Databehandleravtale'],
            'legal' => ['field' => 'legal_snapshot', 'title' => 'Juridiske vilkår og personvern'],
            'general' => ['field' => 'general_snapshot', 'title' => 'Generelle merknader'],
        ];
        $appendices = [];

        foreach ($definitions as $type => $definition) {
            $content = $this->plainText($contract->{$definition['field']});

            if ($content === '') {
                continue;
            }

            $typeSnapshots = $contract->termSnapshots->where('type', $type);
            $contractSnapshots = $typeSnapshots
                ->filter(fn ($snapshot): bool => data_get(
                    $snapshot->metadata,
                    'contract_snapshot_field',
                ) === $definition['field']);
            $versionSnapshots = $contractSnapshots->isNotEmpty()
                ? $contractSnapshots
                : $typeSnapshots;
            $versions = $versionSnapshots
                ->map(fn ($snapshot): ?string => filled($snapshot->version_label)
                    ? $this->customerVersionLabel((string) $snapshot->version_label)
                    : null)
                ->filter()
                ->unique()
                ->sortBy(fn (string $version): string => mb_strtolower($version, 'UTF-8'))
                ->values();
            $dates = $versionSnapshots
                ->map(fn ($snapshot) => $snapshot->termVersion?->effective_at
                    ?? $snapshot->termVersion?->provider_published_at
                    ?? $snapshot->created_at)
                ->filter()
                ->sort();

            $appendices[] = [
                'number' => count($appendices) + 1,
                'title' => $definition['title'],
                'version' => $versions->isEmpty()
                    ? '1 (kontraktsspesifikk)'
                    : $versions->implode(', '),
                'date' => $this->formatDate($dates->first() ?? $capturedAt),
                'content' => $content,
            ];
        }

        return $appendices;
    }

    private function customerVersionLabel(string $version): string
    {
        $version = trim($version);

        return strcasecmp($version, 'Unversioned') === 0
            ? 'ikke versjonert'
            : $version;
    }

    private function legacyAttestationTimestamp(Contracts $contract): CarbonInterface
    {
        foreach ([
            $contract->sent_at,
            $contract->accepted_at,
            $contract->approval_approved_at,
            $contract->created_at,
            $contract->updated_at,
        ] as $timestamp) {
            if ($timestamp instanceof CarbonInterface) {
                return $timestamp;
            }
        }

        return now();
    }

    private function approval(Contracts $contract, string $documentType): array
    {
        $status = (string) $contract->approval_status;
        $accepted = $contract->accepted_at !== null || in_array($status, ['approved', 'won'], true);
        $acceptedAt = $contract->accepted_at;
        $acceptedByName = $contract->accepted_by_name;

        if ($status === 'approved') {
            $acceptedAt ??= $contract->approval_approved_at;

        }

        $acceptedByName = $this->approvalName($acceptedByName);
        $acceptedAtFormatted = $this->formatDateTime($acceptedAt);
        $isOffer = $documentType === 'Tilbud';
        $verb = $isOffer ? 'Akseptert' : 'Godkjent';

        return [
            'accepted' => $accepted,
            'title' => $accepted ? ($isOffer ? 'Akseptert tilbud' : 'Godkjent avtale') : 'Godkjenning',
            'name' => $accepted ? $acceptedByName : null,
            'date' => $acceptedAtFormatted,
            'text' => $accepted
                ? $verb.' av '.$acceptedByName.($acceptedAtFormatted ? ' '.$acceptedAtFormatted : '')
                : 'Dokumentet er ikke godkjent.',
        ];
    }

    private function approvalName(mixed $name): string
    {
        $name = $this->plainText(is_string($name) ? $name : null);

        return $name === '' || strcasecmp($name, 'Internal Approval') === 0
            ? 'Intern godkjenning'
            : $name;
    }

    private function dateValue(string $label, mixed $date): array
    {
        return [
            'label' => $label,
            'value' => $this->formatDate($date),
        ];
    }

    private function formatDate(mixed $date): ?string
    {
        if ($date instanceof CarbonInterface) {
            return $date->format('d.m.Y');
        }

        return filled($date) ? (string) $date : null;
    }

    private function formatDateTime(mixed $date): ?string
    {
        if ($date instanceof CarbonInterface) {
            return $date->format('d.m.Y H:i');
        }

        return filled($date) ? (string) $date : null;
    }

    private function documentType(string $status): string
    {
        return match ($status) {
            'sent_quote' => 'Tilbud',
            'sent_contract', 'approved', 'won' => 'Avtale',
            default => 'Avtaleutkast',
        };
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'draft' => 'Utkast',
            'pending' => 'Avventer behandling',
            'negotiation' => 'Under forhandling',
            'sent_quote' => 'Tilbud sendt',
            'sent_contract' => 'Avtale sendt',
            'approved', 'won' => 'Godkjent',
            'rejected', 'quote_lost', 'lost' => 'Ikke akseptert',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    private function billingLabel(string $cadence): string
    {
        return match ($cadence) {
            'monthly' => 'Månedlig',
            'quarterly' => 'Kvartalsvis',
            'yearly' => 'Årlig',
            'one_time' => 'Engangsbeløp',
        };
    }

    private function rateUnitLabel(string $unit): string
    {
        return match ($unit) {
            'hour' => 'time',
            'km' => 'km',
            'fixed' => 'fastpris',
            default => $this->plainText($unit),
        };
    }
}
