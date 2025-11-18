<?php

namespace App\Domain\Email\Services;

class HtmlSanitizer
{
    /**
     * Basic sanitizer placeholder. Replace with HTMLPurifier integration later.
     */
    public static function sanitize(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }
        // Remove script/style tags entirely.
        $clean = preg_replace('@<script\b[^>]*>(.*?)</script>@is', '', $html);
        $clean = preg_replace('@<style\b[^>]*>(.*?)</style>@is', '', $clean);
        // Optionally strip onload/onerror handlers.
        $clean = preg_replace('/ on[a-zA-Z]+="[^"]*"/u', '', $clean);
        return $clean;
    }
}
