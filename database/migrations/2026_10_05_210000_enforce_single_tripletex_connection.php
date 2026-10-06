<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Preserve existing accounts: never choose or delete one during deployment.
        if (DB::table('integrations')->where('type', 'tripletex')->count() > 1) {
            throw new RuntimeException('Multiple Tripletex connections exist. Resolve them before applying the single-account constraint.');
        }

        Schema::table('integrations', function (Blueprint $table) {
            // NULL leaves every other provider unrestricted; the database serializes first setup.
            $table->unsignedTinyInteger('tripletex_singleton')->nullable()
                ->virtualAs("case when type = 'tripletex' then 1 else null end");
            $table->unique('tripletex_singleton', 'integrations_tripletex_singleton_unique');
        });
    }

    public function down(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            $table->dropUnique('integrations_tripletex_singleton_unique');
            $table->dropColumn('tripletex_singleton');
        });
    }
};
