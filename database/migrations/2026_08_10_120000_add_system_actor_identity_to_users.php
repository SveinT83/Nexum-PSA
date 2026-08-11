<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_management', function (Blueprint $table): void {
            $table->boolean('is_system_actor')->default(false)->after('status')->index();
            $table->string('system_actor_key', 100)->nullable()->after('is_system_actor');
            $table->unique('system_actor_key', 'user_management_system_actor_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('user_management', function (Blueprint $table): void {
            $table->dropUnique('user_management_system_actor_key_unique');
            $table->dropIndex(['is_system_actor']);
            $table->dropColumn(['is_system_actor', 'system_actor_key']);
        });
    }
};
