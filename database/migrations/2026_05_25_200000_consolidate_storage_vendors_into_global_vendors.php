<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            if (! Schema::hasColumn('vendors', 'vendor_code')) {
                $table->string('vendor_code')->nullable()->unique()->after('name');
            }
            if (! Schema::hasColumn('vendors', 'org_no')) {
                $table->string('org_no')->nullable()->after('vendor_code');
            }
            if (! Schema::hasColumn('vendors', 'is_vendor')) {
                $table->boolean('is_vendor')->default(true)->after('org_no');
            }
            if (! Schema::hasColumn('vendors', 'is_supplier')) {
                $table->boolean('is_supplier')->default(false)->after('is_vendor');
            }
            if (! Schema::hasColumn('vendors', 'is_manufacturer')) {
                $table->boolean('is_manufacturer')->default(false)->after('is_supplier');
            }
            if (! Schema::hasColumn('vendors', 'default_lead_time_days')) {
                $table->unsignedInteger('default_lead_time_days')->default(0)->after('email');
            }
            if (! Schema::hasColumn('vendors', 'terms')) {
                $table->text('terms')->nullable()->after('note');
            }
            if (! Schema::hasColumn('vendors', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('terms');
            }
        });

        Schema::dropIfExists('storage_vendors');
    }

    public function down(): void
    {
        // The Storage module intentionally uses the global vendors table.
    }
};
