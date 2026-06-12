<?php

namespace App\Modules\Email\Services;

use App\Modules\Contact\Models\Contact;
use App\Modules\Contact\Models\ContactEmail;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Signal\Actions\RecordSignal;
use App\Modules\Signal\Models\Signal;

class InboundEmailSignalClassifier
{
    public const MACHINE_SIGNAL_TYPES = [
        'hard_bounce',
        'soft_bounce',
        'auto_reply',
        'out_of_office',
        'unsubscribe_request',
        'vendor_notification',
    ];

    public function __construct(private readonly RecordSignal $signals)
    {
    }

    public function classifyAndRecord(EmailMessage $message): ?Signal
    {
        $existing = Signal::query()
            ->where('source_type', $message->getMorphClass())
            ->where('source_id', $message->id)
            ->first();

        if ($existing) {
            return $existing;
        }

        $classification = $this->classify($message);

        if (! $classification) {
            return null;
        }

        $contact = $this->resolveContact($message, $classification['recipient_email'] ?? null);

        return $this->signals->handle([
            'source_domain' => 'email',
            'source_type' => $message->getMorphClass(),
            'source_id' => $message->id,
            'subject_type' => $contact?->getMorphClass(),
            'subject_id' => $contact?->id,
            'contact_id' => $contact?->id,
            'client_id' => $this->clientIdForContact($contact),
            'signal_type' => $classification['signal_type'],
            'severity' => $classification['severity'],
            'confidence' => $classification['confidence'],
            'summary' => $classification['summary'],
            'payload' => array_merge($classification['payload'], [
                'email_message_id' => $message->id,
                'from_email' => $message->from_email,
                'subject' => $message->subject,
            ]),
            'occurred_at' => $message->received_at ?: now(),
        ]);
    }

    public function shouldStopTicketRouting(?Signal $signal): bool
    {
        return $signal && in_array($signal->signal_type, self::MACHINE_SIGNAL_TYPES, true);
    }

    private function classify(EmailMessage $message): ?array
    {
        $subject = mb_strtolower((string) $message->subject);
        $body = mb_strtolower((string) $message->body_text);
        $from = mb_strtolower((string) $message->from_email);
        $headers = $this->headersText($message);
        $combined = $subject."\n".$body."\n".$from."\n".$headers;

        if ($this->looksLikeBounce($combined)) {
            $hard = $this->looksLikeHardBounce($combined);
            $recipient = $this->recipientFromDeliveryStatus($combined);

            return [
                'signal_type' => $hard ? 'hard_bounce' : 'soft_bounce',
                'severity' => $hard ? 'error' : 'warning',
                'confidence' => $hard ? 95 : 85,
                'summary' => $hard ? 'Inbound email classified as hard bounce.' : 'Inbound email classified as soft bounce.',
                'recipient_email' => $recipient,
                'payload' => [
                    'recipient_email' => $recipient,
                    'classifier' => 'email-inbound-v1',
                ],
            ];
        }

        if ($vendor = $this->vendorNotification($combined)) {
            return [
                'signal_type' => 'vendor_notification',
                'severity' => str_contains($combined, 'security') || str_contains($combined, 'vulnerability') || str_contains($combined, 'cve-')
                    ? 'warning'
                    : 'info',
                'confidence' => 80,
                'summary' => 'Inbound email classified as vendor notification.',
                'recipient_email' => null,
                'payload' => [
                    'vendor' => $vendor,
                    'title' => $message->subject,
                    'classifier' => 'email-inbound-v1',
                ],
            ];
        }

        if ($this->looksLikeUnsubscribeRequest($combined)) {
            return [
                'signal_type' => 'unsubscribe_request',
                'severity' => 'warning',
                'confidence' => 85,
                'summary' => 'Inbound email requested unsubscribe.',
                'recipient_email' => $message->from_email,
                'payload' => ['classifier' => 'email-inbound-v1'],
            ];
        }

        if ($this->looksLikeOutOfOffice($combined)) {
            return [
                'signal_type' => 'out_of_office',
                'severity' => 'info',
                'confidence' => 90,
                'summary' => 'Inbound email classified as out of office.',
                'recipient_email' => $message->from_email,
                'payload' => ['classifier' => 'email-inbound-v1'],
            ];
        }

        if ($this->looksLikeAutoReply($combined)) {
            return [
                'signal_type' => 'auto_reply',
                'severity' => 'info',
                'confidence' => 85,
                'summary' => 'Inbound email classified as automatic reply.',
                'recipient_email' => $message->from_email,
                'payload' => ['classifier' => 'email-inbound-v1'],
            ];
        }

        return null;
    }

    private function looksLikeBounce(string $content): bool
    {
        return str_contains($content, 'multipart/report')
            || str_contains($content, 'delivery-status')
            || str_contains($content, 'mailer-daemon')
            || str_contains($content, 'postmaster')
            || str_contains($content, 'undeliverable')
            || str_contains($content, 'delivery status notification')
            || str_contains($content, 'returned mail')
            || str_contains($content, 'delivery has failed');
    }

    private function looksLikeHardBounce(string $content): bool
    {
        foreach (['5.1.1', '550', 'user unknown', 'no such user', 'recipient address rejected', 'mailbox unavailable', 'address not found'] as $needle) {
            if (str_contains($content, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeUnsubscribeRequest(string $content): bool
    {
        foreach (['unsubscribe', 'avmeld', 'meld meg av', 'stop receiving', 'remove me from'] as $needle) {
            if (str_contains($content, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function vendorNotification(string $content): ?string
    {
        $hasNotificationTerms = collect([
            'firmware',
            'update',
            'security advisory',
            'security update',
            'vulnerability',
            'cve-',
            'release notes',
        ])->contains(fn (string $needle): bool => str_contains($content, $needle));

        if (! $hasNotificationTerms) {
            return null;
        }

        foreach ([
            'qnap' => ['qnap', 'qnap.com'],
        ] as $vendor => $needles) {
            if (collect($needles)->contains(fn (string $needle): bool => str_contains($content, $needle))) {
                return $vendor;
            }
        }

        return null;
    }

    private function looksLikeOutOfOffice(string $content): bool
    {
        foreach (['out of office', 'ferie', 'ikke til stede', 'out-of-office', 'away from the office'] as $needle) {
            if (str_contains($content, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeAutoReply(string $content): bool
    {
        return str_contains($content, 'auto-submitted: auto')
            || str_contains($content, 'x-autoreply')
            || str_contains($content, 'automatic reply')
            || str_contains($content, 'auto reply')
            || str_contains($content, 'autoreply')
            || str_contains($content, 'this is an automatic reply')
            || str_contains($content, 'this is an automated response');
    }

    private function recipientFromDeliveryStatus(string $content): ?string
    {
        foreach ([
            '/final-recipient:\s*rfc822;\s*([^\s;]+)/i',
            '/original-recipient:\s*rfc822;\s*([^\s;]+)/i',
            '/for\s+<([^>]+)>/i',
        ] as $pattern) {
            if (preg_match($pattern, $content, $matches) && filter_var($matches[1], FILTER_VALIDATE_EMAIL)) {
                return strtolower($matches[1]);
            }
        }

        return null;
    }

    private function resolveContact(EmailMessage $message, ?string $recipientEmail): ?Contact
    {
        $email = $recipientEmail ?: $message->from_email;

        if (! $email) {
            return null;
        }

        return ContactEmail::query()
            ->with('contact.relations')
            ->where('email', $email)
            ->first()
            ?->contact;
    }

    private function clientIdForContact(?Contact $contact): ?int
    {
        return $contact?->relations
            ->first(fn ($relation): bool => str_contains((string) $relation->related_type, 'Client'))
            ?->related_id;
    }

    private function headersText(EmailMessage $message): string
    {
        return collect((array) $message->headers_json)
            ->map(fn ($value, $key): string => mb_strtolower((string) $key).': '.mb_strtolower(is_array($value) ? implode(' ', $value) : (string) $value))
            ->implode("\n");
    }
}
