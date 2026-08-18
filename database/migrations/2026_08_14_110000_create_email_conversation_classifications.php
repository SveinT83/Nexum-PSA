<?php

use App\Modules\Email\Models\EmailConversationClassification;
use App\Modules\Email\Services\EmailConversationClassificationMigrator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('email_conversation_classifications')) {
            Schema::create('email_conversation_classifications', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('account_id');
                $table->foreignId('email_conversation_id');
                $table->foreignId('category_id')->nullable();
                $table->foreignId('assigned_by')->nullable();
                $table->timestamp('assigned_at')->nullable();
                $table->string('source', 40)->default(EmailConversationClassification::SOURCE_MANUAL)->index();
                $table->json('provenance')->nullable();
                $table->timestamps();

                $table->foreign('account_id', 'email_conv_class_account_fk')
                    ->references('id')
                    ->on('email_accounts')
                    ->cascadeOnDelete();
                $table->foreign('email_conversation_id', 'email_conv_class_conversation_fk')
                    ->references('id')
                    ->on('email_conversations')
                    ->cascadeOnDelete();
                $table->foreign('category_id', 'email_conv_class_category_fk')
                    ->references('id')
                    ->on('categories')
                    ->nullOnDelete();
                $table->foreign('assigned_by', 'email_conv_class_assigned_by_fk')
                    ->references('id')
                    ->on('user_management')
                    ->nullOnDelete();
                $table->unique(
                    ['account_id', 'email_conversation_id'],
                    'email_conv_class_account_conversation_unique',
                );
                $table->index(['account_id', 'category_id'], 'email_conv_class_account_category_index');
            });
        }

        if (! Schema::hasTable('email_conversation_classification_events')) {
            Schema::create('email_conversation_classification_events', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('email_conversation_classification_id')->nullable();
                $table->foreignId('account_id');
                $table->foreignId('email_conversation_id');
                $table->foreignId('actor_id')->nullable();
                $table->string('event_type', 40)->default('updated')->index();
                $table->json('before_json')->nullable();
                $table->json('after_json')->nullable();
                $table->json('metadata_json')->nullable();
                $table->json('provenance_json')->nullable();
                $table->timestamp('created_at')->nullable();

                $table->foreign('email_conversation_classification_id', 'email_conv_class_event_class_fk')
                    ->references('id')
                    ->on('email_conversation_classifications')
                    ->nullOnDelete();
                $table->foreign('account_id', 'email_conv_class_event_account_fk')
                    ->references('id')
                    ->on('email_accounts')
                    ->cascadeOnDelete();
                $table->foreign('email_conversation_id', 'email_conv_class_event_conversation_fk')
                    ->references('id')
                    ->on('email_conversations')
                    ->cascadeOnDelete();
                $table->foreign('actor_id', 'email_conv_class_event_actor_fk')
                    ->references('id')
                    ->on('user_management')
                    ->nullOnDelete();
                $table->index(
                    ['account_id', 'email_conversation_id'],
                    'email_conv_class_event_conversation_index',
                );
            });
        }

        if (! Schema::hasTable('email_conversation_classification_migration_issues')) {
            Schema::create('email_conversation_classification_migration_issues', function (Blueprint $table): void {
                $table->id();
                $table->char('fingerprint', 64)
                    ->unique('email_conv_class_issue_fingerprint_unique');
                $table->string('issue_type', 64)
                    ->index('email_conv_class_issue_type_index');
                $table->string('status', 32)
                    ->default('open')
                    ->index('email_conv_class_issue_status_index');
                $table->foreignId('account_id')->nullable();

                // Legacy identifiers intentionally have no foreign keys so the review evidence survives retirement.
                $table->unsignedBigInteger('email_message_id')
                    ->nullable()
                    ->index('email_conv_class_issue_message_index');
                $table->unsignedBigInteger('email_message_classification_id')
                    ->nullable()
                    ->index('email_conv_class_issue_legacy_class_index');
                $table->foreignId('email_conversation_id')->nullable();
                $table->json('source_classification_ids_json')->nullable();
                $table->json('candidate_conversation_ids_json')->nullable();
                $table->json('source_snapshot_json')->nullable();
                $table->json('target_snapshot_json')->nullable();
                $table->json('details_json')->nullable();
                $table->json('resolution_json')->nullable();
                $table->foreignId('resolved_by')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->dateTime('first_detected_at');
                $table->dateTime('last_detected_at');
                $table->timestamps();

                $table->foreign('account_id', 'email_conv_class_issue_account_fk')
                    ->references('id')
                    ->on('email_accounts')
                    ->nullOnDelete();
                $table->foreign('email_conversation_id', 'email_conv_class_issue_conversation_fk')
                    ->references('id')
                    ->on('email_conversations')
                    ->nullOnDelete();
                $table->foreign('resolved_by', 'email_conv_class_issue_resolved_by_fk')
                    ->references('id')
                    ->on('user_management')
                    ->nullOnDelete();
                $table->index(
                    ['account_id', 'issue_type', 'status'],
                    'email_conv_class_issue_review_index',
                );
            });
        }

        // The service is independently callable so retries and focused tests exercise the same guarded logic.
        app(EmailConversationClassificationMigrator::class)->migrate();
    }

    public function down(): void
    {
        Schema::dropIfExists('email_conversation_classification_migration_issues');
        Schema::dropIfExists('email_conversation_classification_events');

        if (Schema::hasTable('taggables')) {
            $morphTypes = array_unique([
                EmailConversationClassification::class,
                (new EmailConversationClassification)->getMorphClass(),
            ]);

            DB::table('taggables')->whereIn('taggable_type', $morphTypes)->delete();
        }

        Schema::dropIfExists('email_conversation_classifications');
    }
};
