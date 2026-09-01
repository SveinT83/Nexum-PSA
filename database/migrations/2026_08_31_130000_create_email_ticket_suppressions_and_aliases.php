<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_conversation_ticket_suppressions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained('email_accounts')->cascadeOnDelete();
            $table->foreignId('email_conversation_id')->nullable();
            $table->string('conversation_key', 160);
            $table->string('status', 32)->default('active')->index();
            $table->string('reason_code', 64)->default('not_ticket');
            $table->unsignedBigInteger('suppressed_by')->nullable()->index();
            $table->foreignId('source_ticket_id')->nullable();
            $table->timestamp('suppressed_at');
            $table->unsignedBigInteger('lifted_by')->nullable()->index();
            $table->timestamp('lifted_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'conversation_key'], 'email_conv_ticket_supp_account_key_unique');
            $table->index(['email_conversation_id', 'status'], 'email_conv_ticket_supp_conversation_status_index');
            $table->foreign('email_conversation_id', 'email_conv_ticket_supp_conversation_fk')
                ->references('id')->on('email_conversations')->nullOnDelete();
            $table->foreign('suppressed_by', 'email_conv_ticket_supp_actor_fk')
                ->references('id')->on('user_management')->nullOnDelete();
            $table->foreign('lifted_by', 'email_conv_ticket_supp_lifted_actor_fk')
                ->references('id')->on('user_management')->nullOnDelete();
            $table->foreign('source_ticket_id', 'email_conv_ticket_supp_source_ticket_fk')
                ->references('id')->on('tickets')->nullOnDelete();
        });

        Schema::create('ticket_key_aliases', function (Blueprint $table): void {
            $table->id();
            $table->string('alias_key', 40)->unique();
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->foreignId('source_ticket_id')->nullable()->constrained('tickets')->nullOnDelete();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->string('reason_code', 64)->default('ticket_merge');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('created_by', 'ticket_key_aliases_actor_fk')
                ->references('id')->on('user_management')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_key_aliases');
        Schema::dropIfExists('email_conversation_ticket_suppressions');
    }
};
