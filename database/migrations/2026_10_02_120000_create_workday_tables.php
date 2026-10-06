<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Additive storage; no employee history is inferred or imported. */
    public function up(): void
    {
        Schema::create('workdays', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained(env('AUTH_USER_TABLE', 'user_management'))->restrictOnDelete();
            $table->date('work_date');
            $table->string('timezone', 64);
            $table->unsignedInteger('version')->default(0);
            $table->unsignedBigInteger('current_revision_id')->nullable();
            $table->unsignedBigInteger('confirmed_revision_id')->nullable();
            $table->dateTime('expires_at')->index();
            $table->timestamps();
            $table->unique(['user_id', 'work_date']);
        });
        Schema::create('workday_revisions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('workday_id')->constrained('workdays')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('state', 16);
            $table->json('snapshot');
            $table->foreignId('author_id')->constrained(env('AUTH_USER_TABLE', 'user_management'))->restrictOnDelete();
            $table->string('origin', 8);
            $table->string('correction_reason', 1000)->nullable();
            $table->dateTime('created_at');
            $table->unique(['workday_id', 'version']);
        });
        Schema::create('workday_previews', function (Blueprint $table) {
            $table->id();
            $table->uuid('token')->unique();
            $table->foreignId('workday_id')->constrained('workdays')->restrictOnDelete();
            $table->foreignId('revision_id')->constrained('workday_revisions')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->dateTime('expires_at')->index();
            $table->dateTime('consumed_at')->nullable();
        });
        Schema::create('workday_mutation_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->constrained(env('AUTH_USER_TABLE', 'user_management'))->restrictOnDelete();
            $table->char('key_hash', 64);
            $table->string('operation', 100);
            $table->char('request_hash', 64);
            $table->unsignedBigInteger('workday_id')->nullable()->index();
            $table->json('response');
            $table->dateTime('expires_at')->index();
            $table->dateTime('created_at');
            $table->unique(['actor_id', 'key_hash']);
        });
        DB::table('common_settings')->insert([
            'type' => 'workday', 'name' => 'manual_workflow',
            'description' => 'Employee-confirmed actual time, retained for three years from work date.',
            'json' => json_encode(['enabled' => false, 'retention_years' => 3, 'version' => 0]),
        ]);
    }

    public function down(): void
    {
        // A code rollback must never silently erase retained employee records or audit receipts.
        if (DB::table('workdays')->exists() || DB::table('workday_mutation_receipts')->exists()) {
            throw new RuntimeException('Workday contains retained data. Disable the feature and use a reviewed retention procedure.');
        }
        Schema::dropIfExists('workday_mutation_receipts');
        Schema::dropIfExists('workday_previews');
        Schema::dropIfExists('workday_revisions');
        Schema::dropIfExists('workdays');
        DB::table('common_settings')->where('type', 'workday')->where('name', 'manual_workflow')->delete();
    }
};
