<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('email_attachments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('message_id')->constrained('email_messages')->cascadeOnDelete();
            $table->string('filename');
            $table->string('content_type')->nullable();
            $table->unsignedInteger('size_bytes')->nullable();
            $table->string('disk')->default('local');
            $table->string('path', 1024);
            $table->boolean('is_inline')->default(false)->index();
            $table->string('cid', 255)->nullable()->index();
            $table->char('checksum_sha1', 40)->nullable()->index();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_attachments');
    }
};
