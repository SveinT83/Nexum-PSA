<?php

namespace App\Modules\Email\Services;

use App\Modules\Email\Models\EmailAccount;
use Illuminate\Support\Facades\Crypt;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

class SmtpAccountMailer
{
    /*
    |--------------------------------------------------------------------------
    | SMTP account mailer
    |--------------------------------------------------------------------------
    |
    | Sends one fully-rendered message through the selected EmailAccount. This
    | mirrors the SMTP configuration tested by EmailTestService so ticket email
    | sending uses the same account settings admins already validated.
    |
    */
    public function send(EmailAccount $account, string $toEmail, ?string $toName, string $subject, string $html, string $text): string
    {
        [$sslFlag, $encryption] = $this->mapSmtpEncryption($account->smtp_encryption);

        $transport = new EsmtpTransport($account->smtp_host, (int) $account->smtp_port, $sslFlag);

        if ($encryption === 'tls' && method_exists($transport, 'setTls')) {
            $transport->setTls(true);
        }

        $transport->setUsername($account->smtp_username);
        $transport->setPassword(Crypt::decryptString($account->smtp_secret));

        $email = (new Email())
            ->from(new Address($account->address, $account->from_name ?: $account->address))
            ->to(new Address($toEmail, $toName ?: ''))
            ->subject($subject)
            ->text($text ?: strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html)))
            ->html($html ?: nl2br(e($text)));

        $mailer = new Mailer($transport);
        $mailer->send($email);

        $headers = $email->getHeaders();
        $messageId = $headers->has('Message-ID') ? $headers->get('Message-ID')->getBodyAsString() : '';

        $account->forceFill([
            'last_successful_send_at' => now(),
            'last_error_code' => null,
            'last_error_message' => null,
        ])->save();

        return $messageId;
    }

    private function mapSmtpEncryption(?string $encryption): array
    {
        $encryption = strtolower((string) $encryption);

        return match ($encryption) {
            'ssl' => [true, 'ssl'],
            'tls', 'starttls' => [false, 'tls'],
            default => [false, null],
        };
    }
}
