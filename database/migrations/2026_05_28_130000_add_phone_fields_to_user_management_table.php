<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(env('AUTH_USER_TABLE', 'user_management'), function (Blueprint $table) {
            $table->string('phone_work')->nullable()->after('email');
            $table->string('phone_private')->nullable()->after('phone_work');
        });
    }

    public function down(): void
    {
        Schema::table(env('AUTH_USER_TABLE', 'user_management'), function (Blueprint $table) {
            $table->dropColumn(['phone_work', 'phone_private']);
        });
    }
};
