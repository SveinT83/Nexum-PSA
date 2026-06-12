<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketing_campaigns')) {
            return;
        }

        Schema::table('marketing_campaigns', function (Blueprint $table): void {
            if (! Schema::hasColumn('marketing_campaigns', 'sequence_interval_value')) {
                $table->unsignedInteger('sequence_interval_value')->default(1)->after('send_interval_minutes');
            }

            if (! Schema::hasColumn('marketing_campaigns', 'sequence_interval_unit')) {
                $table->string('sequence_interval_unit', 20)->default('days')->after('sequence_interval_value');
            }

            if (! Schema::hasColumn('marketing_campaigns', 'new_recipient_policy')) {
                $table->string('new_recipient_policy', 40)->default('start_at_first_email')->after('sequence_interval_unit');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('marketing_campaigns')) {
            return;
        }

        Schema::table('marketing_campaigns', function (Blueprint $table): void {
            foreach (['new_recipient_policy', 'sequence_interval_unit', 'sequence_interval_value'] as $column) {
                if (Schema::hasColumn('marketing_campaigns', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
