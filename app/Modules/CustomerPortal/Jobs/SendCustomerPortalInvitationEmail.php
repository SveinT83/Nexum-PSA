<?php

namespace App\Modules\CustomerPortal\Jobs;

use App\Modules\CustomerPortal\Models\CustomerPortalInvitation;
use App\Modules\Email\Actions\EnsureDefaultEmailTemplates;
use App\Modules\Email\Models\EmailTemplate;
use App\Modules\Email\Services\DefaultEmailAccountResolver;
use App\Modules\Email\Services\EmailTemplateRenderer;
use App\Modules\Email\Services\SmtpAccountMailer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendCustomerPortalInvitationEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public function __construct(public int $invitationId, public string $token)
    {
    }

    public function handle(
        DefaultEmailAccountResolver $accountResolver,
        EmailTemplateRenderer $renderer,
        SmtpAccountMailer $mailer,
        EnsureDefaultEmailTemplates $defaultTemplates,
    ): void {
        $invitation = CustomerPortalInvitation::query()
            ->with(['contact', 'client', 'site'])
            ->find($this->invitationId);

        if (! $invitation || ! $invitation->isValid()) {
            return;
        }

        $account = $accountResolver->forScope('system');

        if (! $account) {
            throw new \RuntimeException('No active system outbound email account is configured for customer portal invitations.');
        }

        $defaultTemplates->handle();

        $template = EmailTemplate::query()
            ->where('scope', 'system')
            ->where('key', 'customer_portal_invite')
            ->where('is_active', true)
            ->first();

        if (! $template) {
            throw new \RuntimeException('No active system/customer_portal_invite email template exists.');
        }

        $rendered = $renderer->render($template, [
            'app_name' => config('app.name'),
            'contact_name' => $invitation->contact?->display_name ?: $invitation->email,
            'contact_email' => $invitation->email,
            'client_name' => $invitation->client?->name,
            'site_name' => $invitation->site?->name ?: 'All sites',
            'portal_invite_url' => route('customer-portal.invitations.accept', ['token' => $this->token]),
            'expires_hours' => config('auth.invite_expire_hours', 72),
        ]);

        $mailer->send(
            $account,
            $invitation->email,
            $invitation->contact?->display_name,
            $rendered['subject'],
            $rendered['html'],
            $rendered['text']
        );
    }
}
