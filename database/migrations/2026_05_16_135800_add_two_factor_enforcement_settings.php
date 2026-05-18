<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds a column to track whether two-factor authentication has been
     * confirmed by the user, and whether the system requires it.
     */
    public function up(): void
    {
        // Add 2FA enforcement to the existing common_settings table,
        // or create it if it doesn't exist yet.
        if (! Schema::hasTable('common_settings')) {
            Schema::create('common_settings', function (Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->text('value')->nullable();
                $table->timestamps();
            });
        }

        // Seed default 2FA enforcement setting
        \DB::table('common_settings')->insertOrIgnore([
            'key' => 'enforce_two_factor',
            'value' => '0',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \DB::table('common_settings')->insertOrIgnore([
            'key' => 'enforce_two_factor_roles',
            'value' => json_encode(['superadmin', 'technician']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        \DB::table('common_settings')->where('key', 'enforce_two_factor')->delete();
        \DB::table('common_settings')->where('key', 'enforce_two_factor_roles')->delete();
    }
};