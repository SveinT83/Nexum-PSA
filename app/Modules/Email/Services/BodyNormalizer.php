<?php

namespace App\Modules\Email\Services;

class BodyNormalizer
{
    public static function toText(?string $htmlOrText): ?string
    {
        if ($htmlOrText === null) {
            return null;
        }

        // Strip scripts & styles
        $text = preg_replace('@<script\b[^>]*>(.*?)</script>@is', ' ', $htmlOrText);
        $text = preg_replace('@<style\b[^>]*>(.*?)</style>@is', ' ', $text);
        // Remove tags
        $text = strip_tags($text);
        // Decode entities
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Collapse whitespace
        $text = preg_replace('/\s+/u', ' ', $text);
        return trim($text);
    }

    public static function stripQuotedHistory(string $body): string
    {
        $body = str_replace(["\r\n", "\r"], "\n", trim($body));

        if ($body === '') {
            return '';
        }

        $lines = explode("\n", $body);
        $kept = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // Common reply delimiters from Gmail, Outlook, Apple Mail, and Scandinavian-localized clients.
            if (
                $trimmed === '--- Please reply above this line ---'
                || preg_match('/^On .+ wrote:$/iu', $trimmed)
                || preg_match('/^(man|tir|ons|tor|fre|lør|lor|søn|son)\.?\s+.+\s+skrev\s+.+:$/iu', $trimmed)
                || preg_match('/^Den .+ skrev .+:$/iu', $trimmed)
                || preg_match('/^-{2,}\s*Original Message\s*-{2,}$/iu', $trimmed)
                || preg_match('/^(From|Fra|Sent|Sendt|To|Til|Subject|Emne):\s+/iu', $trimmed)
                || str_starts_with($trimmed, '>')
            ) {
                break;
            }

            $kept[] = $line;
        }

        return trim(implode("\n", $kept));
    }
}
