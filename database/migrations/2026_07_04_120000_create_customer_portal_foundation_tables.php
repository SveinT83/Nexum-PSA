<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_portal_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('user_management')->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->string('status', 30)->default('active')->index();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('accepted_terms_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique('user_id');
            $table->unique('contact_id');
        });

        Schema::create('customer_portal_memberships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_portal_account_id')->constrained('customer_portal_accounts')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('client_sites')->nullOnDelete();
            $table->string('role', 50)->default('viewer')->index();
            $table->string('status', 30)->default('active')->index();
            $table->json('capabilities')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('user_management')->nullOnDelete();
            $table->timestamp('disabled_at')->nullable();
            $table->timestamps();

            $table->index(['customer_portal_account_id', 'client_id', 'site_id'], 'customer_portal_memberships_scope_idx');
        });

        Schema::create('customer_portal_invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('client_sites')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('user_management')->nullOnDelete();
            $table->string('email')->index();
            $table->string('role', 50)->default('viewer')->index();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at')->index();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('user_management')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['contact_id', 'client_id', 'site_id'], 'customer_portal_invitations_scope_idx');
        });

        Schema::create('customer_portal_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_portal_account_id')->nullable()->constrained('customer_portal_accounts')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('user_management')->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('client_sites')->nullOnDelete();
            $table->string('event', 100)->index();
            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_portal_audit_events');
        Schema::dropIfExists('customer_portal_invitations');
        Schema::dropIfExists('customer_portal_memberships');
        Schema::dropIfExists('customer_portal_accounts');
    }
};
