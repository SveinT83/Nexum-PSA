<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('nextcloud_group_mappings') && ! Schema::hasColumn('nextcloud_group_mappings', 'client_role')) {
            Schema::table('nextcloud_group_mappings', function (Blueprint $table) {
                $table->string('client_role')->nullable()->after('client_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('nextcloud_group_mappings') && Schema::hasColumn('nextcloud_group_mappings', 'client_role')) {
            Schema::table('nextcloud_group_mappings', function (Blueprint $table) {
                $table->dropColumn('client_role');
            });
        }
    }
};
