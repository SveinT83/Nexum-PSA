<?php

namespace App\Modules\Integration\Support;

use Illuminate\Support\HtmlString;

class AiMessageFormatter
{
    /**
     * Render safe chat text with markdown-style links that open in a new tab.
     */
    public static function render(?string $body): HtmlString
    {
        $escaped = e((string) $body);
        $linked = preg_replace_callback(
            '/\[(.+?)\]\((https?:\/\/[^\s)]+)\)/',
            function (array $matches) {
                return sprintf(
                    '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
                    e($matches[2]),
                    e($matches[1])
                );
            },
            $escaped
        );

        return new HtmlString(nl2br($linked ?? $escaped));
    }
}
