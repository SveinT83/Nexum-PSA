<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_booking_write_mutex', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
        });
        DB::table('calendar_booking_write_mutex')->insert(['id' => 1]);
        Schema::create('booking_api_commands', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('identity_hash', 64)->unique();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('token_hash', 64);
            $table->string('operation', 60);
            $table->string('request_hash', 64);
            $table->unsignedBigInteger('booking_request_id')->nullable();
            $table->longText('result');
            $table->timestamps();
        });
        Schema::create('booking_notification_dispatches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('booking_request_id');
            $table->string('kind', 30);
            $table->string('state', 20)->default('pending');
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
            $table->unique(['booking_request_id', 'kind'], 'booking_dispatch_once');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_notification_dispatches');
        Schema::dropIfExists('booking_api_commands');
        Schema::dropIfExists('calendar_booking_write_mutex');
    }
};
