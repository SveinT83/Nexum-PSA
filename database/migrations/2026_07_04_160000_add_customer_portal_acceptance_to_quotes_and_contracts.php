<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_quote_versions', function (Blueprint $table): void {
            $table->foreignId('portal_accepted_account_id')->nullable()->after('accepted_ua')->constrained('customer_portal_accounts')->nullOnDelete();
            $table->foreignId('portal_accepted_membership_id')->nullable()->after('portal_accepted_account_id')->constrained('customer_portal_memberships')->nullOnDelete();
            $table->foreignId('portal_accepted_contact_id')->nullable()->after('portal_accepted_membership_id')->constrained('contacts')->nullOnDelete();
        });

        Schema::table('contracts', function (Blueprint $table): void {
            $table->foreignId('portal_accepted_account_id')->nullable()->after('accepted_ua')->constrained('customer_portal_accounts')->nullOnDelete();
            $table->foreignId('portal_accepted_membership_id')->nullable()->after('portal_accepted_account_id')->constrained('customer_portal_memberships')->nullOnDelete();
            $table->foreignId('portal_accepted_contact_id')->nullable()->after('portal_accepted_membership_id')->constrained('contacts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('portal_accepted_contact_id');
            $table->dropConstrainedForeignId('portal_accepted_membership_id');
            $table->dropConstrainedForeignId('portal_accepted_account_id');
        });

        Schema::table('sales_quote_versions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('portal_accepted_contact_id');
            $table->dropConstrainedForeignId('portal_accepted_membership_id');
            $table->dropConstrainedForeignId('portal_accepted_account_id');
        });
    }
};
