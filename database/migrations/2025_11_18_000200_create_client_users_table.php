<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('client_users', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_site_id')->constrained('client_sites')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('role', 100)->nullable();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('address')->nullable();
            $table->string('co_address')->nullable();
            $table->string('zip', 20)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('county', 100)->nullable();
            $table->string('country', 100)->nullable();
            $table->boolean('is_default_for_site')->default(false);
            $table->boolean('is_default_for_client')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['client_site_id', 'active']);
            $table->unique(['client_site_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_users');
    }
};
