<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_segments', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('enabled')->default(true)->index();
            $table->json('geography_json')->nullable();
            $table->json('industries_json')->nullable();
            $table->json('nace_codes_json')->nullable();
            $table->json('keywords_json')->nullable();
            $table->json('excluded_keywords_json')->nullable();
            $table->json('target_roles_json')->nullable();
            $table->json('marketing_list_ids_json')->nullable();
            $table->json('settings_json')->nullable();
            $table->timestamps();
        });

        Schema::create('lead_research_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lead_segment_id')->nullable()->constrained('lead_segments')->nullOnDelete();
            $table->string('status')->default('draft')->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('summary_json')->nullable();
            $table->unsignedInteger('tokens_used')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('user_management')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('lead_scan_ledger', function (Blueprint $table): void {
            $table->id();
            $table->string('domain')->nullable()->index();
            $table->string('org_no')->nullable()->index();
            $table->text('url')->nullable();
            $table->timestamp('last_scanned_at')->nullable();
            $table->timestamp('next_scan_after')->nullable()->index();
            $table->string('last_result_hash')->nullable();
            $table->unsignedInteger('pages_scanned')->default(0);
            $table->unsignedInteger('tokens_used')->default(0);
            $table->string('status')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('lead_source_evidence', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lead_research_run_id')->nullable()->constrained('lead_research_runs')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('source_type');
            $table->text('source_url')->nullable();
            $table->string('source_title')->nullable();
            $table->text('excerpt')->nullable();
            $table->unsignedTinyInteger('confidence')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('contact_marketing_eligibilities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->string('email')->nullable()->index();
            $table->string('email_type')->default('unknown')->index();
            $table->string('role')->nullable();
            $table->boolean('eligible')->default(false)->index();
            $table->text('reason')->nullable();
            $table->foreignId('source_evidence_id')->nullable()->constrained('lead_source_evidence')->nullOnDelete();
            $table->timestamp('evaluated_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('marketing_suppression_entries', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->nullable()->index();
            $table->string('domain')->nullable()->index();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->string('source')->nullable();
            $table->timestamp('suppressed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_suppression_entries');
        Schema::dropIfExists('contact_marketing_eligibilities');
        Schema::dropIfExists('lead_source_evidence');
        Schema::dropIfExists('lead_scan_ledger');
        Schema::dropIfExists('lead_research_runs');
        Schema::dropIfExists('lead_segments');
    }
};

