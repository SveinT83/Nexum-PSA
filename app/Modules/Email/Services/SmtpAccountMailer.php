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
    public function send(EmailAccount $account, string $toEmail, ?string $toName, string $subject, string $html, string $text, array $attachments = [], array $ccRecipients = []): string
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

        foreach ($ccRecipients as $recipient) {
            $email->cc(new Address($recipient['email'], $recipient['name'] ?? ''));
        }

        foreach ($attachments as $attachment) {
            if (! empty($attachment['path']) && is_file($attachment['path'])) {
                $email->attachFromPath($attachment['path'], $attachment['filename'] ?? null, $attachment['content_type'] ?? null);
                continue;
            }

            if (array_key_exists('data', $attachment)) {
                $email->attach($attachment['data'], $attachment['filename'] ?? 'attachment', $attachment['content_type'] ?? null);
            }
        }

        $messageId = $this->ensureMessageId($email, $account);
        $mailer = new Mailer($transport);
        $mailer->send($email);

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

    private function ensureMessageId(Email $email, EmailAccount $account): string
    {
        $headers = $email->getHeaders();

        if (! $headers->has('Message-ID')) {
            $headers->addIdHeader('Message-ID', $this->newMessageId($account));
        }

        return $headers->get('Message-ID')->getBodyAsString();
    }

    private function newMessageId(EmailAccount $account): string
    {
        $domain = trim((string) str($account->address)->after('@'));
        $domain = preg_replace('/[^a-z0-9.-]/i', '', $domain) ?: parse_url((string) config('app.url'), PHP_URL_HOST);
        $domain = $domain ?: 'nexum-psa.local';

        return bin2hex(random_bytes(16)).'@'.$domain;
    }
}
