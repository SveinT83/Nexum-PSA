<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Integration\Models\AiChat;
use App\Modules\Integration\Services\AiAgentResolver;
use App\Modules\Integration\Services\AiChatResponder;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketMessage;
use App\Modules\Ticket\Queries\TicketTimeRateOptions;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

/** Browser and API use the same configured-agent suggestion; no time or billing is approved. */
#[OA\Schema(schema: 'TicketInvoiceTextSuggestion', type: 'object', required: ['text'], properties: [new OA\Property(property: 'text', type: 'string', maxLength: 2000)])]
class DraftTicketTimeInvoiceText
{
    public function __construct(private AiAgentResolver $agentResolver, private AiChatResponder $responder, private TicketTimeRateOptions $timeRateOptions) {}

    public function handle(Ticket $ticket, User $user, array $data): array
    {
        $agentResolver = $this->agentResolver;
        $responder = $this->responder;
        $timeRateOptions = $this->timeRateOptions;
        $agent = $user ? $agentResolver->defaultAgent($user, 'tickets') : null;

        if (! $agent) {
            throw ValidationException::withMessages(['agent' => 'No active AI agent is available for tickets.']);
        }

        $rateOption = filled($data['rate_key'] ?? null)
            ? $timeRateOptions->findForTicket($ticket, $data['rate_key'])
            : null;

        $ticket->loadMissing(['status', 'client', 'contact', 'messages.author', 'timeEntries.user']);
        $prompt = $this->timeInvoiceDraftPrompt($ticket, $data['existing_text'] ?? null, $rateOption);
        $chat = AiChat::create([
            'user_id' => $user->id,
            'ai_agent_id' => $agent->id,
            'title' => 'Ticket time invoice draft '.$ticket->ticket_key,
            'status' => 'closed',
            'metadata' => [
                'source' => 'ticket_time_invoice_draft',
                'ticket_id' => $ticket->id,
            ],
            'last_message_at' => now(),
        ]);

        $chat->messages()->create([
            'user_id' => $user->id,
            'role' => 'user',
            'body' => $prompt,
        ]);
        $pending = $chat->messages()->create([
            'role' => 'assistant',
            'body' => 'AI is thinking...',
            'metadata' => ['status' => 'pending'],
        ]);

        $responder->respond($chat, $pending->id);
        $reply = trim((string) $pending->refresh()->body);

        if (Str::startsWith($reply, 'AI provider error:')) {
            throw ValidationException::withMessages(['agent' => 'The configured AI provider could not produce a suggestion.']);
        }

        return ['text' => Str::limit($reply, 2000, '')];
    }

    private function timeInvoiceDraftPrompt(Ticket $ticket, ?string $existingText = null, ?array $rateOption = null): string
    {
        $messages = $ticket->messages
            ->sortByDesc('created_at')
            ->take(10)
            ->reverse()
            ->map(function (TicketMessage $message) {
                $author = $message->author_type === 'contact'
                    ? 'Customer'
                    : ($message->author?->name ?? 'Technician');

                return '['.$message->type.' / '.$message->visibility.'] '.$author.': '.Str::limit(trim((string) $message->body), 1200);
            })
            ->implode("\n");
        $previousTimeTexts = $ticket->timeEntries
            ->sortByDesc('created_at')
            ->take(8)
            ->map(function ($entry) {
                return '- '.$entry->minutes.' min / '.($entry->rate_name ?: 'unknown rate').': '.Str::limit(trim((string) ($entry->invoice_text ?: $entry->note)), 500);
            })
            ->filter(fn (string $line) => filled(trim($line, "- \t\n\r\0\x0B")))
            ->implode("\n");

        $rateText = strtolower(implode(' ', array_filter([
            $rateOption['label'] ?? null,
            $rateOption['rate_name'] ?? null,
            $rateOption['rate_code'] ?? null,
            $rateOption['rate_type'] ?? null,
        ])));
        $isTravelRate = Str::contains($rateText, ['driving', 'drive', 'travel', 'kjøring', 'kjoring', 'reise']);

        return trim(implode("\n\n", array_filter([
            'Write one short invoice/work description for registered ticket time.',
            'Return only the concrete work performed. No greeting, no markdown, no bullet list.',
            'Do not include ticket numbers, customer names, "billing", "invoice", or phrases like "registered time".',
            'Prefer a compact noun phrase such as "oppdatering av skjermkortdriver og Steam-konfigurasjon".',
            'Do not copy a previous time entry unless the selected rate and current draft clearly describe the same work.',
            'If an existing draft is provided, improve or normalize that draft instead of ignoring it.',
            $isTravelRate ? 'CRITICAL: The selected rate is driving/travel. Return a driving/travel description only. Do not mention technical work such as drivers, Steam, troubleshooting, repair, configuration, or updates.' : null,
            $isTravelRate ? 'Good examples for this selected rate: "kjøring til og fra kunde", "reise i forbindelse med supportoppdrag", "kjøring til kunde".' : null,
            ! $isTravelRate ? 'If the selected rate is technical labor, describe the technical work performed.' : null,
            'Use the same language as the technician work when obvious.',
            $rateOption ? 'Selected time rate: '.$rateOption['label'].' (type: '.($rateOption['rate_type'] ?? 'unknown').', unit: '.($rateOption['rate_unit'] ?? 'unknown').')' : null,
            'Ticket: '.$ticket->ticket_key,
            'Subject: '.$ticket->subject,
            'Client: '.($ticket->client?->name ?? 'Unknown'),
            'Contact: '.($ticket->contact?->name ?? 'Unknown'),
            'Description: '.Str::limit(trim((string) $ticket->description), 1500),
            filled($existingText) ? 'Existing draft: '.$existingText : null,
            filled($previousTimeTexts) ? "Previous registered time texts on this ticket:\n".$previousTimeTexts : null,
            filled($messages) ? "Recent replies and notes:\n".$messages : null,
        ])));
    }
}
