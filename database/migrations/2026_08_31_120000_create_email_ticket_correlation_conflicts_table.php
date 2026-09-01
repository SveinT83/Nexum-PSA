<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_ticket_correlation_conflicts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('email_message_id')->unique();
            $table->string('status', 32)->index();
            $table->json('candidate_ticket_ids');
            $table->json('evidence');
            $table->foreignId('resolved_ticket_id')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable()->index();
            $table->text('resolution_reason')->nullable();
            $table->timestamp('detected_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->foreign('email_message_id', 'email_ticket_corr_conflict_message_fk')
                ->references('id')
                ->on('email_messages')
                ->cascadeOnDelete();
            $table->foreign('resolved_ticket_id', 'email_ticket_corr_conflict_ticket_fk')
                ->references('id')
                ->on('tickets')
                ->nullOnDelete();
            $table->foreign('resolved_by', 'email_ticket_corr_conflict_actor_fk')
                ->references('id')
                ->on('user_management')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_ticket_correlation_conflicts');
    }
};
