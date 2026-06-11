<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketing_campaign_emails')) {
            return;
        }

        Schema::table('marketing_campaign_emails', function (Blueprint $table): void {
            if (! Schema::hasColumn('marketing_campaign_emails', 'name')) {
                $table->string('name')->nullable()->after('email_template_id');
            }

            if (! Schema::hasColumn('marketing_campaign_emails', 'template_snapshot_name')) {
                $table->string('template_snapshot_name')->nullable()->after('name');
            }

            if (! Schema::hasColumn('marketing_campaign_emails', 'subject_snapshot')) {
                $table->string('subject_snapshot')->nullable()->after('subject_override');
            }

            if (! Schema::hasColumn('marketing_campaign_emails', 'body_html_snapshot')) {
                $table->longText('body_html_snapshot')->nullable()->after('subject_snapshot');
            }

            if (! Schema::hasColumn('marketing_campaign_emails', 'body_text_snapshot')) {
                $table->longText('body_text_snapshot')->nullable()->after('body_html_snapshot');
            }

            if (! Schema::hasColumn('marketing_campaign_emails', 'variables_snapshot')) {
                $table->json('variables_snapshot')->nullable()->after('body_text_snapshot');
            }
        });

        $this->backfillExistingSnapshots();
    }

    public function down(): void
    {
        if (! Schema::hasTable('marketing_campaign_emails')) {
            return;
        }

        $columns = collect([
            'variables_snapshot',
            'body_text_snapshot',
            'body_html_snapshot',
            'subject_snapshot',
            'template_snapshot_name',
            'name',
        ])->filter(fn (string $column): bool => Schema::hasColumn('marketing_campaign_emails', $column))->all();

        if ($columns === []) {
            return;
        }

        Schema::table('marketing_campaign_emails', function (Blueprint $table) use ($columns): void {
            $table->dropColumn($columns);
        });
    }

    private function backfillExistingSnapshots(): void
    {
        if (! Schema::hasTable('email_templates')) {
            return;
        }

        DB::table('marketing_campaign_emails')
            ->whereNull('subject_snapshot')
            ->orderBy('id')
            ->chunkById(100, function ($emails): void {
                $templates = DB::table('email_templates')
                    ->whereIn('id', $emails->pluck('email_template_id')->filter()->unique()->values())
                    ->get()
                    ->keyBy('id');

                foreach ($emails as $email) {
                    $template = $templates->get($email->email_template_id);

                    if (! $template) {
                        continue;
                    }

                    DB::table('marketing_campaign_emails')
                        ->where('id', $email->id)
                        ->update([
                            'name' => $email->name ?: $template->name,
                            'template_snapshot_name' => $email->template_snapshot_name ?: $template->name,
                            'subject_snapshot' => $email->subject_override ?: $template->subject,
                            'body_html_snapshot' => $email->body_html_snapshot ?: $template->body_html,
                            'body_text_snapshot' => $email->body_text_snapshot ?: $template->body_text,
                            'variables_snapshot' => $email->variables_snapshot ?: $template->variables,
                            'updated_at' => now(),
                        ]);
                }
            });
    }
};
