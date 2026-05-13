<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->foreignId('ticket_message_id')->constrained('ticket_messages')->cascadeOnDelete();
            $table->foreignId('email_attachment_id')->nullable()->constrained('email_attachments')->nullOnDelete();
            $table->unsignedBigInteger('uploaded_by')->nullable()->index();
            $table->string('source')->default('upload')->index();
            $table->string('filename');
            $table->string('original_filename')->nullable();
            $table->string('content_type')->nullable();
            $table->unsignedInteger('size_bytes')->nullable();
            $table->string('disk')->default('local');
            $table->string('path', 1024);
            $table->char('checksum_sha1', 40)->nullable()->index();
            $table->timestamps();

            $table->unique(['ticket_message_id', 'email_attachment_id']);
            $table->index(['ticket_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_attachments');
    }
};
