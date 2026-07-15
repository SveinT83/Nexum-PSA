<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('marketing_campaigns')) {
            Schema::table('marketing_campaigns', function (Blueprint $table): void {
                if (! Schema::hasColumn('marketing_campaigns', 'completion_behavior')) {
                    $table->string('completion_behavior', 20)->default('stop')->after('new_recipient_policy');
                }

                if (! Schema::hasColumn('marketing_campaigns', 'repeat_interval_value')) {
                    $table->unsignedInteger('repeat_interval_value')->default(1)->after('completion_behavior');
                }

                if (! Schema::hasColumn('marketing_campaigns', 'repeat_interval_unit')) {
                    $table->string('repeat_interval_unit', 20)->default('months')->after('repeat_interval_value');
                }

                if (! Schema::hasColumn('marketing_campaigns', 'current_cycle')) {
                    $table->unsignedInteger('current_cycle')->default(1)->after('repeat_interval_unit');
                }

                if (! Schema::hasColumn('marketing_campaigns', 'next_cycle_at')) {
                    $table->timestamp('next_cycle_at')->nullable()->index()->after('current_cycle');
                }

                if (! Schema::hasColumn('marketing_campaigns', 'last_cycle_completed_at')) {
                    $table->timestamp('last_cycle_completed_at')->nullable()->after('next_cycle_at');
                }

                if (! Schema::hasColumn('marketing_campaigns', 'completed_at')) {
                    $table->timestamp('completed_at')->nullable()->index()->after('last_cycle_completed_at');
                }
            });
        }

        if (Schema::hasTable('marketing_campaign_recipients')) {
            Schema::table('marketing_campaign_recipients', function (Blueprint $table): void {
                if (! Schema::hasColumn('marketing_campaign_recipients', 'cycle_number')) {
                    $table->unsignedInteger('cycle_number')->default(1)->after('marketing_list_member_id');
                }
            });

            Schema::table('marketing_campaign_recipients', function (Blueprint $table): void {
                if (Schema::hasIndex('marketing_campaign_recipients', 'marketing_campaign_recipient_member_unique')) {
                    $table->dropUnique('marketing_campaign_recipient_member_unique');
                }

                if (! Schema::hasIndex('marketing_campaign_recipients', 'marketing_campaign_recipient_member_cycle_unique')) {
                    $table->unique(
                        ['marketing_campaign_email_id', 'marketing_list_member_id', 'cycle_number'],
                        'marketing_campaign_recipient_member_cycle_unique'
                    );
                }
            });
        }

        if (! Schema::hasTable('marketing_content_sources')) {
            Schema::create('marketing_content_sources', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('marketing_campaign_id')->constrained('marketing_campaigns')->cascadeOnDelete();
                $table->string('source_type', 40)->default('wordpress')->index();
                $table->text('source_url');
                $table->string('external_id')->nullable();
                $table->string('title')->nullable();
                $table->text('excerpt')->nullable();
                $table->longText('content_html')->nullable();
                $table->timestamp('published_at')->nullable();
                $table->timestamp('fetched_at')->nullable()->index();
                $table->string('status', 40)->default('active')->index();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['marketing_campaign_id', 'source_type'], 'mcs_campaign_type_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_content_sources');

        if (Schema::hasTable('marketing_campaign_recipients') && Schema::hasColumn('marketing_campaign_recipients', 'cycle_number')) {
            Schema::table('marketing_campaign_recipients', function (Blueprint $table): void {
                if (Schema::hasIndex('marketing_campaign_recipients', 'marketing_campaign_recipient_member_cycle_unique')) {
                    $table->dropUnique('marketing_campaign_recipient_member_cycle_unique');
                }

                if (! Schema::hasIndex('marketing_campaign_recipients', 'marketing_campaign_recipient_member_unique')) {
                    $table->unique(
                        ['marketing_campaign_email_id', 'marketing_list_member_id'],
                        'marketing_campaign_recipient_member_unique'
                    );
                }

                $table->dropColumn('cycle_number');
            });
        }

        if (! Schema::hasTable('marketing_campaigns')) {
            return;
        }

        Schema::table('marketing_campaigns', function (Blueprint $table): void {
            foreach ([
                'completed_at',
                'last_cycle_completed_at',
                'next_cycle_at',
                'current_cycle',
                'repeat_interval_unit',
                'repeat_interval_value',
                'completion_behavior',
            ] as $column) {
                if (Schema::hasColumn('marketing_campaigns', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
