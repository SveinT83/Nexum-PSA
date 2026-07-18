<?php

namespace App\Modules\Email\Actions;

use App\Modules\Email\Models\EmailTemplate;

class EnsureDefaultEmailTemplates
{
    /*
    |--------------------------------------------------------------------------
    | Default outbound templates
    |--------------------------------------------------------------------------
    |
    | Missing defaults are created when the template admin page opens or the
    | database seeder runs. Existing rows are never overwritten here because
    | admins may have customized subjects, bodies, variables, or active flags.
    |
    */
    public function handle(): void
    {
        foreach ($this->templates() as $template) {
            EmailTemplate::query()->firstOrCreate(
                ['scope' => $template['scope'], 'key' => $template['key']],
                $template
            );
        }
    }

    public function templates(): array
    {
        return [
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
                'scope' => 'tickets',
                'key' => 'ticket_status_update',
                'name' => 'Ticket status update',
                'subject' => '[{{ ticket_key }}] Status update: {{ ticket_subject }}',
                'body_html' => '<p>Hello {{ contact_name }},</p><p>We have updated your Ticket.</p><p><strong>{{ ticket_subject }}</strong><br>Previous status: {{ previous_status }}<br>Current status: <strong>{{ current_status }}</strong></p><p>{{ status_message }}</p><p>Regards,<br>{{ technician_name }}</p><p style="margin-top:24px;color:#6c757d;font-size:12px;">--- Please reply above this line ---</p>',
                'body_text' => "Hello {{ contact_name }},\n\nWe have updated your Ticket.\n\n{{ ticket_subject }}\nPrevious status: {{ previous_status }}\nCurrent status: {{ current_status }}\n\n{{ status_message }}\n\nRegards,\n{{ technician_name }}\n\n--- Please reply above this line ---",
                'variables' => ['ticket_key', 'ticket_subject', 'contact_name', 'previous_status', 'current_status', 'status_message', 'technician_name'],
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
            [
                'scope' => 'system',
                'key' => 'user_invite',
                'name' => 'User invitation',
                'subject' => 'You have been invited to {{ app_name }}',
                'body_html' => '<p>Hello {{ user_name }},</p><p>You have been invited to join <strong>{{ app_name }}</strong>.</p><p>Click the link below to set up your account and choose a password:</p><p><a href="{{ invite_url }}">Accept invitation</a></p><p>This invitation link expires in {{ expires_hours }} hours.</p><p>If you did not expect this invitation, you can safely ignore this email.</p>',
                'body_text' => "Hello {{ user_name }},\n\nYou have been invited to join {{ app_name }}.\n\nOpen this link to set up your account and choose a password:\n{{ invite_url }}\n\nThis invitation link expires in {{ expires_hours }} hours.\n\nIf you did not expect this invitation, you can safely ignore this email.",
                'variables' => ['app_name', 'user_name', 'user_email', 'invite_url', 'expires_hours'],
                'is_default' => true,
                'is_active' => true,
            ],
            [
                'scope' => 'system',
                'key' => 'customer_portal_invite',
                'name' => 'Customer portal invitation',
                'subject' => 'Customer portal access for {{ client_name }}',
                'body_html' => '<p>Hello {{ contact_name }},</p><p>You have been invited to access the customer portal for <strong>{{ client_name }}</strong>.</p><p>Scope: {{ site_name }}</p><p><a href="{{ portal_invite_url }}">Activate portal access</a></p><p>This invitation link expires in {{ expires_hours }} hours.</p><p>If you did not expect this invitation, contact your service provider.</p>',
                'body_text' => "Hello {{ contact_name }},\n\nYou have been invited to access the customer portal for {{ client_name }}.\n\nScope: {{ site_name }}\n\nActivate portal access:\n{{ portal_invite_url }}\n\nThis invitation link expires in {{ expires_hours }} hours.\n\nIf you did not expect this invitation, contact your service provider.",
                'variables' => ['app_name', 'contact_name', 'contact_email', 'client_name', 'site_name', 'portal_invite_url', 'expires_hours'],
                'is_default' => true,
                'is_active' => true,
            ],
            [
                'scope' => 'sales',
                'key' => 'sales_activity_email',
                'name' => 'Sales activity email',
                'subject' => '[{{ opportunity_key }}] {{ message_subject }}',
                'body_html' => '<p>Hello {{ contact_name }},</p><p>{{ message_body }}</p><p>Regards,<br>{{ seller_name }}</p>',
                'body_text' => "Hello {{ contact_name }},\n\n{{ message_body }}\n\nRegards,\n{{ seller_name }}",
                'variables' => ['opportunity_key', 'opportunity_title', 'client_name', 'contact_name', 'message_subject', 'message_body', 'seller_name'],
                'is_default' => true,
                'is_active' => true,
            ],
            [
                'scope' => 'sales',
                'key' => 'sales_internal_note',
                'name' => 'Sales internal note notification',
                'subject' => '[{{ opportunity_key }}] Internal sales note',
                'body_html' => '<p>{{ author_name }} added an internal note on <strong>{{ opportunity_title }}</strong>.</p><div style="white-space:pre-wrap;">{{ note_body }}</div>',
                'body_text' => "{{ author_name }} added an internal note on {{ opportunity_title }}.\n\n{{ note_body }}",
                'variables' => ['opportunity_key', 'opportunity_title', 'client_name', 'author_name', 'note_body'],
                'is_default' => true,
                'is_active' => true,
            ],
            [
                'scope' => 'sales',
                'key' => 'sales_quote_send',
                'name' => 'Sales quote send',
                'subject' => '[{{ opportunity_key }}] Quote {{ quote_key }} for {{ client_name }}',
                'body_html' => '<p>Hello {{ contact_name }},</p><p>Your quote is ready:</p><p><a href="{{ quote_url }}">View quote</a></p><p>Total ex VAT: {{ total_ex_vat }}<br>Total inc VAT: {{ total_inc_vat }}<br>Expires: {{ expires_at }}</p><p>Regards,<br>{{ seller_name }}</p>',
                'body_text' => "Hello {{ contact_name }},\n\nYour quote is ready:\n{{ quote_url }}\n\nTotal ex VAT: {{ total_ex_vat }}\nTotal inc VAT: {{ total_inc_vat }}\nExpires: {{ expires_at }}\n\nRegards,\n{{ seller_name }}",
                'variables' => ['opportunity_key', 'opportunity_title', 'client_name', 'contact_name', 'quote_key', 'quote_url', 'total_ex_vat', 'total_inc_vat', 'expires_at', 'seller_name'],
                'is_default' => true,
                'is_active' => true,
            ],
            [
                'scope' => 'marketing',
                'key' => 'marketing_campaign_default',
                'name' => 'Default marketing campaign email',
                'subject' => 'Marketing update from {{ company_name }}',
                'body_html' => '<p>Hello {{ contact_name }},</p><h2>Marketing update</h2><p>Write the message for this campaign email here.</p><p>Replace this paragraph with the offer, news, or customer value you want to send.</p><p style="margin-top:24px;color:#6c757d;font-size:12px;">You receive this email because your organization has a business relationship with {{ company_name }}. <a href="{{ unsubscribe_url }}">Unsubscribe</a></p>',
                'body_text' => "Hello {{ contact_name }},\n\nMarketing update\n\nWrite the message for this campaign email here.\n\nReplace this paragraph with the offer, news, or customer value you want to send.\n\nYou receive this email because your organization has a business relationship with {{ company_name }}.\nUnsubscribe: {{ unsubscribe_url }}",
                'variables' => [
                    'contact_name',
                    'company_name',
                    'unsubscribe_url',
                ],
                'is_default' => true,
                'is_active' => true,
            ],
        ];
    }
}
