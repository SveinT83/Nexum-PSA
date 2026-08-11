<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('storage_purchase_order_imports', function (Blueprint $table): void {
            $table->foreignId('ai_profile_candidate_version_id')
                ->nullable()
                ->after('profile_version_id')
                ->constrained('storage_purchase_order_import_profile_versions', 'id', 'spo_import_ai_candidate_version_fk')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('storage_purchase_order_imports', function (Blueprint $table): void {
            $table->dropForeign('spo_import_ai_candidate_version_fk');
            $table->dropColumn('ai_profile_candidate_version_id');
        });
    }
};
