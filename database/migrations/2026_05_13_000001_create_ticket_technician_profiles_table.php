<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_technician_profiles', function (Blueprint $table) {
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
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_technician_profile_tags');
        Schema::dropIfExists('ticket_technician_profile_categories');
        Schema::dropIfExists('ticket_technician_profiles');
    }
};
