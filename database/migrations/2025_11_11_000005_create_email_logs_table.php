<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('email_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->enum('direction', ['inbound','outbound'])->index();
            $table->foreignId('account_id')->nullable()->constrained('email_accounts')->nullOnDelete();
            $table->foreignId('email_message_id')->nullable()->constrained('email_messages')->nullOnDelete();
            $table->string('rfc_message_id', 255)->nullable()->index();
            $table->enum('scope', ['tickets','sales','alerts','inbox'])->nullable()->index();
            $table->enum('level', ['info','warning','error'])->default('info')->index();
            $table->string('code', 128)->nullable();
            $table->text('message')->nullable();
            $table->json('context_json')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_logs');
    }
};
