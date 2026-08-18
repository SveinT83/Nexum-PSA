<?php

namespace App\Modules\Email\Services;

use App\Modules\Email\Support\EmailProviderPath;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Throwable;
use Webklex\PHPIMAP\Header;
use Webklex\PHPIMAP\Message;

/**
 * Normalize a detached provider message for the existing local store.
 *
 * The Webklex Message passed here was built from direct BODY.PEEK literals and
 * has no provider client. Accessing its parsed header, body, or attachments can
 * therefore never reconnect or issue a compensating flag write.
 */
final class EmailProviderReconciliationMessagePayload
{
    private const MESSAGE_ID_CHARS = 255;

    private const SUBJECT_CHARS = 512;

    private const ADDRESS_CHARS = 255;

    /** @return array<string, mixed> */
    public function make(
        #[\SensitiveParameter] Message $message,
        int $accountId,
        int $bindingVersion,
        #[\SensitiveParameter] string $folderPath,
        int $uidValidity,
        int $sizeBytes,
        bool $oversize,
        #[\SensitiveParameter] ?array $rawProviderFlags = null,
    ): array {
        $folderPath = EmailProviderPath::normalize($folderPath);
        $from = $this->normalizeAddressList($message->getFrom())[0] ?? null;
        $strictRawFlags = $rawProviderFlags !== null;
        $flags = $strictRawFlags
            ? $this->rawProviderFlags($rawProviderFlags)
            : $this->messageFlags($message);

        return [
            'account_id' => $accountId,
            'provider_binding_version' => $bindingVersion,
            'mailbox' => $folderPath,
            'uid_validity' => $uidValidity,
            'imap_uid' => (int) $message->getUid(),
            'message_id' => $this->normalizeString(
                $message->getMessageId(),
                self::MESSAGE_ID_CHARS,
            ),
            'subject' => $this->normalizeString($message->getSubject(), self::SUBJECT_CHARS),
            'from_name' => $from['name'] ?? null,
            'from_email' => $from['email'] ?? null,
            'to' => $this->normalizeAddressList($message->getTo()),
            'cc' => $this->normalizeAddressList($message->getCc()),
            'in_reply_to' => $this->normalizeString(
                $message->getInReplyTo(),
                self::MESSAGE_ID_CHARS,
            ),
            'references' => implode(' ', $this->normalizeScalarList($message->getReferences())),
            'headers' => $this->normalizeHeaders($message->getHeader()),
            'received_at' => $this->normalizeDate($message->getDate()),
            'size_bytes' => $sizeBytes,
            'flags' => $flags,
            // RFC system flags require the leading backslash. A legal custom
            // keyword named `Seen` must remain custom and cannot silently
            // become provider Seen evidence.
            'provider_seen' => $this->hasFlag($flags, 'Seen', $strictRawFlags),
            'provider_answered' => $this->hasFlag($flags, 'Answered', $strictRawFlags),
            'provider_flagged' => $this->hasFlag($flags, 'Flagged', $strictRawFlags),
            'provider_deleted' => $this->hasFlag($flags, 'Deleted', $strictRawFlags),
            'provider_draft' => $this->hasFlag($flags, 'Draft', $strictRawFlags),
            'is_oversize' => $oversize,
            'require_exact_provider_identity' => true,
            'run_provider_reconciliation' => false,
        ];
    }

    /** @return array<int, array{name: string|null, email: string|null}> */
    private function normalizeAddressList(#[\SensitiveParameter] mixed $attribute): array
    {
        if (is_object($attribute)) {
            if (method_exists($attribute, 'toArray')) {
                $attribute = $attribute->toArray();
            } elseif ($attribute instanceof \Traversable) {
                $attribute = iterator_to_array($attribute);
            }
        }
        if (! is_array($attribute)) {
            return [];
        }

        $addresses = [];
        foreach ($attribute as $candidate) {
            $name = null;
            $email = null;
            if (is_array($candidate)) {
                $name = $candidate['personal'] ?? $candidate['name'] ?? null;
                $email = $candidate['mail'] ?? $candidate['email'] ?? $candidate['address'] ?? null;
                if (! $email && isset($candidate['mailbox'], $candidate['host'])) {
                    $email = $candidate['mailbox'].'@'.$candidate['host'];
                }
            } elseif (is_object($candidate)) {
                $name = $candidate->personal ?? $candidate->name
                    ?? (method_exists($candidate, 'getName') ? $candidate->getName() : null);
                $email = $candidate->mail ?? $candidate->email
                    ?? (method_exists($candidate, 'getAddress') ? $candidate->getAddress() : null);
                if (! $email) {
                    $mailbox = $candidate->mailbox
                        ?? (method_exists($candidate, 'getMailbox') ? $candidate->getMailbox() : null);
                    $host = $candidate->host
                        ?? (method_exists($candidate, 'getHost') ? $candidate->getHost() : null);
                    if ($mailbox && $host) {
                        $email = $mailbox.'@'.$host;
                    }
                }
            }

            $addresses[] = [
                'name' => is_scalar($name)
                    ? mb_substr((string) $name, 0, self::ADDRESS_CHARS)
                    : null,
                'email' => is_scalar($email)
                    ? mb_substr((string) $email, 0, self::ADDRESS_CHARS)
                    : null,
            ];
        }

        return $addresses;
    }

    /** @return array<int, string> */
    private function normalizeScalarList(#[\SensitiveParameter] mixed $attribute): array
    {
        if ($attribute === null) {
            return [];
        }
        if (is_string($attribute)) {
            return [$attribute];
        }
        if (is_object($attribute)) {
            if (method_exists($attribute, 'toArray')) {
                $attribute = $attribute->toArray();
            } elseif ($attribute instanceof \Traversable) {
                $attribute = iterator_to_array($attribute);
            }
        }

        return is_array($attribute)
            ? array_values(array_map(fn (mixed $value): string => (string) $value, $attribute))
            : [];
    }

    /** @return array<int, string> */
    private function messageFlags(#[\SensitiveParameter] Message $message): array
    {
        $flags = $message->getFlags();
        if ($flags instanceof \Traversable) {
            $flags = iterator_to_array($flags);
        } elseif (is_object($flags) && method_exists($flags, 'toArray')) {
            $flags = $flags->toArray();
        }

        return is_array($flags)
            ? array_values(array_map(fn (mixed $flag): string => ltrim((string) $flag, '\\'), $flags))
            : [];
    }

    /** @param array<int, mixed> $flags @return array<int, string> */
    private function rawProviderFlags(#[\SensitiveParameter] array $flags): array
    {
        return array_values(array_map(
            static fn (mixed $flag): string => trim((string) $flag),
            $flags,
        ));
    }

    /** @param array<int, string> $flags */
    private function hasFlag(array $flags, string $expected, bool $strictRawFlags): bool
    {
        $expected = mb_strtolower(ltrim($expected, '\\'));

        return collect($flags)->contains(
            static function (string $flag) use ($expected, $strictRawFlags): bool {
                $flag = trim($flag);

                return (! $strictRawFlags || str_starts_with($flag, '\\'))
                    && mb_strtolower(ltrim($flag, '\\')) === $expected;
            },
        );
    }

    /** @return array<string, array<int, string>> */
    private function normalizeHeaders(#[\SensitiveParameter] ?Header $header): array
    {
        $raw = $header?->raw ?? '';
        if ($raw === '') {
            return [];
        }

        $headers = [];
        $currentName = null;
        $currentIndex = null;
        foreach (preg_split('/\r\n|\n|\r/', $raw) ?: [] as $line) {
            if ($line === '') {
                break;
            }
            if (preg_match('/^[ \t]+(.*)$/', $line, $continuation)) {
                if ($currentName !== null && $currentIndex !== null) {
                    $value = trim($continuation[1]);
                    if ($value !== '') {
                        $headers[$currentName][$currentIndex] .= ' '.$value;
                    }
                }

                continue;
            }
            if (! preg_match('/^([^:\s]+):[ \t]*(.*)$/', $line, $match)) {
                $currentName = null;
                $currentIndex = null;

                continue;
            }

            $currentName = mb_strtolower($match[1]);
            $headers[$currentName] ??= [];
            $headers[$currentName][] = trim($match[2]);
            $currentIndex = array_key_last($headers[$currentName]);
        }

        return $headers;
    }

    private function normalizeString(
        #[\SensitiveParameter] mixed $value,
        int $maxCharacters,
    ): ?string {
        if ($value === null) {
            return null;
        }
        if (is_object($value) && method_exists($value, 'toString')) {
            $value = $value->toString();
        }
        if (is_object($value) && method_exists($value, 'first')) {
            $value = $value->first();
        }
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $maxCharacters);
    }

    private function normalizeDate(#[\SensitiveParameter] mixed $value): ?CarbonInterface
    {
        if ($value instanceof CarbonInterface) {
            return $value;
        }
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (Throwable) {
            return null;
        }
    }
}
