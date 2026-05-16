<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->timestamp('reopened_at')->nullable()->after('closed_at');
            $table->unsignedSmallInteger('reopen_count')->default(0)->after('reopened_at');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn(['reopened_at', 'reopen_count']);
        });
    }
};