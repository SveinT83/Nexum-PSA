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
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->onDelete('cascade');
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('type')->nullable(); // To allow for different purposes
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('clients', function (Blueprint $table): void {
            $table->foreign('sales_category_id')->references('id')->on('categories')->nullOnDelete();
        });

        Schema::table('services', function (Blueprint $table): void {
            $table->foreign('category_id')->references('id')->on('categories')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            $table->dropForeign(['category_id']);
        });

        Schema::table('clients', function (Blueprint $table): void {
            $table->dropForeign(['sales_category_id']);
        });

        Schema::dropIfExists('categories');
    }
};
