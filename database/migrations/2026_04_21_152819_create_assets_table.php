<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->onDelete('cascade');
            $table->foreignId('site_id')->nullable()->constrained('client_sites')->onDelete('set null');
            $table->string('name');
            $table->enum('type', ['server', 'pc', 'laptop', 'switch', 'ap', 'firewall', 'other'])->default('other');
            $table->string('vendor')->nullable();
            $table->string('model')->nullable();
            $table->string('serial_number')->nullable()->index();
            $table->string('mac_address')->nullable()->index();
            $table->string('ip_address')->nullable();
            $table->string('hostname')->nullable()->index();
            $table->string('source')->default('manual'); // manual, nable, tactical, unifi, omada
            $table->string('rmm_id')->nullable()->index();
            $table->boolean('is_managed')->default(false);
            $table->string('status')->default('unknown'); // online, offline, unknown, in_service
            $table->foreignId('user_id')->nullable()->after('site_id')->constrained('client_users')->onDelete('set null');
            $table->enum('ip_type', ['dhcp', 'fixed'])->default('dhcp')->after('ip_address');
            $table->foreignId('vendor_id')->nullable()->after('vendor')->constrained('vendors')->onDelete('set null');
            $table->timestamp('last_seen_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
