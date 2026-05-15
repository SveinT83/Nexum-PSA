<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sla', function (Blueprint $table) {
            if (! Schema::hasColumn('sla', 'is_default')) {
                $table->boolean('is_default')->default(false)->after('description')->index();
            }
        });

        if (Schema::hasColumn('sla', 'is_default')) {
            DB::table('sla')
                ->where('name', 'Default')
                ->update(['is_default' => true]);
        }

        Schema::table('contracts', function (Blueprint $table) {
            if (! Schema::hasColumn('contracts', 'sla_id')) {
                $table->foreignId('sla_id')
                    ->nullable()
                    ->after('client_id')
                    ->constrained('sla')
                    ->nullOnDelete();
            }
        });

        Schema::table('tickets', function (Blueprint $table) {
            if (! Schema::hasColumn('tickets', 'sla_id')) {
                $table->foreignId('sla_id')
                    ->nullable()
                    ->after('priority_id')
                    ->constrained('sla')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('tickets', 'sla_source')) {
                $table->string('sla_source')->nullable()->after('sla_id')->index();
            }

            if (! Schema::hasColumn('tickets', 'sla_source_id')) {
                $table->unsignedBigInteger('sla_source_id')->nullable()->after('sla_source')->index();
            }

            if (! Schema::hasColumn('tickets', 'sla_snapshot')) {
                $table->json('sla_snapshot')->nullable()->after('sla_source_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            if (Schema::hasColumn('tickets', 'sla_id')) {
                $table->dropConstrainedForeignId('sla_id');
            }

            foreach (['sla_source', 'sla_source_id', 'sla_snapshot'] as $column) {
                if (Schema::hasColumn('tickets', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('contracts', function (Blueprint $table) {
            if (Schema::hasColumn('contracts', 'sla_id')) {
                $table->dropConstrainedForeignId('sla_id');
            }
        });

        Schema::table('sla', function (Blueprint $table) {
            if (Schema::hasColumn('sla', 'is_default')) {
                $table->dropColumn('is_default');
            }
        });
    }
};
