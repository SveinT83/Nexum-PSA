<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('client_sites', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('name');
            $table->string('address')->nullable();
            $table->string('co_address')->nullable();
            $table->string('zip', 20)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('county', 100)->nullable();
            $table->string('country', 100)->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['client_id', 'name']);
            $table->index(['client_id', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_sites');
    }
};
