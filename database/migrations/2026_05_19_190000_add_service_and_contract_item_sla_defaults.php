<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            if (! Schema::hasColumn('services', 'sla_id')) {
                $table->foreignId('sla_id')
                    ->nullable()
                    ->after('category_id')
                    ->constrained('sla')
                    ->nullOnDelete();
            }
        });

        Schema::table('contract_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('contract_items', 'sla_id')) {
                $table->foreignId('sla_id')
                    ->nullable()
                    ->after('setup_fee')
                    ->constrained('sla')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('contract_items', 'uses_contract_default_sla')) {
                $table->boolean('uses_contract_default_sla')
                    ->default(true)
                    ->after('sla_id');
            }

            if (! Schema::hasColumn('contract_items', 'sla_snapshot')) {
                $table->json('sla_snapshot')
                    ->nullable()
                    ->after('uses_contract_default_sla');
            }
        });
    }

    public function down(): void
    {
        Schema::table('contract_items', function (Blueprint $table): void {
            if (Schema::hasColumn('contract_items', 'sla_snapshot')) {
                $table->dropColumn('sla_snapshot');
            }

            if (Schema::hasColumn('contract_items', 'uses_contract_default_sla')) {
                $table->dropColumn('uses_contract_default_sla');
            }

            if (Schema::hasColumn('contract_items', 'sla_id')) {
                $table->dropConstrainedForeignId('sla_id');
            }
        });

        Schema::table('services', function (Blueprint $table): void {
            if (Schema::hasColumn('services', 'sla_id')) {
                $table->dropConstrainedForeignId('sla_id');
            }
        });
    }
};
