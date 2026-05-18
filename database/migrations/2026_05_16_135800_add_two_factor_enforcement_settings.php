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
        // or create it with the project-wide common_settings schema if missing.
        if (! Schema::hasTable('common_settings')) {
            Schema::create('common_settings', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('type');
                $table->text('description')->nullable();
                $table->string('value')->nullable();
                $table->text('json')->nullable();
            });
        }

        // Seed defaults using the existing common_settings columns.
        \DB::table('common_settings')->updateOrInsert(
            ['name' => 'enforce_two_factor'],
            [
                'type' => 'security',
                'description' => 'Require two-factor authentication for selected roles.',
                'value' => '0',
                'json' => null,
            ]
        );

        \DB::table('common_settings')->updateOrInsert(
            ['name' => 'enforce_two_factor_roles'],
            [
                'type' => 'security',
                'description' => 'Role names that must use two-factor authentication when enforcement is enabled.',
                'value' => null,
                'json' => json_encode(['superadmin', 'technician']),
            ]
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        \DB::table('common_settings')->where('name', 'enforce_two_factor')->delete();
        \DB::table('common_settings')->where('name', 'enforce_two_factor_roles')->delete();
    }
};
