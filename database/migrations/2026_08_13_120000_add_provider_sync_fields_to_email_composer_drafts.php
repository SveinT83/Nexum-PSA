<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('email_composer_drafts')) {
            return;
        }

        Schema::table('email_composer_drafts', function (Blueprint $table): void {
            if (! Schema::hasColumn('email_composer_drafts', 'provider_draft_status')) {
                $table->string('provider_draft_status', 24)->nullable()->after('discarded_at');
            }

            if (! Schema::hasColumn('email_composer_drafts', 'provider_draft_folder_path')) {
                $table->string('provider_draft_folder_path')->nullable()->after('provider_draft_status');
            }

            if (! Schema::hasColumn('email_composer_drafts', 'provider_draft_uid_validity')) {
                $table->unsignedBigInteger('provider_draft_uid_validity')->nullable()->after('provider_draft_folder_path');
            }

            if (! Schema::hasColumn('email_composer_drafts', 'provider_draft_uid')) {
                $table->unsignedBigInteger('provider_draft_uid')->nullable()->after('provider_draft_uid_validity');
            }

            if (! Schema::hasColumn('email_composer_drafts', 'provider_draft_message_id')) {
                $table->string('provider_draft_message_id')->nullable()->after('provider_draft_uid');
            }

            if (! Schema::hasColumn('email_composer_drafts', 'provider_draft_normalized_message_id')) {
                $table->string('provider_draft_normalized_message_id')->nullable()->after('provider_draft_message_id');
            }

            if (! Schema::hasColumn('email_composer_drafts', 'provider_draft_synced_at')) {
                $table->timestamp('provider_draft_synced_at')->nullable()->after('provider_draft_normalized_message_id');
            }

            if (! Schema::hasColumn('email_composer_drafts', 'provider_draft_deleted_at')) {
                $table->timestamp('provider_draft_deleted_at')->nullable()->after('provider_draft_synced_at');
            }

            if (! Schema::hasColumn('email_composer_drafts', 'provider_draft_error_code')) {
                $table->string('provider_draft_error_code', 80)->nullable()->after('provider_draft_deleted_at');
            }

            if (! Schema::hasColumn('email_composer_drafts', 'provider_draft_error_message')) {
                $table->text('provider_draft_error_message')->nullable()->after('provider_draft_error_code');
            }
        });

        Schema::table('email_composer_drafts', function (Blueprint $table): void {
            $table->index(['email_account_id', 'provider_draft_status'], 'email_drafts_provider_status_idx');
            $table->index(['email_account_id', 'provider_draft_folder_path', 'provider_draft_uid'], 'email_drafts_provider_uid_idx');
            $table->index(['email_account_id', 'provider_draft_normalized_message_id'], 'email_drafts_provider_msg_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('email_composer_drafts')) {
            return;
        }

        Schema::table('email_composer_drafts', function (Blueprint $table): void {
            $table->dropIndex('email_drafts_provider_status_idx');
            $table->dropIndex('email_drafts_provider_uid_idx');
            $table->dropIndex('email_drafts_provider_msg_idx');
        });

        Schema::table('email_composer_drafts', function (Blueprint $table): void {
            foreach ([
                'provider_draft_error_message',
                'provider_draft_error_code',
                'provider_draft_deleted_at',
                'provider_draft_synced_at',
                'provider_draft_normalized_message_id',
                'provider_draft_message_id',
                'provider_draft_uid',
                'provider_draft_uid_validity',
                'provider_draft_folder_path',
                'provider_draft_status',
            ] as $column) {
                if (Schema::hasColumn('email_composer_drafts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
