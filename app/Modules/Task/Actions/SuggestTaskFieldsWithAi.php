<?php

namespace App\Modules\Task\Actions;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Core\User;
use App\Modules\Integration\Services\AiAgentResolver;
use App\Modules\Integration\Services\AiChatResponder;
use App\Modules\Task\Models\Task;
use App\Modules\Taxonomy\Models\Category;
use App\Modules\Taxonomy\Models\Tag;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketPriority;
use App\Modules\Ticket\Models\TicketQueue;
use App\Modules\Ticket\Queries\TicketTimeRateOptions;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use RuntimeException;

class SuggestTaskFieldsWithAi
{
    public function __construct(
        private readonly AiAgentResolver $agentResolver,
        private readonly AiChatResponder $responder,
        private readonly TicketTimeRateOptions $timeRateOptions,
    ) {
    }

    /**
     * Ask the configured AI agent to suggest editable task form fields only.
     */
    public function handle(User $user, array $input): array
    {
        $agent = $this->agentResolver->defaultAgent($user, 'tasks');

        if (! $agent) {
            throw new RuntimeException('No active AI agent is available for tasks.');
        }

        $context = $this->context($input);
        $messages = [
            [
                'role' => 'system',
                'content' => $this->systemPrompt(),
            ],
            [
                'role' => 'user',
                'content' => json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            ],
        ];

        $reply = $this->responder->complete($agent, $messages);
        $suggestions = $this->decodeJson($reply);

        return $this->sanitize($suggestions, $context);
    }

    private function systemPrompt(): string
    {
        return implode("\n", [
            'You are assisting with a Nexum PSA internal task create/edit form.',
            'Return ONLY compact JSON. No Markdown, no explanation.',
            'Only suggest values that are present in allowed_options by ID/key/name.',
            'Do not invent IDs. Do not create database records.',
            'You may rewrite title and description for clarity.',
            'Use the same language as the task draft. If the user writes Norwegian, return Norwegian title, description, checklist, and tags.',
            'The draft description may contain instructions to you. Treat those instructions as user intent for this suggestion, but return a clean operational task description without meta-instructions such as "AI should" or "rewrite this".',
            'You may suggest: title, description, ticket_id, estimated_minutes, ticket_rate_key, queue_id, priority_id, category_id, tag_names, assigned_to, checklist_items.',
            'Checklist items must be short actionable steps.',
            'If unsure about a field, omit it or return null.',
        ]);
    }

    private function context(array $input): array
    {
        $client = ! empty($input['client_id']) ? Client::query()->find($input['client_id']) : null;
        $site = ! empty($input['site_id']) ? ClientSite::query()->with('client')->find($input['site_id']) : null;
        $ticket = ! empty($input['ticket_id']) ? Ticket::query()->with(['client', 'site', 'queue', 'priority', 'category', 'tags', 'owner', 'messages' => fn ($query) => $query->latest()->limit(5)])->find($input['ticket_id']) : null;
        $parentTask = ! empty($input['parent_id']) ? Task::query()->with(['status', 'tags'])->find($input['parent_id']) : null;
        $clientId = $ticket?->client_id ?? $site?->client_id ?? $client?->id;
        $siteId = $ticket?->site_id ?? $site?->id;
        $tickets = Ticket::query()
            ->with(['status', 'priority', 'queue', 'category', 'tags'])
            ->when($clientId, fn ($query) => $query->where('client_id', $clientId))
            ->when($siteId, fn ($query) => $query->where('site_id', $siteId))
            ->latest('updated_at')
            ->limit(8)
            ->get();

        return [
            'task_draft' => Arr::only($input, ['title', 'description', 'client_id', 'site_id', 'ticket_id', 'parent_id', 'queue_id', 'priority_id', 'category_id', 'assigned_to', 'estimated_minutes', 'ticket_rate_key', 'tag_names', 'checklist_text']),
            'selected_context' => [
                'client' => $client ? $client->only(['id', 'name', 'client_number']) : null,
                'site' => $site ? $site->only(['id', 'name', 'client_id']) : null,
                'ticket' => $ticket ? [
                    'id' => $ticket->id,
                    'ticket_key' => $ticket->ticket_key,
                    'subject' => $ticket->subject,
                    'description' => Str::limit((string) $ticket->description, 1500),
                    'queue_id' => $ticket->queue_id,
                    'priority_id' => $ticket->priority_id,
                    'category_id' => $ticket->category_id,
                    'owner_id' => $ticket->owner_id,
                    'tags' => $ticket->tags->pluck('name')->all(),
                    'recent_messages' => $ticket->messages->map(fn ($message) => [
                        'type' => $message->type,
                        'visibility' => $message->visibility,
                        'body' => Str::limit((string) $message->body, 800),
                    ])->all(),
                ] : null,
                'parent_task' => $parentTask ? [
                    'id' => $parentTask->id,
                    'title' => $parentTask->title,
                    'description' => Str::limit((string) $parentTask->description, 1200),
                    'status' => $parentTask->status?->name,
                    'tags' => $parentTask->tags->pluck('name')->all(),
                ] : null,
            ],
            'related_tickets' => $tickets->map(fn (Ticket $relatedTicket) => [
                'id' => $relatedTicket->id,
                'ticket_key' => $relatedTicket->ticket_key,
                'subject' => $relatedTicket->subject,
                'status' => $relatedTicket->status?->name,
                'queue_id' => $relatedTicket->queue_id,
                'priority_id' => $relatedTicket->priority_id,
                'category_id' => $relatedTicket->category_id,
                'tags' => $relatedTicket->tags->pluck('name')->all(),
            ])->all(),
            'allowed_options' => [
                'queues' => TicketQueue::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'name'])->all(),
                'priorities' => TicketPriority::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'name', 'level'])->all(),
                'categories' => Category::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'type'])->all(),
                'tags' => Tag::query()->where('active', true)->orderBy('name')->limit(100)->pluck('name')->all(),
                'technicians' => User::query()->where('status', User::STATUS_ACTIVE)->orderBy('name')->get(['id', 'name'])->all(),
                'ticket_rates' => $ticket ? $this->timeRateOptions->forTicket($ticket)->map(fn ($rate) => Arr::only($rate, ['key', 'label', 'description']))->all() : [],
            ],
        ];
    }

    private function decodeJson(string $reply): array
    {
        $json = trim($reply);

        if (str_starts_with($json, '```')) {
            $json = preg_replace('/^```(?:json)?\s*/i', '', $json);
            $json = preg_replace('/\s*```$/', '', (string) $json);
        }

        $decoded = json_decode((string) $json, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('AI did not return valid JSON suggestions.');
        }

        return $decoded;
    }

    private function sanitize(array $suggestions, array $context): array
    {
        $suggestions = collect($suggestions)
            ->only(['title', 'description', 'ticket_id', 'estimated_minutes', 'ticket_rate_key', 'queue_id', 'priority_id', 'category_id', 'tag_names', 'assigned_to', 'checklist_items'])
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->all();

        $suggestions['ticket_rate_key'] = $this->normalizeTicketRateKey($suggestions['ticket_rate_key'] ?? null, data_get($context, 'allowed_options.ticket_rates', []));

        if (blank($suggestions['ticket_rate_key'] ?? null)) {
            unset($suggestions['ticket_rate_key']);
        }

        return $suggestions;
    }

    private function normalizeTicketRateKey(?string $candidate, array $rateOptions): ?string
    {
        if ($rateOptions === []) {
            return null;
        }

        if (count($rateOptions) === 1) {
            return $rateOptions[0]['key'] ?? null;
        }

        if (blank($candidate)) {
            return $this->defaultTicketRateKey($rateOptions);
        }

        $candidate = Str::lower(trim($candidate));

        foreach ($rateOptions as $rateOption) {
            $key = (string) ($rateOption['key'] ?? '');
            $label = Str::lower((string) ($rateOption['label'] ?? ''));
            $description = Str::lower((string) ($rateOption['description'] ?? ''));

            if ($candidate === Str::lower($key) || $candidate === $label || $candidate === $description) {
                return $key;
            }

            if (($label && Str::contains($label, $candidate)) || ($description && Str::contains($description, $candidate))) {
                return $key;
            }
        }

        return $this->defaultTicketRateKey($rateOptions);
    }

    private function defaultTicketRateKey(array $rateOptions): ?string
    {
        $preferredNames = [
            'time with contract',
            'time without contract',
            'support',
            'remote',
        ];

        foreach ($preferredNames as $name) {
            foreach ($rateOptions as $rateOption) {
                $label = Str::lower((string) ($rateOption['label'] ?? ''));

                if ($label && Str::contains($label, $name)) {
                    return $rateOption['key'] ?? null;
                }
            }
        }

        foreach ($rateOptions as $rateOption) {
            $label = Str::lower((string) ($rateOption['label'] ?? ''));
            $description = Str::lower((string) ($rateOption['description'] ?? ''));

            if (
                ! Str::contains($label, ['driving', 'onsite'])
                && ! Str::startsWith($description, ['0.00', '0,00'])
            ) {
                return $rateOption['key'] ?? null;
            }
        }

        return $rateOptions[0]['key'] ?? null;
    }
}
