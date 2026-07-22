<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('costs', function (Blueprint $table): void {
            $table->decimal('cost', 14, 4)->change();
            $table->string('source')->default('nexum')->after('id')->index();
            $table->string('external_reference')->nullable()->after('source');
            $table->string('currency', 3)->default('NOK')->after('cost');
            $table->boolean('managed_externally')->default(false)->after('external_reference')->index();

            $table->unique(['source', 'external_reference'], 'costs_source_external_unique');
        });

        Schema::table('cloudfactory_offers', function (Blueprint $table): void {
            $table->foreignId('cost_id')->nullable()->after('service_id')
                ->constrained('costs')->nullOnDelete();
            $table->boolean('is_default_service_offer')->default(false)
                ->after('cost_id')->index();
        });

        Schema::table('contract_items', function (Blueprint $table): void {
            $table->foreignUuid('cloudfactory_offer_id')->nullable()->after('provider_subscription_id')
                ->constrained('cloudfactory_offers')->nullOnDelete();
            $table->decimal('cost_unit_price', 14, 4)->nullable()->after('unit_price');
            $table->string('cost_currency', 3)->nullable()->after('cost_unit_price');
        });
    }

    public function down(): void
    {
        Schema::table('contract_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cloudfactory_offer_id');
            $table->dropColumn(['cost_unit_price', 'cost_currency']);
        });

        Schema::table('cloudfactory_offers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cost_id');
            $table->dropColumn('is_default_service_offer');
        });

        Schema::table('costs', function (Blueprint $table): void {
            $table->dropUnique('costs_source_external_unique');
            $table->dropColumn(['source', 'external_reference', 'currency', 'managed_externally']);
            $table->decimal('cost', 12, 2)->change();
        });
    }
};
