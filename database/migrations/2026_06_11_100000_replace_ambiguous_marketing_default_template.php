<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private string $oldSubject = '{{ campaign_subject }}';

    private string $oldHtml = '<p>Hello {{ contact_name }},</p><h2>{{ campaign_heading }}</h2><p>{{ campaign_intro }}</p><p><a href="{{ primary_cta_url }}">{{ primary_cta_label }}</a></p><p>{{ campaign_body }}</p><p style="margin-top:24px;color:#6c757d;font-size:12px;">You receive this email because your organization has a business relationship with {{ company_name }}. <a href="{{ unsubscribe_url }}">Unsubscribe</a></p>';

    private string $oldText = "Hello {{ contact_name }},\n\n{{ campaign_heading }}\n\n{{ campaign_intro }}\n\n{{ primary_cta_label }}: {{ primary_cta_url }}\n\n{{ campaign_body }}\n\nYou receive this email because your organization has a business relationship with {{ company_name }}.\nUnsubscribe: {{ unsubscribe_url }}";

    private array $oldVariables = [
        'campaign_subject',
        'campaign_heading',
        'campaign_intro',
        'campaign_body',
        'primary_cta_label',
        'primary_cta_url',
        'contact_name',
        'company_name',
        'unsubscribe_url',
    ];

    private string $newSubject = 'Marketing update from {{ company_name }}';

    private string $newHtml = '<p>Hello {{ contact_name }},</p><h2>Marketing update</h2><p>Write the message for this campaign email here.</p><p>Replace this paragraph with the offer, news, or customer value you want to send.</p><p style="margin-top:24px;color:#6c757d;font-size:12px;">You receive this email because your organization has a business relationship with {{ company_name }}. <a href="{{ unsubscribe_url }}">Unsubscribe</a></p>';

    private string $newText = "Hello {{ contact_name }},\n\nMarketing update\n\nWrite the message for this campaign email here.\n\nReplace this paragraph with the offer, news, or customer value you want to send.\n\nYou receive this email because your organization has a business relationship with {{ company_name }}.\nUnsubscribe: {{ unsubscribe_url }}";

    private array $newVariables = [
        'contact_name',
        'company_name',
        'unsubscribe_url',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('email_templates')) {
            return;
        }

        DB::table('email_templates')
            ->where('scope', 'marketing')
            ->where('key', 'marketing_campaign_default')
            ->where('is_default', true)
            ->where('subject', $this->oldSubject)
            ->where('body_html', $this->oldHtml)
            ->where('body_text', $this->oldText)
            ->update([
                'subject' => $this->newSubject,
                'body_html' => $this->newHtml,
                'body_text' => $this->newText,
                'variables' => json_encode($this->newVariables),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('email_templates')) {
            return;
        }

        DB::table('email_templates')
            ->where('scope', 'marketing')
            ->where('key', 'marketing_campaign_default')
            ->where('is_default', true)
            ->where('subject', $this->newSubject)
            ->where('body_html', $this->newHtml)
            ->where('body_text', $this->newText)
            ->update([
                'subject' => $this->oldSubject,
                'body_html' => $this->oldHtml,
                'body_text' => $this->oldText,
                'variables' => json_encode($this->oldVariables),
                'updated_at' => now(),
            ]);
    }
};
