<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('vendor_code')->nullable()->unique();
            $table->string('org_no')->nullable();
            $table->string('url')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->unsignedInteger('default_lead_time_days')->default(0);
            $table->text('note')->nullable();
            $table->text('terms')->nullable();
            $table->boolean('is_vendor')->default(true);
            $table->boolean('is_supplier')->default(false);
            $table->boolean('is_manufacturer')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendors');
    }
};
