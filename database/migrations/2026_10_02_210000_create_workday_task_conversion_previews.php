<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Consumed previews retain the converted ranges until the workday expires, even if attribution is later removed.
        Schema::create('workday_task_conversion_previews', function (Blueprint $table) {
            $table->id();
            $table->uuid('token')->unique();
            $table->foreignId('workday_id')->constrained('workdays')->cascadeOnDelete();
            $table->foreignId('revision_id')->constrained('workday_revisions')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->json('payload');
            $table->json('result')->nullable();
            $table->dateTime('expires_at');
            $table->dateTime('retained_until')->index();
            $table->dateTime('consumed_at')->nullable();
            $table->dateTime('created_at');
        });
    }

    public function down(): void
    {
        if (DB::table('workday_task_conversion_previews')->exists()) {
            throw new RuntimeException('Retained Task conversion records exist. Disable Workday and use reviewed retention cleanup.');
        }
        Schema::dropIfExists('workday_task_conversion_previews');
    }
};
