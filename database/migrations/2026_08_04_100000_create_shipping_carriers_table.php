<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_carriers', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->string('legal_name')->nullable();
            $table->string('lifecycle_state', 32)->default('active');
            $table->unsignedInteger('sort_order')->default(100);
            $table->json('service_tags')->nullable();

            $table->string('website_url', 2048);
            $table->string('support_url', 2048)->nullable();
            $table->string('tracking_page_url', 2048)->nullable();
            $table->string('tracking_method', 32)->default('generic_page');
            $table->string('tracking_url_template', 2048)->nullable();
            $table->json('allowed_tracking_hosts')->nullable();
            $table->string('link_visibility', 32)->default('normal');
            $table->string('connector_type', 128)->nullable();

            $table->string('source_url', 2048);
            $table->string('verification_state', 32)->default('unverified');
            $table->date('verified_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('user_management')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('user_management')->nullOnDelete();
            $table->timestamps();

            $table->index(['lifecycle_state', 'sort_order'], 'shipping_carriers_state_sort_index');
            $table->index(['verification_state', 'verified_at'], 'shipping_carriers_verification_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_carriers');
    }
};
