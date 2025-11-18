<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('email_health_checks', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('account_id')->constrained('email_accounts')->cascadeOnUpdate()->cascadeOnDelete();
            $table->timestamp('checked_at')->index();
            $table->string('imap_status', 32)->nullable();
            $table->string('smtp_status', 32)->nullable();
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->json('durations_json')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_health_checks');
    }
};
