<?php

namespace App\Modules\Relationship\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Relationship\Actions\AuthenticateNexumWebhook;
use App\Modules\Relationship\Actions\ReceiveRelationshipTicket;
use App\Modules\Relationship\Actions\ReceiveRelationshipTicketMessage;
use App\Modules\Relationship\Actions\ReceiveRelationshipTicketStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RelationshipTicketWebhookController extends Controller
{
    public function store(Request $request, AuthenticateNexumWebhook $auth, ReceiveRelationshipTicket $receiver): JsonResponse
    {
        $relationship = $auth->handle($request);

        if (! $relationship) {
            return response()->json(['message' => 'Unauthenticated relationship payload.'], 401);
        }

        $data = $request->validate([
            'source_ticket_id' => ['required', 'string', 'max:255'],
            'source_ticket_key' => ['nullable', 'string', 'max:255'],
            'source_url' => ['nullable', 'url', 'max:1000'],
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'max:255'],
            'priority' => ['nullable', 'string', 'max:255'],
            'client' => ['nullable', 'array'],
            'occurred_at' => ['nullable', 'date'],
        ]);

        [$ticket, $link, $created] = $receiver->handle($relationship, $data);

        return response()->json([
            'data' => [
                'id' => $ticket->id,
                'remote_id' => $ticket->ticket_key,
                'ticket_key' => $ticket->ticket_key,
                'url' => route('tech.tickets.show', $ticket),
                'sync_link_id' => $link->id,
            ],
            'created' => $created,
        ], $created ? 201 : 200);
    }

    public function message(
        Request $request,
        string $remoteTicketId,
        AuthenticateNexumWebhook $auth,
        ReceiveRelationshipTicketMessage $receiver
    ): JsonResponse {
        $relationship = $auth->handle($request);

        if (! $relationship) {
            return response()->json(['message' => 'Unauthenticated relationship payload.'], 401);
        }

        $data = $request->validate([
            'source_message_id' => ['required', 'string', 'max:255'],
            'source_ticket_key' => ['nullable', 'string', 'max:255'],
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'author_name' => ['nullable', 'string', 'max:255'],
            'author_email' => ['nullable', 'email', 'max:255'],
            'occurred_at' => ['nullable', 'date'],
            'attachments' => ['nullable', 'array'],
            'attachments.*.filename' => ['required_with:attachments', 'string', 'max:255'],
            'attachments.*.content_type' => ['nullable', 'string', 'max:255'],
            'attachments.*.size_bytes' => ['nullable', 'integer', 'min:0'],
            'attachments.*.checksum_sha1' => ['nullable', 'string', 'max:80'],
            'attachments.*.content_base64' => ['required_with:attachments', 'string'],
        ]);

        [$message, $created] = $receiver->handle($relationship, $remoteTicketId, $data);

        return response()->json([
            'data' => [
                'id' => $message->id,
                'created' => $created,
            ],
        ], $created ? 201 : 200);
    }

    public function status(
        Request $request,
        string $remoteTicketId,
        AuthenticateNexumWebhook $auth,
        ReceiveRelationshipTicketStatus $receiver
    ): JsonResponse {
        $relationship = $auth->handle($request);

        if (! $relationship) {
            return response()->json(['message' => 'Unauthenticated relationship payload.'], 401);
        }

        $data = $request->validate([
            'status' => ['required', 'string', 'max:255'],
            'local_status' => ['nullable', 'array'],
            'occurred_at' => ['nullable', 'date'],
        ]);

        $ticket = $receiver->handle($relationship, $remoteTicketId, $data);

        return response()->json([
            'data' => [
                'id' => $ticket->id,
                'ticket_key' => $ticket->ticket_key,
                'status_id' => $ticket->status_id,
            ],
        ]);
    }
}
