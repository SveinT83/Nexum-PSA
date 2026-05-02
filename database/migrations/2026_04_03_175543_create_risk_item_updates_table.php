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
        Schema::create('risk_item_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('risk_item_id')->constrained()->onDelete('cascade');
            $table->foreignId('created_by')->nullable()->constrained('user_management');
            $table->text('note')->nullable();
            $table->integer('likelihood')->nullable();
            $table->integer('impact')->nullable();
            $table->integer('score')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_item_updates');
    }
};
