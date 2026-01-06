<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('service_term_pivot', function (Blueprint $table) {
            $table->id();

            $table->foreignId('service_id')
                ->constrained('services')
                ->onDelete('cascade');

            $table->foreignId('term_id')
                ->constrained('terms')
                ->onDelete('cascade');

            // Unngå duplikater av samme kombinasjon
            $table->unique(['service_id', 'term_id']);

            // Hvis du vil logge når relasjonen ble opprettet/oppdatert:
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_term');
    }
};
