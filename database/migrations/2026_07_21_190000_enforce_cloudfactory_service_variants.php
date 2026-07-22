<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cloudfactory_offers', function (Blueprint $table): void {
            $table->unique('service_id', 'cf_offer_service_unique');
            $table->dropIndex(['is_default_service_offer']);
            $table->dropColumn('is_default_service_offer');
        });
    }

    public function down(): void
    {
        Schema::table('cloudfactory_offers', function (Blueprint $table): void {
            $table->dropUnique('cf_offer_service_unique');
            $table->boolean('is_default_service_offer')
                ->default(false)
                ->after('cost_id')
                ->index();
        });
    }
};
