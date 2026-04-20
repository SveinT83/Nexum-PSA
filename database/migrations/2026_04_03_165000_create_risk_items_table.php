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
        Schema::create('risk_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('risk_assessment_id')->constrained('risk_assessments')->onDelete('cascade');
            $table->foreignId('category_id')->nullable()->constrained('categories')->onDelete('set null');
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('recommended_actions')->nullable();
            $table->text('conclusion')->nullable();
            $table->integer('likelihood')->default(1); // 1-5 scale
            $table->integer('impact')->default(1);     // 1-5 scale
            $table->integer('score')->nullable();      // calculated score: likelihood * impact
            $table->string('status')->default('pending'); // pending, mitigated, resolved, accepted
            $table->date('next_review_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('risk_items');
    }
};
