<?php

use App\Modules\Email\Models\EmailTemplate;
use App\Modules\Email\Services\EmailTemplateRenderer;
use App\Modules\Email\Services\OutboundEmailHtmlPolicy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('email_templates')) {
            Schema::table('email_templates', function (Blueprint $table): void {
                if (! Schema::hasColumn('email_templates', 'layout_mode')) {
                    $table->string('layout_mode', 20)->default(EmailTemplate::LAYOUT_BRANDING)->after('body_text');
                }

                if (! Schema::hasColumn('email_templates', 'layout_html')) {
                    $table->longText('layout_html')->nullable()->after('layout_mode');
                }
            });

            $this->moveLegacyDocumentsIntoCustomLayouts();
        }

        if (Schema::hasTable('marketing_campaign_emails')) {
            Schema::table('marketing_campaign_emails', function (Blueprint $table): void {
                if (! Schema::hasColumn('marketing_campaign_emails', 'layout_html_snapshot')) {
                    $table->longText('layout_html_snapshot')->nullable()->after('body_text_snapshot');
                }
            });

            $this->backfillMarketingLayoutSnapshots();
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('marketing_campaign_emails') && Schema::hasColumn('marketing_campaign_emails', 'layout_html_snapshot')) {
            $this->restoreMarketingDocumentsForLegacyRenderer();
            Schema::table('marketing_campaign_emails', function (Blueprint $table): void {
                $table->dropColumn('layout_html_snapshot');
            });
        }

        if (! Schema::hasTable('email_templates')) {
            return;
        }

        $this->restoreCustomTemplateDocumentsForLegacyRenderer();

        $columns = collect(['layout_html', 'layout_mode'])
            ->filter(fn (string $column): bool => Schema::hasColumn('email_templates', $column))
            ->all();

        if ($columns !== []) {
            Schema::table('email_templates', function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }

    /**
     * Preserve the legacy full-document escape hatch as an explicit custom layout.
     */
    private function moveLegacyDocumentsIntoCustomLayouts(): void
    {
        DB::table('email_templates')
            ->whereNotNull('body_html')
            ->orderBy('id')
            ->chunkById(100, function ($templates): void {
                foreach ($templates as $template) {
                    $body = (string) $template->body_html;

                    if (! preg_match('/<\s*html\b/i', $body)) {
                        continue;
                    }

                    DB::table('email_templates')
                        ->where('id', $template->id)
                        ->update([
                            'body_html' => null,
                            'layout_mode' => EmailTemplate::LAYOUT_CUSTOM,
                            'layout_html' => $this->withBodySlot($body),
                        ]);
                }
            });
    }

    /**
     * Existing Marketing copy was already snapshotted. Freeze its current outer layout too.
     */
    private function backfillMarketingLayoutSnapshots(): void
    {
        $renderer = app(EmailTemplateRenderer::class);

        DB::table('marketing_campaign_emails')
            ->whereNull('layout_html_snapshot')
            ->orderBy('id')
            ->chunkById(100, function ($emails) use ($renderer): void {
                $templates = EmailTemplate::query()
                    ->whereIn('id', $emails->pluck('email_template_id')->filter()->unique()->values())
                    ->get()
                    ->keyBy('id');

                foreach ($emails as $email) {
                    $snapshotBody = (string) ($email->body_html_snapshot ?? '');

                    if (preg_match('/<\s*html\b/i', $snapshotBody)) {
                        $layout = $this->withBodySlot($snapshotBody);
                        $body = null;
                    } else {
                        $template = $templates->get($email->email_template_id)
                            ?: new EmailTemplate(['layout_mode' => EmailTemplate::LAYOUT_BRANDING]);
                        $layout = $renderer->materializeLayout($template);
                        $body = $email->body_html_snapshot;
                    }

                    DB::table('marketing_campaign_emails')
                        ->where('id', $email->id)
                        ->update([
                            'body_html_snapshot' => $body,
                            'layout_html_snapshot' => $layout,
                        ]);
                }
            });
    }

    /**
     * A rollback returns to the old renderer, which expects full documents in body_html.
     */
    private function restoreCustomTemplateDocumentsForLegacyRenderer(): void
    {
        if (! Schema::hasColumn('email_templates', 'layout_mode') || ! Schema::hasColumn('email_templates', 'layout_html')) {
            return;
        }

        DB::table('email_templates')
            ->where('layout_mode', EmailTemplate::LAYOUT_CUSTOM)
            ->whereNotNull('layout_html')
            ->orderBy('id')
            ->chunkById(100, function ($templates): void {
                foreach ($templates as $template) {
                    DB::table('email_templates')
                        ->where('id', $template->id)
                        ->update([
                            'body_html' => $this->injectBody(
                                (string) $template->layout_html,
                                (string) ($template->body_html ?? ''),
                            ),
                        ]);
                }
            });
    }

    private function restoreMarketingDocumentsForLegacyRenderer(): void
    {
        DB::table('marketing_campaign_emails')
            ->whereNotNull('layout_html_snapshot')
            ->orderBy('id')
            ->chunkById(100, function ($emails): void {
                foreach ($emails as $email) {
                    DB::table('marketing_campaign_emails')
                        ->where('id', $email->id)
                        ->update([
                            'body_html_snapshot' => $this->injectBody(
                                (string) $email->layout_html_snapshot,
                                (string) ($email->body_html_snapshot ?? ''),
                            ),
                        ]);
                }
            });
    }

    private function injectBody(string $layout, string $body): string
    {
        return preg_replace_callback(
            OutboundEmailHtmlPolicy::BODY_SLOT_PATTERN,
            static fn (): string => $body,
            $layout,
            1,
        ) ?? $layout;
    }

    private function withBodySlot(string $document): string
    {
        if (preg_match(OutboundEmailHtmlPolicy::BODY_SLOT_PATTERN, $document)) {
            return $document;
        }

        if (preg_match('/<\/body\s*>/i', $document)) {
            return preg_replace(
                '/<\/body\s*>/i',
                OutboundEmailHtmlPolicy::BODY_SLOT."\n</body>",
                $document,
                1,
            ) ?? $document;
        }

        return $document."\n".OutboundEmailHtmlPolicy::BODY_SLOT;
    }
};
