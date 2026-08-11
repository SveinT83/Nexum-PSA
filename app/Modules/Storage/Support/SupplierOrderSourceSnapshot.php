<?php

namespace App\Modules\Storage\Support;

use App\Modules\Email\Models\EmailMessage;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SupplierOrderSourceSnapshot
{
    private const MAX_HTML_BYTES = 500_000;

    private const MAX_TEXT_BYTES = 250_000;

    private const MAX_ATTACHMENTS = 50;

    private const FORBIDDEN_KEYS = [
        'raw_path',
        'raw_eml',
        'headers',
        'headers_json',
        'authorization',
        'cookie',
        'password',
        'prompt',
        'model_response',
        'ai_raw_reply',
    ];

    /**
     * Build an immutable, UI-safe snapshot independent of Email retention.
     *
     * @param  array<string, mixed>  $trustedAuth
     * @return array{snapshot: array<string, mixed>, fingerprint: string}
     */
    public function fromEmailMessage(EmailMessage $message, array $trustedAuth = []): array
    {
        $message->loadMissing('attachments');

        $snapshot = [
            'schema_version' => 'storage.supplier_order_source.v1',
            'source' => 'email',
            'account_id' => $message->account_id,
            'mailbox' => $this->cleanScalar($message->mailbox, 255),
            'imap_uid' => $this->cleanScalar($message->imap_uid, 255),
            'message_id' => $this->cleanScalar($message->message_id, 500),
            'subject' => $this->cleanScalar($message->subject, 1000),
            'from' => [
                'name' => $this->cleanScalar($message->from_name, 500),
                'email' => $this->cleanEmail($message->from_email),
            ],
            'to' => $this->cleanAddresses($message->to_json ?? []),
            'cc' => $this->cleanAddresses($message->cc_json ?? []),
            'received_at' => $message->received_at?->toIso8601String(),
            'body_html' => $this->sanitizeHtml((string) $message->body_html_sanitized),
            'body_text' => $this->cleanBodyText((string) $message->body_text),
            'attachments' => $message->attachments
                ->take(self::MAX_ATTACHMENTS)
                ->map(fn ($attachment): array => [
                    'name' => $this->cleanScalar($attachment->filename ?? $attachment->name, 500),
                    'mime_type' => $this->cleanScalar($attachment->mime_type, 255),
                    'size_bytes' => max(0, (int) $attachment->size_bytes),
                    'checksum' => $this->cleanScalar(
                        $attachment->checksum_sha256 ?? $attachment->checksum_sha1,
                        64,
                    ),
                ])
                ->values()
                ->all(),
        ];

        $trustedProjection = $this->trustedAuthProjection($trustedAuth);
        $snapshot['trusted_auth'] = $trustedProjection;
        $this->assertSafe($snapshot);

        return [
            'snapshot' => $snapshot,
            'fingerprint' => StableJson::checksum($snapshot),
        ];
    }

    /**
     * Re-project a stored source before it becomes a protected profile fixture.
     *
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $trustedAuth
     * @return array<string, mixed>
     */
    public function sanitizeStoredSnapshot(array $snapshot, array $trustedAuth = []): array
    {
        $from = is_array($snapshot['from'] ?? null) ? $snapshot['from'] : [];
        $safe = [
            'schema_version' => 'storage.supplier_order_source.v1',
            'source' => $this->cleanScalar($snapshot['source'] ?? 'email', 64) ?? 'email',
            'account_id' => is_numeric($snapshot['account_id'] ?? null)
                ? (int) $snapshot['account_id']
                : null,
            'mailbox' => $this->cleanScalar($snapshot['mailbox'] ?? null, 255),
            'imap_uid' => $this->cleanScalar($snapshot['imap_uid'] ?? null, 255),
            'message_id' => $this->cleanScalar($snapshot['message_id'] ?? null, 500),
            'subject' => $this->cleanScalar($snapshot['subject'] ?? null, 1000),
            'from' => [
                'name' => $this->cleanScalar($from['name'] ?? null, 500),
                'email' => $this->cleanEmail($from['email'] ?? null),
            ],
            'to' => $this->cleanAddresses(
                is_array($snapshot['to'] ?? null) ? $snapshot['to'] : [],
            ),
            'cc' => $this->cleanAddresses(
                is_array($snapshot['cc'] ?? null) ? $snapshot['cc'] : [],
            ),
            'received_at' => $this->cleanScalar($snapshot['received_at'] ?? null, 100),
            'body_html' => $this->sanitizeHtml((string) ($snapshot['body_html'] ?? '')),
            'body_text' => $this->cleanBodyText((string) ($snapshot['body_text'] ?? '')),
            'attachments' => $this->cleanAttachmentDescriptors(
                is_array($snapshot['attachments'] ?? null) ? $snapshot['attachments'] : [],
            ),
            'trusted_auth' => $this->trustedAuthProjection(
                $trustedAuth !== [] ? $trustedAuth : (array) ($snapshot['trusted_auth'] ?? []),
            ),
        ];
        $this->assertSafe($safe);

        return $safe;
    }

    /** @param array<string, mixed> $facts */
    public function trustedAuthProjection(array $facts): array
    {
        return [
            'authentication_passed' => (bool) ($facts['authentication_passed'] ?? false),
            'authenticated_supplier_identity' => $this->cleanScalar(
                $facts['authenticated_supplier_identity'] ?? null,
                500,
            ),
            'authenticated_supplier_domain' => Str::lower($this->cleanScalar(
                $facts['authenticated_supplier_domain'] ?? null,
                255,
            ) ?? ''),
            'authserv_id' => $this->cleanScalar($facts['authserv_id'] ?? null, 255),
            'spf' => $this->authResult($facts['spf'] ?? null),
            'dkim' => $this->authResult($facts['dkim'] ?? null),
            'dmarc' => $this->authResult($facts['dmarc'] ?? null),
            'aligned' => (bool) ($facts['aligned'] ?? false),
        ];
    }

    /** @param array<int, mixed> $addresses */
    private function cleanAddresses(array $addresses): array
    {
        return collect($addresses)
            ->take(100)
            ->map(function (mixed $address): ?array {
                if (is_string($address)) {
                    return ['name' => null, 'email' => $this->cleanEmail($address)];
                }

                if (! is_array($address)) {
                    return null;
                }

                return [
                    'name' => $this->cleanScalar($address['name'] ?? null, 500),
                    'email' => $this->cleanEmail($address['email'] ?? $address['address'] ?? null),
                ];
            })
            ->filter(fn (?array $address): bool => filled($address['name'] ?? null) || filled($address['email'] ?? null))
            ->values()
            ->all();
    }

    /** @param array<int, mixed> $attachments */
    private function cleanAttachmentDescriptors(array $attachments): array
    {
        return collect($attachments)
            ->take(self::MAX_ATTACHMENTS)
            ->map(function (mixed $attachment): ?array {
                if (! is_array($attachment)) {
                    return null;
                }

                return [
                    'name' => $this->cleanScalar($attachment['name'] ?? $attachment['filename'] ?? null, 500),
                    'mime_type' => $this->cleanScalar($attachment['mime_type'] ?? null, 255),
                    'size_bytes' => max(0, (int) ($attachment['size_bytes'] ?? 0)),
                    'checksum' => $this->cleanScalar(
                        $attachment['checksum'] ?? $attachment['checksum_sha256'] ?? null,
                        64,
                    ),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function cleanEmail(mixed $value): ?string
    {
        $email = Str::lower($this->cleanScalar($value, 500) ?? '');

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    private function cleanScalar(mixed $value, int $maxLength): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', trim((string) $value));

        return $value === '' ? null : Str::limit($value, $maxLength, '');
    }

    private function cleanBodyText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';

        return Str::limit($text, self::MAX_TEXT_BYTES, '');
    }

    private function sanitizeHtml(string $html): string
    {
        if ($html === '') {
            return '';
        }

        $html = Str::limit($html, self::MAX_HTML_BYTES, '');
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"><div>'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($document);
        $root = $document->documentElement;

        foreach ($xpath->query('//script|//style|//link|//meta|//img|//iframe|//object|//embed|//form|//input|//button') ?: [] as $node) {
            $node->parentNode?->removeChild($node);
        }

        foreach ($xpath->query('//*') ?: [] as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            foreach (iterator_to_array($node->attributes ?? []) as $attribute) {
                $node->removeAttribute($attribute->name);
            }
        }

        return strip_tags(
            $root instanceof DOMElement ? ($document->saveHTML($root) ?: '') : '',
            '<div><p><br><hr><strong><b><em><i><u><ul><ol><li><table><thead><tbody><tfoot><tr><th><td><span><pre><code><blockquote>',
        );
    }

    private function authResult(mixed $value): string
    {
        $value = Str::lower($this->cleanScalar($value, 32) ?? 'unknown');

        return in_array($value, ['pass', 'fail', 'softfail', 'neutral', 'none', 'temperror', 'permerror'], true)
            ? $value
            : 'unknown';
    }

    private function assertSafe(array $snapshot): void
    {
        array_walk_recursive($snapshot, function (mixed $value, mixed $key): void {
            if (in_array(Str::lower((string) $key), self::FORBIDDEN_KEYS, true)) {
                throw new InvalidArgumentException('Forbidden source-snapshot field.');
            }
        });
    }
}
