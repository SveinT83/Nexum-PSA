<?php

use App\Modules\Commercial\Support\ContractPricing;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const EDITABLE_CONTRACT_STATUSES = [
        'draft',
        'negotiation',
        'quote_lost',
    ];

    private const CUSTOMER_VISIBLE_DEFAULT_RATE_CODES = [
        'TIME_WITHOUT_CONTRACT',
        'TIME_WITH_CONTRACT',
        'DRIVING',
    ];

    public function up(): void
    {
        // MySQL/MariaDB DDL is not transaction-safe. Validate every legacy
        // pricing row first and make each column addition rerunnable if a later
        // schema statement fails for an environmental reason.
        $this->assertPricingBackfillIsSupported();

        if (! Schema::hasColumn('contracts', 'customer_document_snapshot')) {
            Schema::table('contracts', function (Blueprint $table): void {
                $table->json('customer_document_snapshot')->nullable();
            });
        }

        if (! Schema::hasColumn('contract_items', 'customer_description')) {
            Schema::table('contract_items', function (Blueprint $table): void {
                $table->text('customer_description')->nullable();
            });
        }

        if (! Schema::hasColumn('contract_items', 'customer_unit_singular')) {
            Schema::table('contract_items', function (Blueprint $table): void {
                $table->string('customer_unit_singular')->nullable();
            });
        }

        if (! Schema::hasColumn('contract_items', 'customer_unit_plural')) {
            Schema::table('contract_items', function (Blueprint $table): void {
                $table->string('customer_unit_plural')->nullable();
            });
        }

        if (! Schema::hasColumn('services', 'customer_unit_singular')) {
            Schema::table('services', function (Blueprint $table): void {
                $table->string('customer_unit_singular')->nullable();
            });
        }

        if (! Schema::hasColumn('services', 'customer_unit_plural')) {
            Schema::table('services', function (Blueprint $table): void {
                $table->string('customer_unit_plural')->nullable();
            });
        }

        if (! Schema::hasColumn('time_rates', 'is_customer_visible')) {
            Schema::table('time_rates', function (Blueprint $table): void {
                $table->boolean('is_customer_visible')->default(false);
            });
        }

        if (! Schema::hasColumn('contract_item_time_rates', 'is_customer_visible')) {
            Schema::table('contract_item_time_rates', function (Blueprint $table): void {
                $table->boolean('is_customer_visible')->default(false);
            });
        }

        $this->backfillEditableContractItemDescriptions();
        $this->markPlatformDefaultRatesAsCustomerVisible();
        $this->copyVisibleRateDefaultsToEditableContractItems();
        $this->backfillMonthlyPricingCache();
    }

    public function down(): void
    {
        $this->assertCustomerDocumentDataCanBeDropped();

        if (Schema::hasColumn('contract_item_time_rates', 'is_customer_visible')) {
            Schema::table('contract_item_time_rates', function (Blueprint $table): void {
                $table->dropColumn('is_customer_visible');
            });
        }

        if (Schema::hasColumn('time_rates', 'is_customer_visible')) {
            Schema::table('time_rates', function (Blueprint $table): void {
                $table->dropColumn('is_customer_visible');
            });
        }

        foreach (['customer_unit_plural', 'customer_unit_singular'] as $column) {
            if (Schema::hasColumn('services', $column)) {
                Schema::table('services', function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }

        foreach (['customer_unit_plural', 'customer_unit_singular', 'customer_description'] as $column) {
            if (Schema::hasColumn('contract_items', $column)) {
                Schema::table('contract_items', function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }

        if (Schema::hasColumn('contracts', 'customer_document_snapshot')) {
            Schema::table('contracts', function (Blueprint $table): void {
                $table->dropColumn('customer_document_snapshot');
            });
        }
    }

    private function assertCustomerDocumentDataCanBeDropped(): void
    {
        $hasSnapshots = Schema::hasColumn('contracts', 'customer_document_snapshot')
            && DB::table('contracts')->whereNotNull('customer_document_snapshot')->exists();
        $hasLineWording = $this->hasAnyNonNull('contract_items', [
            'customer_description',
            'customer_unit_singular',
            'customer_unit_plural',
        ]);
        $hasServiceUnits = $this->hasAnyNonNull('services', [
            'customer_unit_singular',
            'customer_unit_plural',
        ]);
        $hasVisibleRates = (Schema::hasColumn('time_rates', 'is_customer_visible')
                && DB::table('time_rates')->where('is_customer_visible', true)->exists())
            || (Schema::hasColumn('contract_item_time_rates', 'is_customer_visible')
                && DB::table('contract_item_time_rates')->where('is_customer_visible', true)->exists());

        if (! $hasSnapshots && ! $hasLineWording && ! $hasServiceUnits && ! $hasVisibleRates) {
            return;
        }

        throw new LogicException(
            'Refusing to drop immutable snapshots, customer wording/units, or explicit visible-rate classifications. '
            .'Export, verify, and deliberately clear every affected field before rollback.'
        );
    }

    /** @param array<int, string> $columns */
    private function hasAnyNonNull(string $table, array $columns): bool
    {
        $present = array_values(array_filter(
            $columns,
            fn (string $column): bool => Schema::hasColumn($table, $column),
        ));

        if ($present === []) {
            return false;
        }

        return DB::table($table)
            ->where(function ($query) use ($present): void {
                foreach ($present as $index => $column) {
                    $index === 0
                        ? $query->whereNotNull($column)
                        : $query->orWhereNotNull($column);
                }
            })
            ->exists();
    }

    private function assertPricingBackfillIsSupported(): void
    {
        $pricing = app(ContractPricing::class);

        DB::table('contracts')
            ->select('id')
            ->orderBy('id')
            ->chunkById(200, function ($contracts) use ($pricing): void {
                $contractIds = $contracts->pluck('id')->all();
                $items = DB::table('contract_items')
                    ->whereIn('contract_id', $contractIds)
                    ->get([
                        'contract_id',
                        'unit_price',
                        'quantity',
                        'billing_interval',
                        'discount_value',
                        'discount_type',
                        'setup_fee',
                    ])
                    ->groupBy('contract_id');

                foreach ($contractIds as $contractId) {
                    $pricing->calculateTotals(
                        $items->get($contractId, collect())->map(fn ($item): array => (array) $item)
                    );
                }
            });
    }

    private function backfillEditableContractItemDescriptions(): void
    {
        DB::table('contract_items')
            ->join('contracts', 'contracts.id', '=', 'contract_items.contract_id')
            ->join('services', 'services.id', '=', 'contract_items.service_id')
            ->whereIn('contracts.approval_status', self::EDITABLE_CONTRACT_STATUSES)
            ->whereNull('contract_items.customer_description')
            ->select([
                'contract_items.id as contract_item_id',
                'services.short_description as service_short_description',
                'services.name as service_name',
            ])
            ->chunkById(200, function ($items): void {
                foreach ($items as $item) {
                    $source = trim((string) $item->service_short_description) !== ''
                        ? $item->service_short_description
                        : $item->service_name;
                    $plainText = $this->plainText((string) $source);

                    if ($plainText === '') {
                        continue;
                    }

                    DB::table('contract_items')
                        ->where('id', $item->contract_item_id)
                        ->whereNull('customer_description')
                        ->update(['customer_description' => $plainText]);
                }
            }, 'contract_items.id', 'contract_item_id');
    }

    private function markPlatformDefaultRatesAsCustomerVisible(): void
    {
        DB::table('time_rates')
            ->whereIn('code', self::CUSTOMER_VISIBLE_DEFAULT_RATE_CODES)
            ->update(['is_customer_visible' => true]);
    }

    private function copyVisibleRateDefaultsToEditableContractItems(): void
    {
        $visibleRateIds = DB::table('time_rates')
            ->whereIn('code', self::CUSTOMER_VISIBLE_DEFAULT_RATE_CODES)
            ->where('is_customer_visible', true)
            ->pluck('id')
            ->all();

        if ($visibleRateIds === []) {
            return;
        }

        $editableContractItemIds = DB::table('contract_items')
            ->join('contracts', 'contracts.id', '=', 'contract_items.contract_id')
            ->whereIn('contracts.approval_status', self::EDITABLE_CONTRACT_STATUSES)
            ->select('contract_items.id');

        DB::table('contract_item_time_rates')
            ->whereIn('contract_item_id', $editableContractItemIds)
            ->whereIn('time_rate_id', $visibleRateIds)
            ->update(['is_customer_visible' => true]);
    }

    /**
     * The physical monthly column is a sort cache. Backfill it through the same
     * domain calculator used at runtime without touching any accepted snapshot.
     */
    private function backfillMonthlyPricingCache(): void
    {
        $pricing = app(ContractPricing::class);

        DB::table('contracts')
            ->select('id')
            ->orderBy('id')
            ->chunkById(200, function ($contracts) use ($pricing): void {
                $contractIds = $contracts->pluck('id')->all();
                $items = DB::table('contract_items')
                    ->whereIn('contract_id', $contractIds)
                    ->get([
                        'contract_id',
                        'unit_price',
                        'quantity',
                        'billing_interval',
                        'discount_value',
                        'discount_type',
                        'setup_fee',
                    ])
                    ->groupBy('contract_id');

                foreach ($contractIds as $contractId) {
                    $lines = $items->get($contractId, collect())
                        ->map(fn ($item): array => (array) $item);
                    $totals = $pricing->calculateTotals($lines);

                    DB::table('contracts')
                        ->where('id', $contractId)
                        ->update(['total_monthly_amount' => $totals['monthly']['decimal']]);
                }
            });
    }

    private function plainText(string $value): string
    {
        $plainText = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plainText = preg_replace('/<br\s*\/?>/i', "\n", $plainText) ?? $plainText;
        $plainText = preg_replace('/<\/(p|div|li|h[1-6])>/i', "\n", $plainText) ?? $plainText;
        $plainText = strip_tags($plainText);
        $plainText = preg_replace("/\r\n?/", "\n", $plainText) ?? $plainText;
        $plainText = preg_replace('/[ \t\x{00A0}]+/u', ' ', $plainText) ?? $plainText;
        $plainText = preg_replace('/ *\n */u', "\n", $plainText) ?? $plainText;
        $plainText = preg_replace('/\n{3,}/', "\n\n", $plainText) ?? $plainText;

        return trim($plainText);
    }
};
