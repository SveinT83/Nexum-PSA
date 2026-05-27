<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the Calendar domain storage used by personal, shared, resource,
     * absence, shift, and future externally synced calendars.
     */
    public function up(): void
    {
        $userTable = (new \App\Models\Core\User())->getTable();

        if (! Schema::hasTable('calendars')) {
            Schema::create('calendars', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('type')->default('personal')->index();
            $table->text('description')->nullable();
            $table->string('color', 20)->default('#2563eb');
            $table->string('timezone')->default('Europe/Oslo');
            $table->nullableMorphs('owner');
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_default')->default(false)->index();
            $table->boolean('is_visible_by_default')->default(true);
            $table->string('visibility_default')->default('default');
            $table->string('transparency_default')->default('busy');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
            });
        }

        if (! Schema::hasTable('calendar_event_series')) {
            Schema::create('calendar_event_series', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('calendar_id')->constrained('calendars')->cascadeOnDelete();
            $table->string('timezone')->default('Europe/Oslo');
            $table->text('rrule')->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->dateTime('recurrence_starts_at')->nullable();
            $table->dateTime('recurrence_ends_at')->nullable();
            $table->unsignedInteger('max_occurrences')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['calendar_id', 'recurrence_starts_at']);
            });
        }

        if (! Schema::hasTable('calendar_events')) {
            Schema::create('calendar_events', function (Blueprint $table) use ($userTable) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('calendar_id')->constrained('calendars')->cascadeOnDelete();
            $table->foreignId('series_id')->nullable()->constrained('calendar_event_series')->nullOnDelete();
            $table->string('title');
            $table->longText('description')->nullable();
            $table->string('location')->nullable();
            $table->string('meeting_url')->nullable();
            $table->dateTime('starts_at')->index();
            $table->dateTime('ends_at')->index();
            $table->string('timezone')->default('Europe/Oslo');
            $table->boolean('all_day')->default(false);
            $table->string('status')->default('confirmed')->index();
            $table->string('transparency')->default('busy')->index();
            $table->string('visibility')->default('default')->index();
            $table->unsignedTinyInteger('priority')->nullable();
            $table->foreignId('created_by')->nullable()->constrained($userTable)->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained($userTable)->nullOnDelete();
            $table->string('source')->default('local')->index();
            $table->string('external_source')->nullable()->index();
            $table->string('external_calendar_id')->nullable()->index();
            $table->string('external_event_id')->nullable()->index();
            $table->string('external_uid')->nullable()->index();
            $table->string('external_etag')->nullable();
            $table->string('sync_status')->nullable()->index();
            $table->timestamp('last_synced_at')->nullable();
            $table->string('sync_hash')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['calendar_id', 'starts_at', 'ends_at']);
            $table->index(['source', 'external_source', 'external_uid']);
            });
        }

        if (! Schema::hasTable('calendar_event_exceptions')) {
            Schema::create('calendar_event_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('series_id')->constrained('calendar_event_series')->cascadeOnDelete();
            $table->dateTime('original_starts_at');
            $table->string('exception_type')->default('cancelled');
            $table->foreignId('replacement_event_id')->nullable()->constrained('calendar_events')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['series_id', 'original_starts_at']);
            });
        }

        if (! Schema::hasTable('calendar_participants')) {
            Schema::create('calendar_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('calendar_events')->cascadeOnDelete();
            $table->string('participant_type')->default('email')->index();
            $table->unsignedBigInteger('participant_id')->nullable()->index();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('role')->default('required');
            $table->string('response_status')->default('needs_action');
            $table->boolean('notify')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();

                $table->index(['event_id', 'participant_type', 'participant_id'], 'cal_participants_lookup_idx');
            });
        }

        if (! Schema::hasTable('calendar_event_links')) {
            Schema::create('calendar_event_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('calendar_events')->cascadeOnDelete();
            $table->morphs('linkable');
            $table->string('relation')->default('scheduled_for')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['event_id', 'linkable_type', 'linkable_id', 'relation'], 'calendar_event_link_unique');
            });
        }

        if (! Schema::hasTable('calendar_access')) {
            Schema::create('calendar_access', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calendar_id')->constrained('calendars')->cascadeOnDelete();
            $table->string('subject_type')->index();
            $table->unsignedBigInteger('subject_id')->index();
            $table->string('access_level')->default('viewer');
            $table->boolean('can_view_private_details')->default(false);
            $table->boolean('can_share')->default(false);
            $table->boolean('can_manage_access')->default(false);
            $table->timestamps();

            $table->unique(['calendar_id', 'subject_type', 'subject_id'], 'calendar_access_subject_unique');
            });
        }

        if (! Schema::hasTable('calendar_availability_rules')) {
            Schema::create('calendar_availability_rules', function (Blueprint $table) use ($userTable) {
            $table->id();
            $table->foreignId('calendar_id')->nullable()->constrained('calendars')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained($userTable)->cascadeOnDelete();
            $table->string('timezone')->default('Europe/Oslo');
            $table->unsignedTinyInteger('weekday');
            $table->time('starts_at_local');
            $table->time('ends_at_local');
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'weekday']);
            $table->index(['calendar_id', 'weekday']);
            });
        }

        if (! Schema::hasTable('calendar_availability_overrides')) {
            Schema::create('calendar_availability_overrides', function (Blueprint $table) use ($userTable) {
            $table->id();
            $table->foreignId('calendar_id')->nullable()->constrained('calendars')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained($userTable)->cascadeOnDelete();
            $table->date('date')->index();
            $table->time('starts_at_local')->nullable();
            $table->time('ends_at_local')->nullable();
            $table->string('availability_type')->default('unavailable');
            $table->string('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'date']);
            $table->index(['calendar_id', 'date']);
            });
        }

        if (! Schema::hasTable('calendar_settings')) {
            Schema::create('calendar_settings', function (Blueprint $table) {
            $table->id();
            $table->string('scope_type')->default('system')->index();
            $table->unsignedBigInteger('scope_id')->nullable()->index();
            $table->string('name')->index();
            $table->text('value')->nullable();
            $table->json('json')->nullable();
            $table->timestamps();

            $table->unique(['scope_type', 'scope_id', 'name'], 'calendar_settings_scope_name_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_settings');
        Schema::dropIfExists('calendar_availability_overrides');
        Schema::dropIfExists('calendar_availability_rules');
        Schema::dropIfExists('calendar_access');
        Schema::dropIfExists('calendar_event_links');
        Schema::dropIfExists('calendar_participants');
        Schema::dropIfExists('calendar_event_exceptions');
        Schema::dropIfExists('calendar_events');
        Schema::dropIfExists('calendar_event_series');
        Schema::dropIfExists('calendars');
    }
};
