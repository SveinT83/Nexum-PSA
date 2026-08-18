<?php

namespace App\Modules\Email\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Email\Actions\UpdateEmailConversationClassification;
use App\Modules\Email\Models\EmailConversation;
use App\Modules\Email\Models\EmailConversationClassification;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Services\MailboxAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Email',
    description: 'API endpoints for inbox messages and email operations.'
)]
class ConversationClassificationController extends Controller
{
    #[OA\Get(
        path: '/api/v1/email/mailbox/conversations/{conversation}/classification',
        operationId: 'getEmailConversationClassification',
        summary: 'Read one account-scoped Mail conversation classification',
        security: [['bearerAuth' => []]],
        tags: ['Email'],
        parameters: [
            new OA\Parameter(name: 'conversation', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Conversation classification or null'),
            new OA\Response(response: 404, description: 'Conversation not found or inaccessible'),
        ]
    )]
    public function show(
        Request $request,
        EmailConversation $conversation,
        MailboxAccess $mailboxAccess,
    ): JsonResponse {
        $this->authorizeView($request, $conversation, $mailboxAccess);
        $conversation->load('classification.category', 'classification.tags');

        return response()->json([
            'data' => $this->serialize($conversation->classification),
        ]);
    }

    #[OA\Put(
        path: '/api/v1/email/mailbox/conversations/{conversation}/classification',
        operationId: 'updateEmailConversationClassification',
        summary: 'Update one account-scoped Mail conversation classification',
        security: [['bearerAuth' => []]],
        tags: ['Email'],
        parameters: [
            new OA\Parameter(name: 'conversation', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Updated conversation classification'),
            new OA\Response(response: 403, description: 'Mailbox Organize access required'),
            new OA\Response(response: 404, description: 'Conversation not found or inaccessible'),
            new OA\Response(response: 422, description: 'Invalid category, tags, or conversation placement'),
        ]
    )]
    public function update(
        Request $request,
        EmailConversation $conversation,
        MailboxAccess $mailboxAccess,
        UpdateEmailConversationClassification $updateClassification,
    ): JsonResponse {
        $this->authorizeView($request, $conversation, $mailboxAccess);
        abort_unless(
            $mailboxAccess->canAccessAccount($request->user(), $conversation->account, MailboxAccess::ORGANIZE),
            403,
        );

        $data = $request->validate([
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'tags' => ['sometimes', 'array', 'max:15'],
            'tags.*' => ['required', 'string', 'max:255'],
        ]);

        $placement = $this->activePlacement($conversation);
        abort_if(! $placement, 422, 'The conversation does not have an active mailbox placement.');

        $classification = $updateClassification->handle(
            $placement,
            $request->user(),
            isset($data['category_id']) ? (int) $data['category_id'] : null,
            array_values($data['tags'] ?? []),
        );

        return response()->json([
            'data' => $this->serialize($classification),
        ]);
    }

    #[OA\Delete(
        path: '/api/v1/email/mailbox/conversations/{conversation}/classification',
        operationId: 'clearEmailConversationClassification',
        summary: 'Clear one account-scoped Mail conversation classification',
        security: [['bearerAuth' => []]],
        tags: ['Email'],
        parameters: [
            new OA\Parameter(name: 'conversation', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Cleared conversation classification'),
            new OA\Response(response: 403, description: 'Mailbox Organize access required'),
            new OA\Response(response: 404, description: 'Conversation not found or inaccessible'),
        ]
    )]
    public function destroy(
        Request $request,
        EmailConversation $conversation,
        MailboxAccess $mailboxAccess,
        UpdateEmailConversationClassification $updateClassification,
    ): JsonResponse {
        $this->authorizeView($request, $conversation, $mailboxAccess);
        abort_unless(
            $mailboxAccess->canAccessAccount($request->user(), $conversation->account, MailboxAccess::ORGANIZE),
            403,
        );

        $placement = $this->activePlacement($conversation);
        abort_if(! $placement, 422, 'The conversation does not have an active mailbox placement.');

        $classification = $updateClassification->handle($placement, $request->user(), null, []);

        return response()->json([
            'data' => $this->serialize($classification),
        ]);
    }

    private function authorizeView(
        Request $request,
        EmailConversation $conversation,
        MailboxAccess $mailboxAccess,
    ): void {
        $conversation->loadMissing('account');

        abort_if(
            ! $conversation->account
            || ! $mailboxAccess->canAccessAccount($request->user(), $conversation->account, MailboxAccess::VIEW),
            404,
        );
    }

    private function activePlacement(EmailConversation $conversation): ?EmailMailboxPlacement
    {
        return $conversation->placements()
            ->with(['account', 'message', 'conversation'])
            ->where('local_state', EmailMailboxPlacement::LOCAL_ACTIVE)
            ->latest('id')
            ->first();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function serialize(?EmailConversationClassification $classification): ?array
    {
        if (! $classification) {
            return null;
        }

        $classification->loadMissing('category', 'tags');

        return [
            'id' => (int) $classification->id,
            'account_id' => (int) $classification->account_id,
            'email_conversation_id' => (int) $classification->email_conversation_id,
            'category' => $classification->category ? [
                'id' => (int) $classification->category->id,
                'name' => $classification->category->name,
            ] : null,
            'tags' => $classification->tags
                ->map(fn ($tag): array => ['id' => (int) $tag->id, 'name' => $tag->name])
                ->values()
                ->all(),
            'assigned_by' => $classification->assigned_by ? (int) $classification->assigned_by : null,
            'assigned_at' => $classification->assigned_at?->toIso8601String(),
            'source' => $classification->source,
        ];
    }
}
