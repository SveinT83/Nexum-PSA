<?php

namespace App\Modules\Storage\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

class SupplierOrderDocumentNormalizer
{
    private const MAX_BLOCKS = 2000;

    private const MAX_BLOCK_LENGTH = 4000;

    private const MAX_SEARCH_LENGTH = 250000;

    private const MAX_TABLES = 20;

    private const MAX_TABLE_ROWS = 500;

    private const MAX_TABLE_COLUMNS = 50;

    private const MAX_BODY_TEXT_LENGTH = 250000;

    private const MAX_HTML_LENGTH = 500000;

    /**
     * Convert a safe source snapshot into bounded, addressable text and table evidence.
     *
     * @param  array<string, mixed>  $sourceSnapshot
     */
    public function normalize(array $sourceSnapshot): SupplierOrderNormalizedDocument
    {
        $blocks = [];
        $subject = $this->cleanText(
            mb_substr((string) ($sourceSnapshot['subject'] ?? ''), 0, 1000),
            false,
        );
        if ($subject !== '') {
            $this->appendBlock($blocks, 'subject', $subject, 'subject');
        }

        $bodyText = $this->cleanText(
            mb_substr((string) ($sourceSnapshot['body_text'] ?? ''), 0, self::MAX_BODY_TEXT_LENGTH),
            true,
        );
        foreach ($this->lines($bodyText) as $line) {
            $this->appendBlock($blocks, 'text', $line, 'body_text');
        }

        [$htmlLines, $tables] = $this->normalizeHtml(
            mb_substr((string) ($sourceSnapshot['body_html'] ?? ''), 0, self::MAX_HTML_LENGTH),
        );
        if ($bodyText === '') {
            foreach ($htmlLines as $line) {
                $this->appendBlock($blocks, 'text', $line, 'body_html');
            }
        }

        $searchParts = array_column($blocks, 'text');
        if ($searchParts === [] && $tables !== []) {
            foreach ($tables as $table) {
                $searchParts[] = implode("\n", $table['headers']);
                foreach ($table['rows'] as $row) {
                    $searchParts[] = implode("\n", array_values($row['cells']));
                }
            }
        }

        return new SupplierOrderNormalizedDocument(
            blocks: $blocks,
            tables: $tables,
            searchText: mb_substr(implode("\n", $searchParts), 0, self::MAX_SEARCH_LENGTH),
            sourceFacts: $this->sourceFacts($sourceSnapshot),
        );
    }

    /**
     * @param  list<array{id: string, type: string, text: string, source: string}>  $blocks
     */
    private function appendBlock(array &$blocks, string $type, string $text, string $source): void
    {
        if (count($blocks) >= self::MAX_BLOCKS) {
            return;
        }

        $text = mb_substr(trim($text), 0, self::MAX_BLOCK_LENGTH);
        if ($text === '') {
            return;
        }

        $blocks[] = [
            'id' => 'b'.str_pad((string) (count($blocks) + 1), 4, '0', STR_PAD_LEFT),
            'type' => $type,
            'text' => $text,
            'source' => $source,
        ];
    }

    /** @return list<string> */
    private function lines(string $text): array
    {
        if ($text === '') {
            return [];
        }

        return collect(explode("\n", $text))
            ->map(fn (string $line): string => trim(preg_replace('/[ \t]+/u', ' ', $line) ?? ''))
            ->filter()
            ->take(self::MAX_BLOCKS)
            ->values()
            ->all();
    }

    /**
     * @return array{
     *     0: list<string>,
     *     1: list<array{id: string, headers: list<string>, rows: list<array{id: string, cells: array<string, string>}>}>
     * }
     */
    private function normalizeHtml(string $html): array
    {
        if (trim($html) === '') {
            return [[], []];
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML(
            '<?xml encoding="UTF-8"><div>'.$html.'</div>',
            LIBXML_NONET | LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $loaded) {
            return [[], []];
        }

        $xpath = new DOMXPath($document);
        foreach ($xpath->query('//script|//style|//link|//meta|//iframe|//object|//embed|//form|//input|//button|//svg') ?: [] as $node) {
            $node->parentNode?->removeChild($node);
        }
        foreach ($xpath->query('//*') ?: [] as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            foreach (iterator_to_array($node->attributes ?? []) as $attribute) {
                $node->removeAttribute($attribute->name);
            }
        }

        $tables = [];
        foreach ($xpath->query('//table') ?: [] as $tableNode) {
            if (count($tables) >= self::MAX_TABLES || ! $tableNode instanceof DOMElement) {
                break;
            }

            $table = $this->normalizeTable($xpath, $tableNode, count($tables) + 1);
            if ($table !== null) {
                $tables[] = $table;
            }
            $tableNode->parentNode?->removeChild($tableNode);
        }

        foreach ($xpath->query('//br|//p|//div|//li|//hr|//tr') ?: [] as $node) {
            if ($node instanceof DOMElement) {
                $node->appendChild($document->createTextNode("\n"));
            }
        }
        $visibleText = $document->documentElement instanceof DOMNode
            ? (string) $document->documentElement->textContent
            : (string) $document->textContent;

        return [$this->lines($this->cleanText($visibleText, true)), $tables];
    }

    /**
     * @return array{id: string, headers: list<string>, rows: list<array{id: string, cells: array<string, string>}>}|null
     */
    private function normalizeTable(DOMXPath $xpath, DOMElement $tableNode, int $tableNumber): ?array
    {
        $rawRows = [];
        foreach ($xpath->query('.//tr', $tableNode) ?: [] as $rowNode) {
            if (count($rawRows) >= self::MAX_TABLE_ROWS + 1 || ! $rowNode instanceof DOMElement) {
                break;
            }

            $cells = [];
            foreach ($xpath->query('./th|./td', $rowNode) ?: [] as $cellNode) {
                if (count($cells) >= self::MAX_TABLE_COLUMNS || ! $cellNode instanceof DOMElement) {
                    break;
                }
                $cells[] = mb_substr($this->cleanText((string) $cellNode->textContent, false), 0, 2000);
            }
            if (collect($cells)->contains(fn (string $cell): bool => $cell !== '')) {
                $rawRows[] = $cells;
            }
        }
        if (count($rawRows) < 2) {
            return null;
        }

        $headers = $this->uniqueHeaders(array_shift($rawRows));
        if ($headers === []) {
            return null;
        }

        $tableId = 't'.str_pad((string) $tableNumber, 4, '0', STR_PAD_LEFT);
        $rows = [];
        foreach ($rawRows as $rowNumber => $rawRow) {
            $cells = [];
            foreach ($headers as $index => $header) {
                $cells[$header] = $rawRow[$index] ?? '';
            }
            if (collect($cells)->contains(fn (string $cell): bool => $cell !== '')) {
                $rows[] = [
                    'id' => $tableId.'.r'.str_pad((string) ($rowNumber + 1), 4, '0', STR_PAD_LEFT),
                    'cells' => $cells,
                ];
            }
        }

        return $rows === [] ? null : [
            'id' => $tableId,
            'headers' => $headers,
            'rows' => $rows,
        ];
    }

    /**
     * @param  list<string>  $rawHeaders
     * @return list<string>
     */
    private function uniqueHeaders(array $rawHeaders): array
    {
        $headers = [];
        $counts = [];
        foreach ($rawHeaders as $index => $rawHeader) {
            $base = trim($rawHeader) !== '' ? trim($rawHeader) : 'column_'.($index + 1);
            $key = mb_strtolower($base);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
            $headers[] = $counts[$key] === 1 ? $base : $base.' '.$counts[$key];
        }

        return $headers;
    }

    /** @return array<string, mixed> */
    private function sourceFacts(array $snapshot): array
    {
        $fromEmail = mb_strtolower(trim((string) data_get($snapshot, 'from.email', '')));
        $recipients = collect([
            ...(array) ($snapshot['to'] ?? []),
            ...(array) ($snapshot['cc'] ?? []),
        ])->map(function (mixed $address): string {
            if (is_string($address)) {
                return mb_strtolower(trim($address));
            }

            return mb_strtolower(trim((string) data_get($address, 'email', '')));
        })->filter()->unique()->take(200)->values()->all();
        $trustedAuth = is_array($snapshot['trusted_auth'] ?? null) ? $snapshot['trusted_auth'] : [];

        return [
            'source' => $snapshot['source'] ?? null,
            'account_id' => is_numeric($snapshot['account_id'] ?? null)
                ? (int) $snapshot['account_id']
                : null,
            'mailbox' => mb_strtolower(trim((string) ($snapshot['mailbox'] ?? ''))),
            'subject' => $this->cleanText((string) ($snapshot['subject'] ?? ''), false),
            'from_email' => $fromEmail,
            'from_domain' => str_contains($fromEmail, '@') ? str($fromEmail)->afterLast('@')->toString() : '',
            'recipients' => $recipients,
            'received_at' => $snapshot['received_at'] ?? null,
            'trusted_auth' => [
                'authentication_passed' => (bool) ($trustedAuth['authentication_passed'] ?? false),
                'authenticated_supplier_domain' => mb_strtolower(trim((string) ($trustedAuth['authenticated_supplier_domain'] ?? ''))),
                'aligned' => (bool) ($trustedAuth['aligned'] ?? false),
            ],
        ];
    }

    private function cleanText(string $text, bool $preserveLines): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\r\n", "\r", "\u{00A0}", "\u{202F}"], ["\n", "\n", ' ', ' '], $text);
        $text = preg_replace('~(?:https?://|www\.)[^\s<>()]+~iu', '[URL]', $text) ?? '';
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';

        if (! $preserveLines) {
            $text = preg_replace('/\s+/u', ' ', $text) ?? '';
        }

        return trim($text);
    }
}
