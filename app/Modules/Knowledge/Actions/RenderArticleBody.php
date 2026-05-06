<?php

namespace App\Modules\Knowledge\Actions;

use Illuminate\Support\Str;

/**
 * Converts stored article markdown into HTML.
 *
 * The current module keeps the conversion deliberately conservative. Laravel's
 * Str::markdown helper is used when available. If it is not available in the
 * installed framework version, the raw markdown is escaped and line breaks are
 * preserved so articles still render safely.
 */
class RenderArticleBody
{
    /**
     * Convert a markdown string to safe HTML for storage/display.
     */
    public function handle(string $markdown): string
    {
        if (method_exists(Str::class, 'markdown')) {
            return (string) Str::markdown($markdown);
        }

        return nl2br(e($markdown));
    }
}
