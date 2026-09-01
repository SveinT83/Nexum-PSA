<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ai_model_usage_events', function (Blueprint $table) {
            $table->decimal('calculated_cost', 18, 12)->nullable()->after('provider_reported_cost');
            $table->decimal('effective_cost', 18, 12)->nullable()->after('calculated_cost');
            $table->string('cost_source', 32)->nullable()->after('effective_cost');
            $table->json('pricing_snapshot')->nullable()->after('cost_source');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_model_usage_events', function (Blueprint $table) {
            $table->dropColumn(['calculated_cost', 'effective_cost', 'cost_source', 'pricing_snapshot']);
        });
    }
};
