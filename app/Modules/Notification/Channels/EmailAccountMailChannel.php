<?php

namespace App\Modules\Notification\Channels;

use App\Modules\Email\Services\EmailProviderBindingSnapshot;
use App\Modules\Email\Services\EmailProviderSendOutcomeUnresolvedException;
use App\Modules\Email\Services\SmtpAccountMailer;
use App\Modules\Integration\Exceptions\EmailProviderSecurityException;
use App\Modules\Notification\Contracts\EmailAccountMailNotification;
use App\Modules\Notification\Exceptions\EmailAccountNotificationDeliveryException;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Markdown;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Notification-owned bridge to the Integration-backed Email SMTP boundary.
 *
 * This channel deliberately never invokes Laravel's configured system mailer.
 * An ambiguous SMTP result is terminal for this attempt and is returned as
 * unresolved, not thrown into Laravel's automatic queued-notification retry.
 */
final class EmailAccountMailChannel
{
    public function __construct(
        private readonly EmailProviderBindingSnapshot $bindings,
        private readonly SmtpAccountMailer $mailer,
        private readonly Markdown $markdown,
        private readonly ConfigRepository $config,
    ) {}

    /**
     * @return array{status: 'delivered'|'blocked'|'unresolved', reason_code: string, message_id?: string}
     */
    public function send(
        #[\SensitiveParameter] object $notifiable,
        #[\SensitiveParameter] Notification $notification,
    ): array {
        if (! $notification instanceof EmailAccountMailNotification) {
            return $this->blocked($notification, null, 'provider_notification_contract_missing');
        }

        $snapshot = $notification->emailAccountMailSnapshot();
        $accountId = $snapshot['account_id'];

        if (! $snapshot['captured']
            || blank($snapshot['scope'])
            || ! $accountId
            || ! $snapshot['provider_binding_version']) {
            return $this->blocked(
                $notification,
                $accountId,
                $snapshot['failure_code'] ?: 'provider_binding_snapshot_missing',
            );
        }

        try {
            $account = $this->bindings->resolveScope(
                (string) $snapshot['scope'],
                $accountId,
                $snapshot['provider_binding_version'],
            );
            $message = $notification->toMail($notifiable);
            $this->assertSupported($message);
            $recipients = $this->recipients(
                $notifiable->routeNotificationFor('mail', $notification),
            );

            if ($recipients === []) {
                throw new InvalidArgumentException('provider_notification_recipient_missing');
            }

            $renderer = $this->markdown->theme(
                $message->theme ?: $this->config->get('mail.markdown.theme', 'default'),
            );
            $data = array_merge($message->data(), [
                '__laravel_notification_id' => $notification->id,
                '__laravel_notification' => $notification::class,
            ]);
            $messageId = $this->mailer->sendMessage(
                $account,
                $recipients,
                $message->subject ?: Str::title(Str::snake(class_basename($notification), ' ')),
                $renderer->render((string) $message->markdown, $data)->toHtml(),
                $renderer->renderText((string) $message->markdown, $data)->toHtml(),
                [],
                $this->addresses($message->cc),
                ['provider_binding_version' => $snapshot['provider_binding_version']],
            );

            return [
                'status' => 'delivered',
                'reason_code' => 'provider_notification_delivered',
                'message_id' => $messageId,
            ];
        } catch (EmailProviderSendOutcomeUnresolvedException) {
            // SMTP may already have accepted the message. Never throw this
            // attempt back to Laravel's blind notification retry mechanism.
            return $this->unresolved($notification, $accountId);
        } catch (EmailProviderSecurityException $exception) {
            return $this->blocked($notification, $accountId, $exception->reasonCode);
        } catch (\Throwable $exception) {
            return $this->blocked(
                $notification,
                $accountId,
                $exception instanceof InvalidArgumentException
                    ? $exception->getMessage()
                    : 'provider_notification_delivery_failed_prewrite',
                $exception,
            );
        }
    }

    private function assertSupported(#[\SensitiveParameter] MailMessage $message): void
    {
        if ($message->mailer
            || $message->view
            || ! is_string($message->markdown)
            || $message->markdown === ''
            || $message->from !== []
            || $message->replyTo !== []
            || $message->bcc !== []
            || $message->attachments !== []
            || $message->rawAttachments !== []
            || $message->tags !== []
            || $message->metadata !== []
            || $message->priority !== null
            || $message->callbacks !== []) {
            throw new InvalidArgumentException('provider_notification_message_contract_unsupported');
        }
    }

    /** @return array<int, array{email: string, name: string}> */
    private function recipients(#[\SensitiveParameter] mixed $route): array
    {
        if (is_string($route)) {
            $route = [$route];
        }

        if (! is_iterable($route)) {
            return [];
        }

        $recipients = [];

        foreach ($route as $key => $value) {
            if (is_string($key)) {
                $email = $key;
                $name = is_string($value) ? $value : '';
            } elseif (is_string($value)) {
                $email = $value;
                $name = '';
            } elseif (is_object($value)) {
                $email = $value->email ?? null;
                $name = $value->name ?? '';
            } else {
                continue;
            }

            if (! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            $recipients[] = ['email' => $email, 'name' => is_string($name) ? $name : ''];
        }

        return $recipients;
    }

    /**
     * @param  array<int, array{0: string, 1?: string|null}>  $addresses
     * @return array<int, array{email: string, name: string}>
     */
    private function addresses(#[\SensitiveParameter] array $addresses): array
    {
        return collect($addresses)
            ->map(fn (array $address): array => [
                'email' => (string) ($address[0] ?? ''),
                'name' => (string) ($address[1] ?? ''),
            ])
            ->filter(fn (array $address): bool => filter_var($address['email'], FILTER_VALIDATE_EMAIL) !== false)
            ->values()
            ->all();
    }

    /**
     * @return array{status: 'blocked', reason_code: string}
     */
    private function blocked(
        #[\SensitiveParameter] Notification $notification,
        ?int $accountId,
        string $reasonCode,
        ?\Throwable $exception = null,
    ): array {
        Log::warning('Email-account Notification delivery was blocked before a confirmed send.', [
            'account_id' => $accountId,
            'notification' => $notification::class,
            'reason_code' => $this->safeReasonCode($reasonCode),
            'exception' => $exception ? $exception::class : null,
        ]);

        $safeReasonCode = $this->safeReasonCode($reasonCode);

        if (! $notification instanceof ShouldQueue) {
            throw new EmailAccountNotificationDeliveryException($safeReasonCode);
        }

        return [
            'status' => 'blocked',
            'reason_code' => $safeReasonCode,
        ];
    }

    /**
     * @return array{status: 'unresolved', reason_code: string}
     */
    private function unresolved(
        #[\SensitiveParameter] Notification $notification,
        ?int $accountId,
    ): array {
        Log::warning('Email-account Notification SMTP outcome is unresolved; automatic replay is blocked.', [
            'account_id' => $accountId,
            'notification' => $notification::class,
            'reason_code' => 'smtp_send_outcome_unresolved',
        ]);

        if (! $notification instanceof ShouldQueue) {
            throw new EmailAccountNotificationDeliveryException('smtp_send_outcome_unresolved');
        }

        return [
            'status' => 'unresolved',
            'reason_code' => 'smtp_send_outcome_unresolved',
        ];
    }

    private function safeReasonCode(string $reasonCode): string
    {
        $reasonCode = Str::lower(trim($reasonCode));

        return preg_match('/\A[a-z0-9_]{1,80}\z/', $reasonCode) === 1
            ? $reasonCode
            : 'provider_notification_delivery_failed_prewrite';
    }
}
