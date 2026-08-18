<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('email_message_classifications')) {
            Schema::create('email_message_classifications', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('account_id');
                $table->foreignId('email_message_id');
                $table->foreignId('category_id')->nullable();
                $table->foreignId('assigned_by')->nullable();
                $table->timestamp('assigned_at')->nullable();
                $table->timestamps();

                $table->foreign('account_id', 'email_msg_class_account_fk')
                    ->references('id')
                    ->on('email_accounts')
                    ->cascadeOnDelete();
                $table->foreign('email_message_id', 'email_msg_class_message_fk')
                    ->references('id')
                    ->on('email_messages')
                    ->cascadeOnDelete();
                $table->foreign('category_id', 'email_msg_class_category_fk')
                    ->references('id')
                    ->on('categories')
                    ->nullOnDelete();
                $table->foreign('assigned_by', 'email_msg_class_assigned_by_fk')
                    ->references('id')
                    ->on('user_management')
                    ->nullOnDelete();
                $table->unique(['account_id', 'email_message_id'], 'email_message_classifications_account_message_unique');
                $table->index(['account_id', 'category_id'], 'email_message_classifications_account_category_index');
            });
        }

        if (Schema::hasTable('email_message_classification_events')) {
            Schema::drop('email_message_classification_events');
        }

        Schema::create('email_message_classification_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('email_message_classification_id')->nullable();
            $table->foreignId('account_id');
            $table->foreignId('email_message_id');
            $table->foreignId('actor_id')->nullable();
            $table->string('event_type', 40)->default('updated')->index();
            $table->json('before_json')->nullable();
            $table->json('after_json')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('email_message_classification_id', 'email_msg_class_events_class_fk')
                ->references('id')
                ->on('email_message_classifications')
                ->nullOnDelete();
            $table->foreign('account_id', 'email_msg_class_events_account_fk')
                ->references('id')
                ->on('email_accounts')
                ->cascadeOnDelete();
            $table->foreign('email_message_id', 'email_msg_class_events_message_fk')
                ->references('id')
                ->on('email_messages')
                ->cascadeOnDelete();
            $table->foreign('actor_id', 'email_msg_class_events_actor_fk')
                ->references('id')
                ->on('user_management')
                ->nullOnDelete();
            $table->index(['account_id', 'email_message_id'], 'email_message_classification_events_message_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_message_classification_events');
        Schema::dropIfExists('email_message_classifications');
    }
};
