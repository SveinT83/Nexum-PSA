<?php

namespace App\Modules\Email\Support;

final class EmailSubjectPresenter
{
    private const MAX_INPUT_BYTES = 16384;

    private const MAX_OUTPUT_CHARACTERS = 512;

    /**
     * Decode common RFC 2047 encoded words for presentation only. The stored
     * subject remains untouched because it also participates in search, rules,
     * Ticket correlation, provider evidence, and legacy conversation identity.
     */
    public static function present(?string $subject): ?string
    {
        if ($subject === null) {
            return null;
        }

        $value = substr($subject, 0, self::MAX_INPUT_BYTES);
        $value = preg_replace("/\r\n[ \t]+/", ' ', $value) ?? $value;
        $value = str_replace(["\r", "\n", "\0"], ' ', $value);

        if (preg_match('/=\?[^?\s]{1,64}\?[bq]\?/i', $value) === 1) {
            // RFC 2047 discards linear whitespace between adjacent encoded words.
            $value = preg_replace('/\?=[ \t]+(?==\?)/', '?=', $value) ?? $value;
            $segments = [];
            $offset = 0;
            $matches = [];
            preg_match_all(
                '/=\?([^?\s]{1,64})\?([bq])\?([^?]*)\?=/i',
                $value,
                $matches,
                PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
            );

            foreach ($matches as $match) {
                $word = $match[0][0];
                $wordOffset = $match[0][1];
                $segments[] = substr($value, $offset, $wordOffset - $offset);

                $decoded = self::decodeWord($match[1][0], $match[2][0], $match[3][0], false);
                $segments[] = $decoded !== null
                    && preg_match('/=\?[^?\s]{1,64}\?[bq]\?/i', $decoded) !== 1
                        ? $decoded
                        : $word;
                $offset = $wordOffset + strlen($word);
            }

            // Complete words are already final segments. Only the untouched raw
            // tail is eligible for the provider-specific truncated-word salvage,
            // which prevents recursive decoding without collision-prone tokens.
            $value = substr($value, $offset);

            // Some providers expose a truncated final encoded word. Salvage only
            // a bounded terminal token; never run a greedy whole-header decoder.
            $value = preg_replace_callback(
                '/=\?([^?\s]{1,64})\?([bq])\?([^\r\n]*)$/i',
                function (array $match): string {
                    $decoded = self::decodeWord($match[1], $match[2], $match[3], true);

                    return $decoded !== null
                        && preg_match('/=\?[^?\s]{1,64}\?[bq]\?/i', $decoded) !== 1
                            ? $decoded
                            : $match[0];
                },
                $value,
            ) ?? $value;
            $segments[] = $value;
            $value = implode('', $segments);
        }

        $value = self::validUtf8($value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? $value;
        $value = preg_replace('/[\p{Z}\s]+/u', ' ', $value) ?? $value;
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        return mb_substr($value, 0, self::MAX_OUTPUT_CHARACTERS);
    }

    private static function decodeWord(
        string $charset,
        string $encoding,
        string $encodedText,
        bool $truncated,
    ): ?string {
        if (mb_strtolower($encoding) === 'q') {
            $encodedText = str_replace('_', ' ', $encodedText);
            if ($truncated) {
                $encodedText = rtrim($encodedText, '=');
            }

            $bytes = preg_replace_callback(
                '/=([0-9a-f]{2})/i',
                fn (array $match): string => chr((int) hexdec($match[1])),
                $encodedText,
            );
        } else {
            $encodedText = preg_replace('/\s+/', '', $encodedText) ?? '';
            if ($truncated && $encodedText !== '') {
                $encodedText .= str_repeat('=', (4 - strlen($encodedText) % 4) % 4);
            }

            $bytes = base64_decode($encodedText, true);
        }

        if (! is_string($bytes)) {
            return null;
        }

        return self::convertToUtf8($bytes, $charset);
    }

    private static function convertToUtf8(string $value, string $charset): ?string
    {
        $normalized = mb_strtolower(str_replace(['_', ' '], ['-', ''], trim($charset)));
        $source = match ($normalized) {
            'utf-8', 'utf8' => 'UTF-8',
            'us-ascii', 'ascii' => 'ASCII',
            'iso-8859-1', 'iso8859-1', 'latin1', 'latin-1' => 'ISO-8859-1',
            'iso-8859-15', 'iso8859-15', 'latin9', 'latin-9' => 'ISO-8859-15',
            'windows-1252', 'windows1252', 'cp1252' => 'Windows-1252',
            default => null,
        };

        if ($source === null) {
            return null;
        }

        // The sentinel lets iconv discard an incomplete trailing multibyte byte
        // instead of failing the entire compatibility decode.
        $converted = @iconv($source, 'UTF-8//IGNORE', $value."\n");
        if (is_string($converted) && str_ends_with($converted, "\n")) {
            return substr($converted, 0, -1);
        }

        try {
            return mb_convert_encoding($value, 'UTF-8', $source);
        } catch (\ValueError) {
            return null;
        }
    }

    private static function validUtf8(string $value): string
    {
        if (mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $value."\n");

        return is_string($clean) && str_ends_with($clean, "\n")
            ? substr($clean, 0, -1)
            : preg_replace('/[^\x20-\x7E\x09]/', '', $value) ?? '';
    }
}
