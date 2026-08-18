<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('email_composer_draft_attachments')) {
            return;
        }

        Schema::create('email_composer_draft_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('email_composer_draft_id')
                ->constrained('email_composer_drafts', indexName: 'email_draft_attach_draft_fk')
                ->cascadeOnDelete();
            $table->foreignId('user_id')
                ->constrained('user_management', indexName: 'email_draft_attach_user_fk')
                ->cascadeOnDelete();
            $table->unsignedInteger('position')->default(1);
            $table->string('filename');
            $table->string('content_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('disk', 48)->default('local');
            $table->string('path');
            $table->string('checksum_sha1', 40)->nullable();
            $table->timestamps();

            $table->index(['email_composer_draft_id', 'position'], 'email_draft_attach_draft_pos_idx');
            $table->index(['user_id', 'created_at'], 'email_draft_attach_user_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_composer_draft_attachments');
    }
};
