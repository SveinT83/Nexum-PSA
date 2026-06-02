<?php

namespace App\Modules\Email\Services;

class HtmlSanitizer
{
    /**
     * Sanitize inbound email HTML before it is stored and rendered.
     *
     * Inbound email is untrusted content. Keep the allowed HTML broad enough for readable messages,
     * but remove scripts, event handlers, unsafe URLs, forms, and embedded active content.
     */
    public static function sanitize(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        if (class_exists(\HTMLPurifier::class) && class_exists(\HTMLPurifier_Config::class)) {
            $config = \HTMLPurifier_Config::createDefault();
            $config->set('Cache.SerializerPath', storage_path('framework/cache'));
            $config->set('HTML.Doctype', 'HTML 4.01 Transitional');
            $config->set('HTML.SafeIframe', false);
            $config->set('Attr.AllowedFrameTargets', ['_blank']);
            $config->set('URI.AllowedSchemes', [
                'http' => true,
                'https' => true,
                'mailto' => true,
                'tel' => true,
            ]);
            $config->set('HTML.Allowed', implode(',', [
                'a[href|title|target|rel]',
                'abbr[title]',
                'blockquote',
                'br',
                'code',
                'div[style]',
                'em',
                'hr',
                'img[src|alt|title|width|height|style]',
                'li[style]',
                'ol[style]',
                'p[style]',
                'pre',
                'span[style]',
                'strong',
                'table[style|border|cellpadding|cellspacing]',
                'tbody',
                'td[style|colspan|rowspan]',
                'tfoot',
                'th[style|colspan|rowspan]',
                'thead',
                'tr[style]',
                'u',
                'ul[style]',
            ]));

            return (new \HTMLPurifier($config))->purify($html);
        }

        $clean = preg_replace('@<(script|style|iframe|object|embed|form)\b[^>]*>.*?</\1>@is', '', $html) ?? '';
        $clean = preg_replace('/\s+on[a-zA-Z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/u', '', $clean) ?? '';
        $clean = preg_replace('/\s+(href|src)\s*=\s*("|\')?\s*javascript:[^"\'\s>]*/iu', '', $clean) ?? '';

        return $clean;
    }
}
