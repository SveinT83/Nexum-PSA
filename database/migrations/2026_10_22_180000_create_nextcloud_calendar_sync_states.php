<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One durable lease per connection protects manual and queued calendar work alike.
        Schema::create('nextcloud_calendar_sync_states', function (Blueprint $table) {
            $table->foreignId('connection_id')->primary()->constrained('nextcloud_connections')->cascadeOnDelete();
            $table->uuid('token')->nullable();
            $table->foreignId('log_id')->nullable()->constrained('nextcloud_sync_logs')->nullOnDelete();
            $table->timestamp('lease_until')->nullable();
            $table->timestamp('next_due_at')->nullable();
            $table->timestamp('last_completed_at')->nullable();
            $table->timestamps();
        });
        // Different connections can point to the same local Calendar.
        Schema::create('nextcloud_calendar_sync_locks', function (Blueprint $table) {
            $table->foreignId('calendar_id')->primary()->constrained('calendars')->cascadeOnDelete();
            $table->uuid('token')->nullable();
            $table->timestamp('lease_until')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nextcloud_calendar_sync_locks');
        Schema::dropIfExists('nextcloud_calendar_sync_states');
    }
};
