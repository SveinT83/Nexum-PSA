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
        Schema::create('email_mail_user_conversation_acknowledgements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('email_conversation_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('last_acknowledged_message_id')->nullable();
            $table->timestamp('acknowledged_at');
            $table->timestamps();

            $table->unique(['email_conversation_id', 'user_id'], 'uk_email_conv_ack_user');

            $table->foreign('email_conversation_id', 'fk_email_conv_ack_conv')
                ->references('id')->on('email_conversations')
                ->onDelete('cascade');
            $table->foreign('user_id', 'fk_email_conv_ack_user')
                ->references('id')->on('user_management')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_mail_user_conversation_acknowledgements');
    }
};
