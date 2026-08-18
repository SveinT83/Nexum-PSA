<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE common_settings MODIFY `value` TEXT NULL');

            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE common_settings ALTER COLUMN value TYPE TEXT');

            return;
        }

        if ($driver === 'sqlite') {
            return;
        }

        Schema::table('common_settings', function (Blueprint $table): void {
            $table->text('value')->nullable()->change();
        });
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE common_settings MODIFY `value` VARCHAR(255) NULL');

            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE common_settings ALTER COLUMN value TYPE VARCHAR(255)');

            return;
        }

        if ($driver === 'sqlite') {
            return;
        }

        Schema::table('common_settings', function (Blueprint $table): void {
            $table->string('value')->nullable()->change();
        });
    }
};
