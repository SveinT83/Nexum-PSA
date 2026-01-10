<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('common_settings', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type');
            $table->text('description')->nullable();
            $table->string('value')->nullable();
            $table->text('json')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('common_settings');
    }
};
