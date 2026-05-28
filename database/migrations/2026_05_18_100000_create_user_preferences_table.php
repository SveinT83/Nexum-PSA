<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_preferences')) {
            return;
        }

        Schema::create('user_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('user_management')->cascadeOnDelete();
            $table->string('timezone')->default('Europe/Oslo');
            $table->string('default_calendar_view')->default('week');
            $table->time('workday_start')->default('08:00:00');
            $table->time('workday_end')->default('16:00:00');
            $table->json('settings')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_preferences');
    }
};
