<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('email_messages', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('account_id')->constrained('email_accounts')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('mailbox', 191)->default('INBOX');
            $table->unsignedBigInteger('imap_uid');

            $table->string('message_id', 255)->nullable()->index();
            $table->string('subject', 512)->nullable();
            $table->string('from_name', 255)->nullable();
            $table->string('from_email', 255)->nullable()->index();
            $table->json('to_json')->nullable();
            $table->json('cc_json')->nullable();
            $table->json('headers_json')->nullable();
            $table->string('in_reply_to', 255)->nullable();
            $table->longText('references')->nullable();

            $table->timestamp('received_at')->index();
            $table->unsignedInteger('size_bytes')->nullable();
            $table->boolean('is_oversize')->default(false)->index();
            $table->enum('state', ['new','untriaged','awaiting-link','linked','archived'])->default('untriaged')->index();
            $table->json('labels_json')->nullable();

            $table->longText('body_html_sanitized')->nullable();
            $table->longText('body_text')->nullable();
            $table->string('raw_path', 1024)->nullable();
            $table->unsignedInteger('attachments_count')->default(0);
            $table->char('checksum_sha1', 40)->nullable()->index();

            $table->unsignedBigInteger('ticket_id')->nullable()->index();
            $table->timestamps();

            $table->unique(['account_id','mailbox','imap_uid'], 'uniq_account_mailbox_uid');
            $table->fullText(['subject', 'body_text'], 'ft_subject_body');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_messages');
    }
};
