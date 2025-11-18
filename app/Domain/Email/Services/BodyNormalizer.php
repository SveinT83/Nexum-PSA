<?php

namespace App\Domain\Email\Services;

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
}
