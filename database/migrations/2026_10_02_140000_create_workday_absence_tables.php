<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Private source records; no classification is copied into Calendar.
        Schema::create('workday_absences', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained(env('AUTH_USER_TABLE', 'user_management'))->restrictOnDelete();
            $table->string('timezone', 64);
            $table->string('category', 24);
            $table->string('mode', 16);
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('status', 16)->default('active');
            $table->unsignedInteger('version');
            $table->foreignId('calendar_event_id')->nullable()->unique()->constrained('calendar_events')->restrictOnDelete();
            $table->dateTime('expires_at')->index();
            $table->timestamps();
            $table->index(['user_id', 'starts_at', 'ends_at']);
        });
        Schema::create('workday_absence_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('absence_id')->constrained('workday_absences')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->json('snapshot');
            $table->foreignId('author_id')->constrained(env('AUTH_USER_TABLE', 'user_management'))->restrictOnDelete();
            $table->string('origin', 8);
            $table->dateTime('created_at');
            $table->unique(['absence_id', 'version']);
        });
        Schema::create('workday_absence_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->constrained(env('AUTH_USER_TABLE', 'user_management'))->restrictOnDelete();
            $table->char('key_hash', 64);
            $table->char('request_hash', 64);
            $table->string('operation', 100);
            $table->foreignId('absence_id')->constrained('workday_absences')->restrictOnDelete();
            $table->json('response');
            $table->dateTime('expires_at')->index();
            $table->dateTime('created_at');
            $table->unique(['actor_id', 'key_hash']);
        });
        Schema::table('calendar_event_links', function (Blueprint $table) {
            // Nullable keeps every existing Calendar link unchanged.
            $table->foreignId('workday_absence_id')->nullable()->unique('calendar_links_absence_unique')
                ->constrained('workday_absences')->restrictOnDelete();
        });
        Schema::table('workday_previews', function (Blueprint $table) {
            $table->char('absence_fingerprint', 64)->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('workday_absences')->exists()) {
            throw new RuntimeException('Retained absence records require the reviewed retention procedure. Disable the feature instead.');
        }
        Schema::table('workday_previews', fn (Blueprint $table) => $table->dropColumn('absence_fingerprint'));
        Schema::table('calendar_event_links', function (Blueprint $table) {
            $table->dropUnique('calendar_links_absence_unique');
            $table->dropConstrainedForeignId('workday_absence_id');
        });
        Schema::dropIfExists('workday_absence_receipts');
        Schema::dropIfExists('workday_absence_revisions');
        Schema::dropIfExists('workday_absences');
    }
};
