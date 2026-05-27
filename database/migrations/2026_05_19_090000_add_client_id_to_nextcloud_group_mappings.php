<?php

use App\Models\Clients\Client;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('nextcloud_group_mappings') && ! Schema::hasColumn('nextcloud_group_mappings', 'client_id')) {
            Schema::table('nextcloud_group_mappings', function (Blueprint $table) {
                $table->foreignIdFor(Client::class)
                    ->nullable()
                    ->after('role_id')
                    ->constrained('clients')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('nextcloud_group_mappings') && Schema::hasColumn('nextcloud_group_mappings', 'client_id')) {
            Schema::table('nextcloud_group_mappings', function (Blueprint $table) {
                $table->dropConstrainedForeignId('client_id');
            });
        }
    }
};
