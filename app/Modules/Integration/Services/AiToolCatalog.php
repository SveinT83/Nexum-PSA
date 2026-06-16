<?php

namespace App\Modules\Integration\Services;

class AiToolCatalog
{
    /**
     * Central policy catalog for AI tools. Tool keys are stored on agents, while
     * scope keys describe which backend permissions a future executor must enforce.
     */
    public function grouped(): array
    {
        return [
            'read' => [
                'knowledge.search' => [
                    'label' => 'Search Knowledge',
                    'description' => 'Find matching books and articles and pass them as read-only context.',
                    'requires_data_source' => 'knowledge',
                    'requires_scope' => 'knowledge.read',
                ],
                'records.read' => [
                    'label' => 'Read allowed records',
                    'description' => 'Read records from the data sources this agent is allowed to use.',
                    'requires_data_source' => null,
                    'requires_scope' => null,
                ],
                'context.summarize' => [
                    'label' => 'Summarize context',
                    'description' => 'Summarize available chat, ticket, or knowledge context without changing data.',
                    'requires_data_source' => null,
                    'requires_scope' => null,
                ],
                'draft.reply' => [
                    'label' => 'Draft replies',
                    'description' => 'Draft technician replies without sending or saving them as ticket actions.',
                    'requires_data_source' => 'active_tickets',
                    'requires_scope' => 'tickets.read',
                ],
            ],
            'write' => [
                'tickets.update' => [
                    'label' => 'Update tickets',
                    'description' => 'Change ticket fields, status, assignment, priority, or internal metadata.',
                    'requires_data_source' => 'active_tickets',
                    'requires_scope' => 'tickets.update',
                ],
                'tickets.reply' => [
                    'label' => 'Post ticket replies',
                    'description' => 'Create internal notes or customer-visible ticket replies.',
                    'requires_data_source' => 'active_tickets',
                    'requires_scope' => 'tickets.update',
                ],
                'knowledge.update' => [
                    'label' => 'Update Knowledge',
                    'description' => 'Create or update Knowledge books, chapters, or articles.',
                    'requires_data_source' => 'knowledge',
                    'requires_scope' => 'knowledge.update',
                ],
            ],
        ];
    }

    public function options(): array
    {
        return collect($this->grouped())
            ->flatMap(fn (array $tools) => collect($tools)->mapWithKeys(fn (array $tool, string $key) => [$key => $tool['label']]))
            ->all();
    }

    public function readOptions(): array
    {
        return collect($this->grouped()['read'])
            ->mapWithKeys(fn (array $tool, string $key) => [$key => $tool['label']])
            ->all();
    }

    public function writeOptions(): array
    {
        return collect($this->grouped()['write'])
            ->mapWithKeys(fn (array $tool, string $key) => [$key => $tool['label']])
            ->all();
    }

    public function writeKeys(): array
    {
        return array_keys($this->grouped()['write']);
    }

    public function normalize(array $tools, bool $canExecuteActions): array
    {
        $aliases = [
            'search' => 'knowledge.search',
            'read_records' => 'records.read',
            'summarize' => 'context.summarize',
            'draft_replies' => 'draft.reply',
        ];

        $validTools = array_keys($this->options());
        $writeTools = $this->writeKeys();

        return collect($tools)
            ->map(fn ($tool) => $aliases[$tool] ?? $tool)
            ->filter(fn ($tool) => in_array($tool, $validTools, true))
            ->when(! $canExecuteActions, fn ($items) => $items->reject(fn ($tool) => in_array($tool, $writeTools, true)))
            ->unique()
            ->values()
            ->all();
    }

    public function acceptedKeys(): array
    {
        return array_merge(array_keys($this->options()), [
            'search',
            'read_records',
            'summarize',
            'draft_replies',
        ]);
    }
}
