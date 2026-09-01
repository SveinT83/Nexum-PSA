<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ai_model_rate_cards', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->uuid('ai_provider_id')->nullable();
            $table->string('model_pattern')->comment('Model identifier or regex pattern');
            $table->string('currency', 3)->default('USD');
            $table->dateTime('effective_from');
            $table->dateTime('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('source_reference')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('user_management')->nullOnDelete();
            $table->timestamps();

            $table->foreign('ai_provider_id')->references('id')->on('ai_providers')->nullOnDelete();
            $table->index(['ai_provider_id', 'model_pattern', 'is_active'], 'idx_rate_card_lookup');
        });

        Schema::create('ai_model_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_model_rate_card_id')->constrained('ai_model_rate_cards')->cascadeOnDelete();
            $table->string('metric')->comment('input_token, output_token, cached_input_token, etc.');
            $table->decimal('rate', 18, 12);
            $table->unsignedInteger('unit_quantity')->default(1000000)->comment('e.g. per 1M units');
            $table->timestamps();

            $table->unique(['ai_model_rate_card_id', 'metric']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_model_rates');
        Schema::dropIfExists('ai_model_rate_cards');
    }
};
