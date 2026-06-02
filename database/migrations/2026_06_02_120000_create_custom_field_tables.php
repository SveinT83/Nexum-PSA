<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_field_definitions', function (Blueprint $table): void {
            $table->id();
            $table->string('model_type')->index();
            $table->string('key');
            $table->string('label');
            $table->string('field_type')->default('text');
            $table->text('help_text')->nullable();
            $table->json('options')->nullable();
            $table->json('default_value')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('visible_in_ui')->default(true);
            $table->boolean('editable_in_ui')->default(true);
            $table->boolean('editable_via_api')->default(true);
            $table->boolean('searchable')->default(false);
            $table->boolean('unique_per_model')->default(false);
            $table->boolean('required')->default(false);
            $table->boolean('admin_only')->default(false);
            $table->string('view_permission')->nullable();
            $table->string('edit_permission')->nullable();
            $table->boolean('active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['model_type', 'key']);
        });

        Schema::create('custom_field_values', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('custom_field_definition_id')->constrained('custom_field_definitions')->cascadeOnDelete();
            $table->string('model_type')->index();
            $table->unsignedBigInteger('model_id')->index();
            $table->text('value_text')->nullable();
            $table->decimal('value_number', 20, 6)->nullable();
            $table->boolean('value_boolean')->nullable();
            $table->date('value_date')->nullable();
            $table->dateTime('value_datetime')->nullable();
            $table->json('value_json')->nullable();
            $table->timestamps();

            $table->unique(['custom_field_definition_id', 'model_type', 'model_id'], 'custom_field_values_record_unique');
            $table->index(['model_type', 'model_id'], 'custom_field_values_record_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_field_values');
        Schema::dropIfExists('custom_field_definitions');
    }
};
