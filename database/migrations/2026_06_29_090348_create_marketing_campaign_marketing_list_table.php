<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketing_campaign_marketing_list')) {
            Schema::create('marketing_campaign_marketing_list', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('marketing_campaign_id');
                $table->foreignId('marketing_list_id');
                $table->timestamps();

                $table->unique(
                    ['marketing_campaign_id', 'marketing_list_id'],
                    'mcml_campaign_list_unique'
                );

                $table->foreign('marketing_campaign_id', 'mcml_campaign_fk')
                    ->references('id')
                    ->on('marketing_campaigns')
                    ->cascadeOnDelete();

                $table->foreign('marketing_list_id', 'mcml_list_fk')
                    ->references('id')
                    ->on('marketing_lists')
                    ->restrictOnDelete();
            });
        }

        if (
            Schema::hasTable('marketing_campaigns')
            && Schema::hasColumn('marketing_campaigns', 'marketing_list_id')
        ) {
            DB::table('marketing_campaigns')
                ->whereNotNull('marketing_list_id')
                ->orderBy('id')
                ->select(['id', 'marketing_list_id'])
                ->chunkById(500, function ($campaigns): void {
                    foreach ($campaigns as $campaign) {
                        DB::table('marketing_campaign_marketing_list')->updateOrInsert([
                            'marketing_campaign_id' => $campaign->id,
                            'marketing_list_id' => $campaign->marketing_list_id,
                        ], [
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_campaign_marketing_list');
    }
};
