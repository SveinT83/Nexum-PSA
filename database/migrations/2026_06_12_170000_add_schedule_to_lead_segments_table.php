<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lead_segments', function (Blueprint $table): void {
            $table->boolean('schedule_enabled')->default(false)->after('enabled')->index();
            $table->string('schedule_period')->default('weekly')->after('schedule_enabled');
            $table->json('schedule_weekdays_json')->nullable()->after('schedule_period');
            $table->time('schedule_time')->nullable()->after('schedule_weekdays_json');
            $table->unsignedInteger('run_interval_days')->default(1)->after('schedule_time');
            $table->unsignedInteger('target_new_leads_per_period')->nullable()->after('run_interval_days');
            $table->unsignedInteger('token_budget_per_period')->nullable()->after('target_new_leads_per_period');
            $table->boolean('token_budget_unlimited')->default(false)->after('token_budget_per_period');
            $table->unsignedInteger('max_runs_per_period')->nullable()->after('token_budget_unlimited');
            $table->timestamp('next_run_at')->nullable()->after('max_runs_per_period')->index();
            $table->timestamp('last_run_at')->nullable()->after('next_run_at');
        });
    }

    public function down(): void
    {
        Schema::table('lead_segments', function (Blueprint $table): void {
            $table->dropColumn([
                'schedule_enabled',
                'schedule_period',
                'schedule_weekdays_json',
                'schedule_time',
                'run_interval_days',
                'target_new_leads_per_period',
                'token_budget_per_period',
                'token_budget_unlimited',
                'max_runs_per_period',
                'next_run_at',
                'last_run_at',
            ]);
        });
    }
};

