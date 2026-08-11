<?php

namespace App\Modules\Storage\Support;

final class SupplierOrderNormalizedDocument
{
    /**
     * @param  list<array{id: string, type: string, text: string, source: string}>  $blocks
     * @param  list<array{id: string, headers: list<string>, rows: list<array{id: string, cells: array<string, string>}>}>  $tables
     * @param  array<string, mixed>  $sourceFacts
     */
    public function __construct(
        public readonly array $blocks,
        public readonly array $tables,
        public readonly string $searchText,
        public readonly array $sourceFacts,
    ) {}

    /** @return array<string, mixed>|null */
    public function anchorForQuote(string $quote): ?array
    {
        $quote = trim($quote);
        if ($quote === '') {
            return null;
        }

        foreach ($this->blocks as $block) {
            if (mb_stripos($block['text'], $quote) !== false) {
                return [
                    'block_id' => $block['id'],
                    'source' => $block['source'],
                    'quote' => mb_substr($quote, 0, 500),
                ];
            }
        }

        foreach ($this->tables as $table) {
            foreach ($table['rows'] as $row) {
                foreach ($row['cells'] as $column => $value) {
                    if (mb_stripos($value, $quote) !== false) {
                        return [
                            'block_id' => $table['id'],
                            'row_id' => $row['id'],
                            'column' => $column,
                            'source' => 'html_table',
                            'quote' => mb_substr($quote, 0, 500),
                        ];
                    }
                }
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'blocks' => $this->blocks,
            'tables' => $this->tables,
            'search_text' => $this->searchText,
            'source_facts' => $this->sourceFacts,
        ];
    }
}
