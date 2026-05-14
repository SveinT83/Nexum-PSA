<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_time_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('type')->default('manual');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('minutes')->default(0);
            $table->string('cost_account')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['ticket_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_time_entries');
    }
};
