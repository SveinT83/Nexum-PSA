<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_assignment_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('weight')->default(10);
            $table->boolean('is_active')->default(true);
            $table->boolean('stop_processing')->default(true);
            $table->json('conditions_json')->nullable();
            $table->string('action_type')->default('assign_user');
            $table->string('action_value')->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('updated_by')->nullable()->index();
            $table->timestamp('last_hit_at')->nullable();
            $table->unsignedInteger('hit_count')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'weight']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_assignment_rules');
    }
};
