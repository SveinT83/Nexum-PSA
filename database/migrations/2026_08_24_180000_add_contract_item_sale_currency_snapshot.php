<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Contract sale prices historically had no currency snapshot. Prove
        // that every linked source is NOK before assigning the explicit
        // NOK-only customer-document boundary; cost_currency is unrelated.
        $this->assertLinkedSaleSourcesAreNok();

        if (! Schema::hasColumn('contract_items', 'price_currency')) {
            Schema::table('contract_items', function (Blueprint $table): void {
                $table->char('price_currency', 3)->default('NOK')->after('unit_price');
            });
        }

        $this->assertStoredCurrenciesAreNok();
    }

    public function down(): void
    {
        if (! Schema::hasColumn('contract_items', 'price_currency')) {
            return;
        }

        $this->assertStoredCurrenciesAreNok();

        Schema::table('contract_items', function (Blueprint $table): void {
            $table->dropColumn('price_currency');
        });
    }

    private function assertLinkedSaleSourcesAreNok(): void
    {
        $currencyExpression = "UPPER(TRIM(COALESCE(NULLIF(TRIM(cloudfactory_offers.currency), ''), NULLIF(TRIM(services.price_currency), ''), 'NOK')))";
        $unsupported = DB::table('contract_items')
            ->leftJoin('services', 'services.id', '=', 'contract_items.service_id')
            ->leftJoin('cloudfactory_offers', 'cloudfactory_offers.id', '=', 'contract_items.cloudfactory_offer_id')
            ->whereRaw($currencyExpression." <> 'NOK'");

        if (! (clone $unsupported)->exists()) {
            return;
        }

        // Keep the diagnostic bounded even when production contains a large
        // Contract history. The preflight itself remains SQL-side and runs
        // before any DDL.
        $contractIds = (clone $unsupported)
            ->select('contract_items.contract_id')
            ->distinct()
            ->orderBy('contract_items.contract_id')
            ->limit(25)
            ->pluck('contract_items.contract_id')
            ->implode(', ');

        throw new LogicException(
            'Contract sale-currency preflight failed. Review linked non-NOK source prices before migration. '
            .'Affected contract IDs: '.$contractIds.'.'
        );
    }

    private function assertStoredCurrenciesAreNok(): void
    {
        if (DB::table('contract_items')
            ->whereNull('price_currency')
            ->orWhereRaw("UPPER(TRIM(price_currency)) <> 'NOK'")
            ->exists()) {
            throw new LogicException(
                'Contract items contain unsupported sale currencies. Customer contracts currently support NOK only.'
            );
        }
    }
};
