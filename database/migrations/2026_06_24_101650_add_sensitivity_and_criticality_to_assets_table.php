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
        if (! Schema::hasColumn('assets', 'sensitivity_level')) {
            Schema::table('assets', function (Blueprint $table) {
                $table->enum('sensitivity_level', ['low', 'medium', 'high', 'ultra'])
                    ->nullable()
                    ->after('vendor_id');
            });
        }

        if (! Schema::hasColumn('assets', 'criticality_level')) {
            Schema::table('assets', function (Blueprint $table) {
                $table->enum('criticality_level', ['low', 'medium', 'high', 'critical'])
                    ->nullable()
                    ->after('sensitivity_level');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $columns = collect(['sensitivity_level', 'criticality_level'])
            ->filter(fn (string $column): bool => Schema::hasColumn('assets', $column))
            ->values()
            ->all();

        if ($columns !== []) {
            Schema::table('assets', function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }
};
