<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Widen only; historical unknown costs stay unknown and are never recomputed. */
    public function up(): void
    {
        Schema::table('ai_model_usage_events', function (Blueprint $table) {
            $table->decimal('calculated_cost', 24, 12)->nullable()->change();
            $table->decimal('effective_cost', 24, 12)->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('ai_model_usage_events')->where('calculated_cost', '>=', '1000000')
            ->orWhere('effective_cost', '>=', '1000000')
            ->orWhere('calculated_cost', '<=', '-1000000')
            ->orWhere('effective_cost', '<=', '-1000000')->exists()) {
            throw new RuntimeException('Cost precision cannot be reduced without losing historical values.');
        }
        Schema::table('ai_model_usage_events', function (Blueprint $table) {
            $table->decimal('calculated_cost', 18, 12)->nullable()->change();
            $table->decimal('effective_cost', 18, 12)->nullable()->change();
        });
    }
};
