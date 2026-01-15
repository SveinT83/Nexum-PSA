<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('cost_relations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('costId')->constrained('costs')->onDelete('cascade');
            $table->foreignId('serviceId')->constrained('services')->onDelete('cascade');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_relations');
    }
};
