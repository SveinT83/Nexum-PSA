<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Intent survives remote success followed by local failure; never cascade this evidence.
        Schema::create('tripletex_customer_links', function (Blueprint $table) {
            $table->id();
            $table->uuid('connection_id');
            $table->foreign('connection_id')->references('id')->on('integrations')->restrictOnDelete();
            $table->unsignedBigInteger('company_id');
            $table->string('environment', 20);
            $table->string('request_key', 64);
            $table->string('payload_hash', 64)->nullable();
            $table->foreignId('client_id')->nullable()->constrained('clients')->restrictOnDelete();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('customer_number', 20)->nullable();
            $table->string('status', 30)->default('prepared');
            $table->string('error_code', 100)->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
            $table->unique(['connection_id', 'request_key'], 'tripletex_customer_request_unique');
            $table->unique(['connection_id', 'customer_id'], 'tripletex_customer_identity_unique');
            $table->unique('client_id', 'tripletex_customer_client_unique');
        });
    }

    public function down(): void
    {
        if (DB::table('tripletex_customer_links')->exists()) {
            throw new RuntimeException('Preserve Tripletex customer delivery evidence before rollback.');
        }
        Schema::dropIfExists('tripletex_customer_links');
    }
};
