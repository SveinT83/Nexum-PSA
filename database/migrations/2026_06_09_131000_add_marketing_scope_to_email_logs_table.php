<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE email_logs MODIFY scope ENUM('tickets','sales','marketing','alerts','inbox') NULL");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE email_logs MODIFY scope ENUM('tickets','sales','alerts','inbox') NULL");
        }
    }
};
