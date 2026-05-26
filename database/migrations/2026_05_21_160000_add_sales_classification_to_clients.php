<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            if (! Schema::hasColumn('clients', 'website')) {
                $table->string('website')->nullable()->after('org_no');
            }

            if (! Schema::hasColumn('clients', 'sales_category_id')) {
                $table->foreignId('sales_category_id')->nullable()->after('website')->constrained('categories')->nullOnDelete();
            }

            if (! Schema::hasColumn('clients', 'lead_temperature')) {
                $table->unsignedTinyInteger('lead_temperature')->default(3)->after('sales_category_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            if (Schema::hasColumn('clients', 'sales_category_id')) {
                $table->dropConstrainedForeignId('sales_category_id');
            }

            if (Schema::hasColumn('clients', 'lead_temperature')) {
                $table->dropColumn('lead_temperature');
            }

            if (Schema::hasColumn('clients', 'website')) {
                $table->dropColumn('website');
            }
        });
    }
};
