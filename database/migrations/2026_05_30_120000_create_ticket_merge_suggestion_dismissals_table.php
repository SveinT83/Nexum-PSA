<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_merge_suggestion_dismissals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('first_ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->foreignId('second_ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->foreignId('dismissed_by')->nullable()->constrained('user_management')->nullOnDelete();
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->unique(['first_ticket_id', 'second_ticket_id'], 'ticket_merge_suggestion_pair_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_merge_suggestion_dismissals');
    }
};
