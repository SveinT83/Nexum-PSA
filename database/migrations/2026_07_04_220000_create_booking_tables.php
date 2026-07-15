<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('booking_service_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->foreignId('assigned_user_id')->nullable()->constrained('user_management')->nullOnDelete();
            $table->string('status')->default('draft')->index();
            $table->string('slug')->unique();
            $table->string('public_name');
            $table->text('public_description')->nullable();
            $table->string('booking_mode')->default('staff_confirmed');
            $table->unsignedSmallInteger('duration_minutes')->default(60);
            $table->unsignedSmallInteger('slot_step_minutes')->default(15);
            $table->unsignedSmallInteger('min_notice_hours')->default(24);
            $table->unsignedSmallInteger('horizon_days')->default(30);
            $table->string('location')->nullable();
            $table->text('instructions')->nullable();
            $table->boolean('allow_new_clients')->default(true);
            $table->string('spam_honeypot_field')->default('booking_website');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique('service_id', 'booking_service_settings_service_unique');
        });

        Schema::create('booking_requests', function (Blueprint $table) {
            $table->id();
            $table->string('booking_key')->unique();
            $table->foreignId('booking_service_setting_id')->nullable()->constrained('booking_service_settings')->nullOnDelete();
            $table->foreignId('service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->foreignId('assigned_user_id')->nullable()->constrained('user_management')->nullOnDelete();
            $table->foreignId('calendar_event_id')->nullable()->constrained('calendar_events')->nullOnDelete();
            $table->string('status')->default('requested')->index();
            $table->string('booking_mode')->default('staff_confirmed');
            $table->string('company_name')->nullable();
            $table->string('contact_name');
            $table->string('contact_email');
            $table->string('contact_phone')->nullable();
            $table->text('message')->nullable();
            $table->date('requested_date')->nullable();
            $table->timestamp('requested_starts_at')->nullable();
            $table->timestamp('requested_ends_at')->nullable();
            $table->string('timezone')->default('Europe/Oslo');
            $table->string('source_url')->nullable();
            $table->string('referrer')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamp('customer_requested_notification_sent_at')->nullable();
            $table->timestamp('customer_confirmation_notification_sent_at')->nullable();
            $table->timestamp('customer_decline_notification_sent_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('user_management')->nullOnDelete();
            $table->timestamp('declined_at')->nullable();
            $table->foreignId('declined_by')->nullable()->constrained('user_management')->nullOnDelete();
            $table->text('decline_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['status', 'requested_starts_at'], 'booking_requests_status_starts_idx');
            $table->index(['booking_service_setting_id', 'status'], 'booking_requests_setting_status_idx');
        });

        Schema::create('booking_request_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_request_id')->constrained('booking_requests')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('user_management')->nullOnDelete();
            $table->string('type')->index();
            $table->text('message')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('booking_request_events');
        Schema::dropIfExists('booking_requests');
        Schema::dropIfExists('booking_service_settings');
    }
};
