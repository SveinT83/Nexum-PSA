<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add service-level public hours and the technician pool used by Booking routing.
     */
    public function up(): void
    {
        Schema::table('booking_service_settings', function (Blueprint $table): void {
            $table->string('technician_routing_mode')
                ->default('fixed')
                ->after('assigned_user_id');
            $table->string('working_hours_source')
                ->default('company')
                ->after('technician_routing_mode');
            $table->time('opening_window_start')
                ->nullable()
                ->after('horizon_days');
            $table->time('opening_window_end')
                ->nullable()
                ->after('opening_window_start');
        });

        Schema::create('booking_service_setting_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('booking_service_setting_id')
                ->constrained('booking_service_settings')
                ->cascadeOnDelete();
            $table->foreignId('user_id')
                ->constrained('user_management')
                ->cascadeOnDelete();
            $table->timestamps();

            $table->unique(
                ['booking_service_setting_id', 'user_id'],
                'booking_setting_user_unique'
            );
        });
    }

    /**
     * Remove the Booking routing extension without touching existing settings or requests.
     */
    public function down(): void
    {
        Schema::dropIfExists('booking_service_setting_user');

        Schema::table('booking_service_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'technician_routing_mode',
                'working_hours_source',
                'opening_window_start',
                'opening_window_end',
            ]);
        });
    }
};
