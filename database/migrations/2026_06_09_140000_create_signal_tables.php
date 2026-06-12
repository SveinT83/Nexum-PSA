<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signals', function (Blueprint $table): void {
            $table->id();
            $table->string('source_domain', 80)->index();
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->string('signal_type', 100)->index();
            $table->string('severity', 50)->default('info')->index();
            $table->unsignedTinyInteger('confidence')->default(100)->index();
            $table->string('status', 50)->default('new')->index();
            $table->string('summary')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();

            $table->index(['source_type', 'source_id']);
            $table->index(['subject_type', 'subject_id']);
            $table->index(['source_domain', 'signal_type', 'status']);
        });

        Schema::create('signal_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('priority')->default(100)->index();
            $table->json('conditions')->nullable();
            $table->json('actions')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('user_management')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('user_management')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('signal_rule_executions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('signal_id')->constrained('signals')->cascadeOnDelete();
            $table->foreignId('signal_rule_id')->nullable()->constrained('signal_rules')->nullOnDelete();
            $table->string('status', 50)->default('executed')->index();
            $table->json('actions')->nullable();
            $table->json('results')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('executed_at')->index();
            $table->timestamps();
        });

        Schema::create('signal_webhook_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('signal_id')->constrained('signals')->cascadeOnDelete();
            $table->foreignId('signal_rule_id')->nullable()->constrained('signal_rules')->nullOnDelete();
            $table->string('url');
            $table->string('status', 50)->default('pending')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('response_body')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signal_webhook_deliveries');
        Schema::dropIfExists('signal_rule_executions');
        Schema::dropIfExists('signal_rules');
        Schema::dropIfExists('signals');
    }
};
