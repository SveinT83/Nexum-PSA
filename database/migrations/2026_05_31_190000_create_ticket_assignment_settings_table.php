<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $userTable = env('AUTH_USER_TABLE', 'user_management');

        Schema::create('ticket_assignment_settings', function (Blueprint $table) use ($userTable) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->boolean('is_assignable')->default(true);
            $table->unsignedSmallInteger('max_open_tickets')->default(10);
            $table->json('assignment_preferences')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('user_id')
                ->references('id')
                ->on($userTable)
                ->cascadeOnDelete();
            $table->index(['is_assignable', 'max_open_tickets']);
        });

        Schema::create('ticket_assignment_setting_categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ticket_assignment_setting_id');
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['ticket_assignment_setting_id', 'category_id'], 'ticket_assignment_category_unique');
            $table->foreign('ticket_assignment_setting_id', 'ticket_assignment_category_setting_fk')
                ->references('id')
                ->on('ticket_assignment_settings')
                ->cascadeOnDelete();
        });

        Schema::create('ticket_assignment_setting_tags', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ticket_assignment_setting_id');
            $table->foreignId('tag_id')->constrained('tags')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['ticket_assignment_setting_id', 'tag_id'], 'ticket_assignment_tag_unique');
            $table->foreign('ticket_assignment_setting_id', 'ticket_assignment_tag_setting_fk')
                ->references('id')
                ->on('ticket_assignment_settings')
                ->cascadeOnDelete();
        });

        $this->migrateLegacyTicketTechnicianProfiles();

        Schema::dropIfExists('ticket_technician_profile_tags');
        Schema::dropIfExists('ticket_technician_profile_categories');
        Schema::dropIfExists('ticket_technician_profiles');
    }

    public function down(): void
    {
        $userTable = env('AUTH_USER_TABLE', 'user_management');

        Schema::create('ticket_technician_profiles', function (Blueprint $table) use ($userTable) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->boolean('is_assignable')->default(true);
            $table->unsignedSmallInteger('max_open_tickets')->default(10);
            $table->string('timezone')->default(config('app.timezone', 'UTC'));
            $table->json('working_hours')->nullable();
            $table->json('assignment_preferences')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('user_id')
                ->references('id')
                ->on($userTable)
                ->cascadeOnDelete();
            $table->index(['is_assignable', 'max_open_tickets']);
        });

        Schema::create('ticket_technician_profile_categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ticket_technician_profile_id');
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['ticket_technician_profile_id', 'category_id'], 'ticket_profile_category_unique');
            $table->foreign('ticket_technician_profile_id', 'ticket_profile_category_profile_fk')
                ->references('id')
                ->on('ticket_technician_profiles')
                ->cascadeOnDelete();
        });

        Schema::create('ticket_technician_profile_tags', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ticket_technician_profile_id');
            $table->foreignId('tag_id')->constrained('tags')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['ticket_technician_profile_id', 'tag_id'], 'ticket_profile_tag_unique');
            $table->foreign('ticket_technician_profile_id', 'ticket_profile_tag_profile_fk')
                ->references('id')
                ->on('ticket_technician_profiles')
                ->cascadeOnDelete();
        });

        $this->restoreLegacyTicketTechnicianProfiles();

        Schema::dropIfExists('ticket_assignment_setting_tags');
        Schema::dropIfExists('ticket_assignment_setting_categories');
        Schema::dropIfExists('ticket_assignment_settings');
    }

    private function migrateLegacyTicketTechnicianProfiles(): void
    {
        if (! Schema::hasTable('ticket_technician_profiles')) {
            return;
        }

        DB::table('ticket_technician_profiles')
            ->orderBy('id')
            ->chunkById(100, function ($profiles) {
                foreach ($profiles as $profile) {
                    $settingId = DB::table('ticket_assignment_settings')->insertGetId([
                        'user_id' => $profile->user_id,
                        'is_assignable' => $profile->is_assignable,
                        'max_open_tickets' => $profile->max_open_tickets,
                        'assignment_preferences' => $profile->assignment_preferences,
                        'notes' => $profile->notes,
                        'created_at' => $profile->created_at ?? now(),
                        'updated_at' => $profile->updated_at ?? now(),
                        'deleted_at' => $profile->deleted_at,
                    ]);

                    if (Schema::hasTable('ticket_technician_profile_categories')) {
                        DB::table('ticket_technician_profile_categories')
                            ->where('ticket_technician_profile_id', $profile->id)
                            ->orderBy('id')
                            ->get()
                            ->each(function ($row) use ($settingId) {
                                DB::table('ticket_assignment_setting_categories')->insertOrIgnore([
                                    'ticket_assignment_setting_id' => $settingId,
                                    'category_id' => $row->category_id,
                                    'created_at' => $row->created_at ?? now(),
                                    'updated_at' => $row->updated_at ?? now(),
                                ]);
                            });
                    }

                    if (Schema::hasTable('ticket_technician_profile_tags')) {
                        DB::table('ticket_technician_profile_tags')
                            ->where('ticket_technician_profile_id', $profile->id)
                            ->orderBy('id')
                            ->get()
                            ->each(function ($row) use ($settingId) {
                                DB::table('ticket_assignment_setting_tags')->insertOrIgnore([
                                    'ticket_assignment_setting_id' => $settingId,
                                    'tag_id' => $row->tag_id,
                                    'created_at' => $row->created_at ?? now(),
                                    'updated_at' => $row->updated_at ?? now(),
                                ]);
                            });
                    }
                }
            });
    }

    private function restoreLegacyTicketTechnicianProfiles(): void
    {
        if (! Schema::hasTable('ticket_assignment_settings')) {
            return;
        }

        DB::table('ticket_assignment_settings')
            ->orderBy('id')
            ->chunkById(100, function ($settings) {
                foreach ($settings as $setting) {
                    $userProfile = Schema::hasTable('user_profiles')
                        ? DB::table('user_profiles')->where('user_id', $setting->user_id)->first()
                        : null;

                    $profileId = DB::table('ticket_technician_profiles')->insertGetId([
                        'user_id' => $setting->user_id,
                        'is_assignable' => $setting->is_assignable,
                        'max_open_tickets' => $setting->max_open_tickets,
                        'timezone' => $userProfile?->timezone ?? config('app.timezone', 'UTC'),
                        'working_hours' => $userProfile?->working_hours,
                        'assignment_preferences' => $setting->assignment_preferences,
                        'notes' => $setting->notes,
                        'created_at' => $setting->created_at ?? now(),
                        'updated_at' => $setting->updated_at ?? now(),
                        'deleted_at' => $setting->deleted_at,
                    ]);

                    DB::table('ticket_assignment_setting_categories')
                        ->where('ticket_assignment_setting_id', $setting->id)
                        ->orderBy('id')
                        ->get()
                        ->each(function ($row) use ($profileId) {
                            DB::table('ticket_technician_profile_categories')->insertOrIgnore([
                                'ticket_technician_profile_id' => $profileId,
                                'category_id' => $row->category_id,
                                'created_at' => $row->created_at ?? now(),
                                'updated_at' => $row->updated_at ?? now(),
                            ]);
                        });

                    DB::table('ticket_assignment_setting_tags')
                        ->where('ticket_assignment_setting_id', $setting->id)
                        ->orderBy('id')
                        ->get()
                        ->each(function ($row) use ($profileId) {
                            DB::table('ticket_technician_profile_tags')->insertOrIgnore([
                                'ticket_technician_profile_id' => $profileId,
                                'tag_id' => $row->tag_id,
                                'created_at' => $row->created_at ?? now(),
                                'updated_at' => $row->updated_at ?? now(),
                            ]);
                        });
                }
            });
    }
};
