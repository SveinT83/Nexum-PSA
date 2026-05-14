<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('trigger')->default('on_create')->index();
            $table->unsignedInteger('weight')->default(10)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('stop_processing')->default(false);
            $table->json('conditions_json');
            $table->json('actions_json');
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('updated_by')->nullable()->index();
            $table->timestamp('last_hit_at')->nullable();
            $table->unsignedInteger('hit_count')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['trigger', 'is_active', 'weight']);
        });

        Schema::create('ticket_rule_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_rule_id')->nullable()->constrained('ticket_rules')->nullOnDelete();
            $table->foreignId('ticket_id')->nullable()->constrained('tickets')->nullOnDelete();
            $table->string('status')->default('matched')->index();
            $table->json('actions_json')->nullable();
            $table->text('message')->nullable();
            $table->timestamps();

            $table->index(['ticket_rule_id', 'created_at']);
            $table->index(['ticket_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_rule_logs');
        Schema::dropIfExists('ticket_rules');
    }
};
