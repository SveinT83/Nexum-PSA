<?php

namespace App\Modules\Email\Services;

use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Support\EmailAccountProviderLock;
use App\Modules\Integration\Exceptions\EmailProviderSecurityException;
use App\Modules\Integration\Services\EmailProviderTransportFactory;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

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
    public function send(
        #[\SensitiveParameter] EmailAccount $account,
        #[\SensitiveParameter] string $toEmail,
        #[\SensitiveParameter] ?string $toName,
        #[\SensitiveParameter] string $subject,
        #[\SensitiveParameter] string $html,
        #[\SensitiveParameter] string $text,
        #[\SensitiveParameter] array $attachments = [],
        #[\SensitiveParameter] array $ccRecipients = [],
        #[\SensitiveParameter] array $options = [],
    ): string
    {
        return $this->sendMessage(
            $account,
            [['email' => $toEmail, 'name' => $toName ?: '']],
            $subject,
            $html,
            $text,
            $attachments,
            $ccRecipients,
            $options,
        );
    }

    /**
     * Send one rendered message to one or more recipients.
     *
     * @param  array<int, array{email: string, name?: string|null}>  $toRecipients
     * @param  array<int, array{path?: string, data?: string, filename?: string|null, content_type?: string|null}>  $attachments
     * @param  array<int, array{email: string, name?: string|null}>  $ccRecipients
     * @param  array{message_id?: string|null, in_reply_to?: string|null, references?: string|null, provider_binding_version?: int}  $options
     */
    public function sendMessage(
        #[\SensitiveParameter] EmailAccount $account,
        #[\SensitiveParameter] array $toRecipients,
        #[\SensitiveParameter] string $subject,
        #[\SensitiveParameter] string $html,
        #[\SensitiveParameter] string $text,
        #[\SensitiveParameter] array $attachments = [],
        #[\SensitiveParameter] array $ccRecipients = [],
        #[\SensitiveParameter] array $options = [],
    ): string
    {
        $providerLock = EmailAccountProviderLock::acquire((int) $account->id, 360);

        if (! $providerLock) {
            throw new EmailProviderSecurityException('provider_work_locked');
        }

        $transport = null;

        try {
            $expectedBindingVersion = isset($options['provider_binding_version'])
                ? (int) $options['provider_binding_version']
                : app(EmailAccountProviderRuntimeResolver::class)->captureBindingVersion($account);
            if ($expectedBindingVersion < 1) {
                throw new EmailProviderSecurityException('provider_binding_snapshot_missing');
            }
            $runtime = app(EmailAccountProviderRuntimeResolver::class)->resolve(
                $account,
                $expectedBindingVersion,
            );
            $transport = app(EmailProviderTransportFactory::class)->makeSmtp($runtime);

            $toAddresses = $this->addresses($toRecipients);

            if ($toAddresses === []) {
                throw new InvalidArgumentException('At least one valid recipient is required.');
            }

            $email = (new Email)
                ->from(new Address($account->address, $account->from_name ?: $account->address))
                ->to(...$toAddresses)
                ->subject($subject)
                ->text($text ?: strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html)))
                ->html($html ?: nl2br(e($text)));

            $ccAddresses = $this->addresses($ccRecipients);

            if ($ccAddresses !== []) {
                $email->cc(...$ccAddresses);
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

            $this->applyThreadingHeaders($email, $options);

            $messageId = $this->ensureMessageId($email, $account);
            try {
                $this->deliver($transport, $email);
            } catch (\Throwable $exception) {
                try {
                    Log::warning('SMTP provider send failed at the sanitized boundary.', [
                        'account_id' => $account->id,
                        'reason' => 'smtp_send_outcome_unresolved',
                        'exception' => $exception::class,
                    ]);
                } catch (\Throwable) {
                    // Logging cannot weaken the secret-free exception boundary.
                }

                throw new EmailProviderSendOutcomeUnresolvedException(
                    'The Email provider send outcome could not be confirmed.',
                );
            }

            try {
                $this->recordSuccessfulSendTelemetry($account);
            } catch (\Throwable $exception) {
                // SMTP acceptance is the delivery boundary. Account telemetry
                // may never create a duplicate-send invitation.
                try {
                    Log::warning('SMTP accepted an Email message, but account telemetry could not be updated.', [
                        'account_id' => $account->id,
                        'exception' => $exception::class,
                    ]);
                } catch (\Throwable) {
                    // Logging is also secondary after provider acceptance.
                }
            }

            return $messageId;
        } finally {
            if ($transport instanceof EsmtpTransport) {
                try {
                    $transport->stop();
                } catch (\Throwable $exception) {
                    try {
                        Log::notice('SMTP provider transport cleanup failed.', [
                            'account_id' => $account->id,
                            'exception' => $exception::class,
                        ]);
                    } catch (\Throwable) {
                    }
                }
            }

            $providerLock->release();
        }
    }

    /**
     * Pre-generate the exact RFC Message-ID that will be stored in the atomic
     * send reservation and passed to Symfony before SMTP.
     */
    public function generateMessageId(#[\SensitiveParameter] EmailAccount $account): string
    {
        return '<'.$this->newMessageId($account).'>';
    }

    protected function deliver(
        #[\SensitiveParameter] EsmtpTransport $transport,
        #[\SensitiveParameter] Email $email,
    ): void
    {
        (new Mailer($transport))->send($email);
    }

    protected function recordSuccessfulSendTelemetry(#[\SensitiveParameter] EmailAccount $account): void
    {
        $account->forceFill([
            'last_successful_send_at' => now(),
            'last_error_code' => null,
            'last_error_message' => null,
        ])->save();
    }

    /**
     * @param  array<int, array{email: string, name?: string|null}>  $recipients
     * @return array<int, Address>
     */
    private function addresses(#[\SensitiveParameter] array $recipients): array
    {
        return collect($recipients)
            ->map(function (array $recipient): ?Address {
                $email = trim((string) ($recipient['email'] ?? ''));

                if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    return null;
                }

                return new Address($email, trim((string) ($recipient['name'] ?? '')));
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array{message_id?: string|null, in_reply_to?: string|null, references?: string|null}  $options
     */
    private function applyThreadingHeaders(
        #[\SensitiveParameter] Email $email,
        #[\SensitiveParameter] array $options,
    ): void
    {
        $headers = $email->getHeaders();
        $messageId = $this->cleanHeaderValue($options['message_id'] ?? null);

        if ($messageId !== '' && ! $headers->has('Message-ID')) {
            $headers->addIdHeader('Message-ID', trim($messageId, '<>'));
        }

        $inReplyTo = $this->cleanHeaderValue($options['in_reply_to'] ?? null);

        if ($inReplyTo !== '' && ! $headers->has('In-Reply-To')) {
            $headers->addTextHeader('In-Reply-To', $inReplyTo);
        }

        $references = $this->cleanHeaderValue($options['references'] ?? null);

        if ($references !== '' && ! $headers->has('References')) {
            $headers->addTextHeader('References', $references);
        }
    }

    private function cleanHeaderValue(?string $value): string
    {
        return trim((string) preg_replace('/[\r\n]+/', ' ', (string) $value));
    }

    private function ensureMessageId(
        #[\SensitiveParameter] Email $email,
        #[\SensitiveParameter] EmailAccount $account,
    ): string
    {
        $headers = $email->getHeaders();

        if (! $headers->has('Message-ID')) {
            $headers->addIdHeader('Message-ID', trim($this->generateMessageId($account), '<>'));
        }

        return $headers->get('Message-ID')->getBodyAsString();
    }

    private function newMessageId(#[\SensitiveParameter] EmailAccount $account): string
    {
        $domain = trim((string) str($account->address)->after('@'));
        $domain = preg_replace('/[^a-z0-9.-]/i', '', $domain) ?: parse_url((string) config('app.url'), PHP_URL_HOST);
        $domain = $domain ?: 'nexum-psa.local';

        return bin2hex(random_bytes(16)).'@'.$domain;
    }
}
