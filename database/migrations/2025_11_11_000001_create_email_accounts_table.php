<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('email_accounts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('address')->unique();
            $table->string('description')->nullable();
            $table->string('from_name')->nullable();

            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_global_default')->default(false)->index();
            $table->json('defaults_for')->nullable(); // ["tickets","sales","alerts"]

            // IMAP
            $table->string('imap_host');
            $table->unsignedSmallInteger('imap_port');
            $table->string('imap_encryption'); // ssl|tls|starttls
            $table->string('imap_username');
            $table->text('imap_secret'); // encrypted
            $table->string('imap_auth_type')->default('password'); // password|oauth2

            // SMTP
            $table->string('smtp_host');
            $table->unsignedSmallInteger('smtp_port');
            $table->string('smtp_encryption');
            $table->string('smtp_username');
            $table->text('smtp_secret'); // encrypted
            $table->string('smtp_auth_type')->default('password');

            // Health
            $table->timestamp('last_test_at')->nullable();
            $table->string('last_test_result')->nullable(); // OK|Warning|Error
            $table->string('last_error_code')->nullable();
            $table->text('last_error_message')->nullable();
            $table->timestamp('last_successful_fetch_at')->nullable();
            $table->timestamp('last_successful_send_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_accounts');
    }
};
