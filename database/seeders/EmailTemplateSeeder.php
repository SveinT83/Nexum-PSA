<?php

namespace Database\Seeders;

use App\Modules\Email\Models\EmailTemplate;
use Illuminate\Database\Seeder;

class EmailTemplateSeeder extends Seeder
{
    /*
    |--------------------------------------------------------------------------
    | Default outbound email templates
    |--------------------------------------------------------------------------
    |
    | New installations should be able to send practical ticket and system
    | emails immediately. Admins can later edit these templates from the
    | Templates hub without changing application code.
    |
    | Keep these keys stable. Outbound flows should reference keys such as
    | tickets/ticket_reply instead of hard-coded template IDs.
    |
    */
    public function run(): void
    {
        $templates = [
            [
                'scope' => 'tickets',
                'key' => 'ticket_reply',
                'name' => 'Ticket reply',
                'subject' => '[{{ ticket_key }}] {{ ticket_subject }}',
                'body_html' => '<p>Hello {{ contact_name }},</p><p>{{ message_body }}</p><p>Regards,<br>{{ technician_name }}</p><p style="margin-top:24px;color:#6c757d;font-size:12px;">--- Please reply above this line ---</p>',
                'body_text' => "Hello {{ contact_name }},\n\n{{ message_body }}\n\nRegards,\n{{ technician_name }}\n\n--- Please reply above this line ---",
                'variables' => ['ticket_key', 'ticket_subject', 'contact_name', 'message_body', 'technician_name'],
                'is_default' => true,
                'is_active' => true,
            ],
            [
                'scope' => 'tickets',
                'key' => 'ticket_created',
                'name' => 'Ticket created confirmation',
                'subject' => '[{{ ticket_key }}] Ticket created: {{ ticket_subject }}',
                'body_html' => '<p>Hello {{ contact_name }},</p><p>Your ticket has been created.</p><p><strong>{{ ticket_subject }}</strong></p>',
                'body_text' => "Hello {{ contact_name }},\n\nYour ticket has been created.\n\n{{ ticket_subject }}",
                'variables' => ['ticket_key', 'ticket_subject', 'contact_name'],
                'is_default' => true,
                'is_active' => true,
            ],
            [
                'scope' => 'system',
                'key' => 'system_notification',
                'name' => 'System notification',
                'subject' => '{{ notification_subject }}',
                'body_html' => '<p>{{ notification_body }}</p>',
                'body_text' => '{{ notification_body }}',
                'variables' => ['notification_subject', 'notification_body'],
                'is_default' => true,
                'is_active' => true,
            ],
        ];

        foreach ($templates as $template) {
            EmailTemplate::updateOrCreate(
                ['scope' => $template['scope'], 'key' => $template['key']],
                $template
            );
        }
    }
}
