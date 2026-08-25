<?php

namespace App\Modules\Email\Services;

use DOMDocument;
use DOMXPath;
use InvalidArgumentException;

class OutboundEmailHtmlPolicy
{
    public const BODY_SLOT = '{{ email_body }}';

    public const BODY_SLOT_PATTERN = '/{{\s*email_body\s*}}/i';

    /**
     * Body HTML is an email fragment. Full documents belong in Layout HTML.
     */
    public function assertBody(?string $html): void
    {
        $html = trim((string) $html);

        if ($html === '') {
            return;
        }

        if (preg_match('/<!doctype\b|<\s*(html|head|body)\b/i', $html)) {
            throw new InvalidArgumentException('Body HTML must be a fragment. Put full-document HTML in Layout HTML.');
        }

        if (preg_match(self::BODY_SLOT_PATTERN, $html)) {
            throw new InvalidArgumentException('The email_body slot belongs in Layout HTML, not Body HTML.');
        }

        $this->assertNoActiveContent($html);
    }

    /**
     * A custom layout is a complete email document with one deterministic body slot.
     */
    public function assertLayout(?string $html): void
    {
        $html = trim((string) $html);

        if ($html === '') {
            throw new InvalidArgumentException('Custom Layout HTML is required.');
        }

        if (! preg_match('/<\s*html\b/i', $html)
            || ! preg_match('/<\s*body\b/i', $html)
            || ! preg_match('/<\/\s*body\s*>/i', $html)
            || ! preg_match('/<\/\s*html\s*>/i', $html)) {
            throw new InvalidArgumentException('Custom Layout HTML must contain complete html and body elements.');
        }

        preg_match_all(self::BODY_SLOT_PATTERN, $html, $matches);

        if (count($matches[0]) !== 1) {
            throw new InvalidArgumentException('Custom Layout HTML must contain exactly one {{ email_body }} slot.');
        }

        $this->assertBodySlotIsRenderableText($html);
        $this->assertNoActiveContent($html);
    }

    /**
     * The reserved slot must be a body text node, never an attribute, comment, head, or CSS value.
     */
    private function assertBodySlotIsRenderableText(string $html): void
    {
        $previousErrors = libxml_use_internal_errors(true);
        $document = new DOMDocument('1.0', 'UTF-8');

        try {
            $loaded = $document->loadHTML(
                $html,
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }

        if (! $loaded) {
            throw new InvalidArgumentException('Custom Layout HTML could not be parsed.');
        }

        $textNodes = (new DOMXPath($document))->query(
            '//body//text()[not(ancestor::style) and not(ancestor::template) and not(ancestor::noscript)]',
        );
        $textSlotCount = 0;

        if ($textNodes !== false) {
            foreach ($textNodes as $textNode) {
                preg_match_all(self::BODY_SLOT_PATTERN, $textNode->nodeValue ?? '', $textMatches);
                $textSlotCount += count($textMatches[0]);
            }
        }

        if ($textSlotCount !== 1) {
            throw new InvalidArgumentException('The {{ email_body }} slot must be renderable content inside the body element.');
        }
    }

    /**
     * Outbound templates are admin-authored, but they still render in a browser preview and many mail clients.
     * Reject executable or navigation-capable markup instead of silently changing authored HTML.
     */
    private function assertNoActiveContent(string $html): void
    {
        $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if (preg_match('/<\s*(script|iframe|object|embed|form|input|button|select|textarea|base)\b/i', $decoded)) {
            throw new InvalidArgumentException('Email HTML cannot contain scripts, embedded frames, forms, or interactive controls.');
        }

        if (preg_match('/<\s*meta\b[^>]*http-equiv\s*=\s*["\']?\s*refresh\b/i', $decoded)) {
            throw new InvalidArgumentException('Email HTML cannot contain meta refresh navigation.');
        }

        if (preg_match('/\son[a-z0-9_-]+\s*=/i', $decoded)) {
            throw new InvalidArgumentException('Email HTML cannot contain event-handler attributes.');
        }

        if (preg_match('/\s(?:href|src|background|action|formaction|poster)\s*=\s*(["\'])?\s*(?:javascript|vbscript|data)\s*:/i', $decoded)) {
            throw new InvalidArgumentException('Email HTML contains an unsafe URL scheme.');
        }

        if (preg_match('/(?:expression\s*\(|@import\b|behavior\s*:|-moz-binding\s*:|url\s*\(\s*["\']?\s*(?:javascript|vbscript|data)\s*:)/i', $decoded)) {
            throw new InvalidArgumentException('Email HTML contains unsafe CSS.');
        }
    }
}
