<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tripletex_customer_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('link_id')->unique()->constrained('tripletex_customer_links')->restrictOnDelete();
            // Retain the original Site ID after deletion; never silently bind another Site.
            $table->unsignedBigInteger('site_id')->nullable()->index();
            $table->string('address_kind', 32)->default('physicalAddress');
            $table->text('baseline')->nullable();
            $table->text('pending')->nullable();
            $table->json('conflict_fields')->nullable();
            $table->string('status', 32)->default('pending');
            $table->string('error_code', 100)->nullable();
            $table->timestamp('checked_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (DB::table('tripletex_customer_profiles')->exists()) {
            throw new RuntimeException('Preserve customer profile synchronization evidence before rollback.');
        }
        Schema::dropIfExists('tripletex_customer_profiles');
    }
};
