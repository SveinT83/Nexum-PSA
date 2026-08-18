<?php

namespace App\Modules\UserManagement\Jobs;

use App\Modules\Email\Models\EmailTemplate;
use App\Modules\Email\Services\DefaultEmailAccountResolver;
use App\Modules\Email\Services\EmailProviderBindingSnapshot;
use App\Modules\Email\Services\EmailTemplateRenderer;
use App\Modules\Email\Services\SmtpAccountMailer;
use App\Modules\UserManagement\Models\InviteToken;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendUserInviteEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public ?int $emailAccountId = null;

    public ?int $providerBindingVersion = null;

    /*
    |--------------------------------------------------------------------------
    | User invitation outbound email job
    |--------------------------------------------------------------------------
    |
    | Admin-created pending users get an invite token immediately, while the
    | email send happens in the queue so failures are visible in queue tooling.
    | Content is rendered from email_templates: system/user_invite.
    |
    */
    public function __construct(public int $inviteTokenId)
    {
        $snapshot = app(EmailProviderBindingSnapshot::class)->captureScope('system');
        $this->emailAccountId = $snapshot['account_id'];
        $this->providerBindingVersion = $snapshot['provider_binding_version'];
    }

    public function handle(
        DefaultEmailAccountResolver $accountResolver,
        EmailTemplateRenderer $renderer,
        SmtpAccountMailer $mailer
    ): void {
        $inviteToken = InviteToken::with('user')->find($this->inviteTokenId);

        if (! $inviteToken || ! $inviteToken->isValid() || ! $inviteToken->user) {
            return;
        }

        $account = $accountResolver->forScope('system');

        if (! $account) {
            throw new \RuntimeException('No active system outbound email account is configured for user invites.');
        }

        try {
            $account = app(EmailProviderBindingSnapshot::class)->resolveScope(
                'system',
                $this->emailAccountId,
                $this->providerBindingVersion,
            );
        } catch (\App\Modules\Integration\Exceptions\EmailProviderSecurityException) {
            Log::warning('User invite Email was superseded by a provider binding change.', [
                'invite_token_id' => $inviteToken->id,
                'reason' => 'provider_binding_stale',
            ]);

            return;
        }

        $template = EmailTemplate::query()
            ->where('scope', 'system')
            ->where('key', 'user_invite')
            ->where('is_active', true)
            ->first();

        if (! $template) {
            throw new \RuntimeException('No active system/user_invite email template exists.');
        }

        $user = $inviteToken->user;
        $acceptUrl = route('invite.accept', ['token' => $inviteToken->token]);
        $expiresHours = config('auth.invite_expire_hours', 72);
        $appName = config('app.name');

        $rendered = $renderer->render($template, [
            'app_name' => $appName,
            'user_name' => $user->name,
            'user_email' => $user->email,
            'invite_url' => $acceptUrl,
            'expires_hours' => $expiresHours,
        ]);

        $mailer->send(
            $account,
            $user->email,
            $user->name,
            $rendered['subject'],
            $rendered['html'],
            $rendered['text'],
            [],
            [],
            ['provider_binding_version' => $this->providerBindingVersion],
        );
    }
}
