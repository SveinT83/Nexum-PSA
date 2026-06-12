<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('marketing_interest_assignments')) {
            return;
        }

        Schema::create('marketing_interest_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('marketing_interest_tag_id');
            $table->foreignId('contact_id')->nullable();
            $table->foreignId('client_id')->nullable();
            $table->foreignId('first_event_id')->nullable();
            $table->foreignId('last_event_id')->nullable();
            $table->unsignedInteger('event_count')->default(0);
            $table->unsignedInteger('engagement_score')->default(0);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['marketing_interest_tag_id', 'contact_id'], 'mia_tag_contact_unique');
            $table->unique(['marketing_interest_tag_id', 'client_id'], 'mia_tag_client_unique');
            $table->index(['contact_id', 'engagement_score'], 'mia_contact_score_idx');
            $table->index(['client_id', 'engagement_score'], 'mia_client_score_idx');

            $table->foreign('marketing_interest_tag_id', 'mia_tag_fk')->references('id')->on('marketing_interest_tags')->cascadeOnDelete();
            $table->foreign('contact_id', 'mia_contact_fk')->references('id')->on('contacts')->nullOnDelete();
            $table->foreign('client_id', 'mia_client_fk')->references('id')->on('clients')->nullOnDelete();
            $table->foreign('first_event_id', 'mia_first_evt_fk')->references('id')->on('marketing_campaign_events')->nullOnDelete();
            $table->foreign('last_event_id', 'mia_last_evt_fk')->references('id')->on('marketing_campaign_events')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_interest_assignments');
    }
};
