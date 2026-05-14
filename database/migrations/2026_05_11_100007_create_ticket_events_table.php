<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->string('type');
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->text('message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['ticket_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_events');
    }
};
