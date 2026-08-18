<?php

namespace App\Modules\Email\Services;

use Throwable;
use Webklex\PHPIMAP\Message;

/**
 * Builds reparsable RFC 822 snapshots and reads the historical body-only format.
 */
final class EmailRawMessageSnapshot
{
    /**
     * Build a complete message snapshot only when a local round trip preserves
     * the attachment structure exposed by the provider message.
     */
    public function serialize(object $message): ?string
    {
        if (! method_exists($message, 'getHeader') || ! method_exists($message, 'getRawBody')) {
            return null;
        }

        try {
            $header = $message->getHeader();
            $rawHeader = is_object($header) && property_exists($header, 'raw')
                ? (string) $header->raw
                : '';
            $rawHeader = $this->headerBlock($rawHeader);

            if (! $this->isHeaderBlock($rawHeader)) {
                return null;
            }

            $snapshot = rtrim($rawHeader, "\r\n")."\r\n\r\n".(string) $message->getRawBody();
            $parsed = $this->parseComplete($snapshot);

            if (! $parsed) {
                return null;
            }

            if (method_exists($message, 'getAttachments')
                && count($parsed->getAttachments()) !== $this->iterableCount($message->getAttachments())) {
                return null;
            }

            return $snapshot;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Parse a current full snapshot or reconstruct the minimum top-level MIME
     * header required by body-only snapshots written by older workers.
     *
     * @param  array<string, mixed>  $storedHeaders
     */
    public function parseStored(string $snapshot, array $storedHeaders = []): ?Message
    {
        $parsed = $this->parseComplete($snapshot);

        if ($parsed) {
            return $parsed;
        }

        $contentType = $this->storedHeaderValue($storedHeaders, 'content-type');
        if ($contentType === null) {
            return null;
        }

        $headers = [
            'MIME-Version: '.($this->storedHeaderValue($storedHeaders, 'mime-version') ?: '1.0'),
            'Content-Type: '.$contentType,
        ];

        foreach ([
            'content-transfer-encoding' => 'Content-Transfer-Encoding',
            'content-disposition' => 'Content-Disposition',
        ] as $storedName => $rfcName) {
            $value = $this->storedHeaderValue($storedHeaders, $storedName);
            if ($value !== null) {
                $headers[] = $rfcName.': '.$value;
            }
        }

        try {
            return Message::fromString(implode("\r\n", $headers)."\r\n\r\n".$snapshot);
        } catch (Throwable) {
            return null;
        }
    }

    private function parseComplete(string $snapshot): ?Message
    {
        $header = $this->headerBlock($snapshot);
        if (! $this->isHeaderBlock($header) || strlen($header) === strlen($snapshot)) {
            return null;
        }

        try {
            return Message::fromString($snapshot);
        } catch (Throwable) {
            return null;
        }
    }

    private function headerBlock(string $raw): string
    {
        $parts = preg_split('/\r\n\r\n|\n\n|\r\r/', $raw, 2);

        return (string) ($parts[0] ?? '');
    }

    private function isHeaderBlock(string $header): bool
    {
        $firstLine = (string) (preg_split('/\r\n|\n|\r/', $header, 2)[0] ?? '');

        return preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+:[ \t]*/', $firstLine) === 1;
    }

    /** @param array<string, mixed> $headers */
    private function storedHeaderValue(array $headers, string $wanted): ?string
    {
        foreach ($headers as $name => $values) {
            if (mb_strtolower(trim((string) $name)) !== $wanted) {
                continue;
            }

            if (is_array($values)) {
                $values = collect($values)->first(fn (mixed $value): bool => is_scalar($value));
            }

            if (! is_scalar($values)) {
                return null;
            }

            $value = preg_replace('/[\r\n\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', ' ', (string) $values) ?? '';
            $value = trim(preg_replace('/[ \t]+/u', ' ', $value) ?? '');

            return $value !== '' ? mb_substr($value, 0, 8192) : null;
        }

        return null;
    }

    private function iterableCount(mixed $items): int
    {
        if (is_countable($items)) {
            return count($items);
        }

        if (is_iterable($items)) {
            return iterator_count((function () use ($items): \Generator {
                yield from $items;
            })());
        }

        return 0;
    }
}
