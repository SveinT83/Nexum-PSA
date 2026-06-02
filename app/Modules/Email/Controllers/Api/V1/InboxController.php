<?php

namespace App\Modules\Email\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Settings\CommonSetting;
use App\Modules\Email\Actions\MarkEmailAsSpam;
use App\Modules\Email\Jobs\FetchImapAccount;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Resources\Api\V1\EmailMessageResource;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Email',
    description: 'API endpoints for inbox messages and email operations.'
)]
class InboxController extends Controller
{
    #[OA\Get(
        path: '/api/v1/email/inbox/messages',
        operationId: 'getInboxMessageList',
        description: 'Returns paginated unrouted inbox messages. Messages already linked to tickets are excluded.',
        summary: 'Get inbox messages',
        security: [['bearerAuth' => []]],
        tags: ['Email'],
        parameters: [
            new OA\Parameter(name: 'q', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'state', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'account_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'from_email', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
            new OA\Response(response: 403, description: 'Missing email.read scope'),
        ]
    )]
    public function messages(Request $request)
    {
        $query = EmailMessage::query()
            ->with(['account', 'tags'])
            ->whereNull('ticket_id')
            ->orderByDesc('received_at')
            ->orderByDesc('id');

        if ($request->filled('q')) {
            $term = trim((string) $request->input('q'));
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
            $query->where(function ($inner) use ($like): void {
                $inner->where('subject', 'like', $like)
                    ->orWhere('from_email', 'like', $like)
                    ->orWhere('from_name', 'like', $like)
                    ->orWhere('body_text', 'like', $like);
            });
        }

        foreach (['state', 'from_email'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->input($field));
            }
        }

        if ($request->filled('account_id')) {
            $query->where('account_id', $request->integer('account_id'));
        }

        return EmailMessageResource::collection($query->paginate($request->integer('per_page') ?: 25));
    }

    #[OA\Get(
        path: '/api/v1/email/inbox/messages/{message}',
        operationId: 'getInboxMessageById',
        summary: 'Get inbox message',
        security: [['bearerAuth' => []]],
        tags: ['Email'],
        parameters: [
            new OA\Parameter(name: 'message', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
            new OA\Response(response: 403, description: 'Missing email.read scope'),
            new OA\Response(response: 404, description: 'Inbox message not found'),
        ]
    )]
    public function show(EmailMessage $message)
    {
        abort_if($message->ticket_id !== null, 404);

        return new EmailMessageResource($this->loadMessage($message));
    }

    #[OA\Post(
        path: '/api/v1/email/inbox/messages/{message}/spam',
        operationId: 'markInboxMessageSpam',
        summary: 'Mark inbox message as spam',
        security: [['bearerAuth' => []]],
        tags: ['Email'],
        parameters: [
            new OA\Parameter(name: 'message', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Message marked as spam'),
            new OA\Response(response: 403, description: 'Missing email.update scope'),
            new OA\Response(response: 404, description: 'Inbox message not found'),
        ]
    )]
    public function markSpam(Request $request, EmailMessage $message, MarkEmailAsSpam $markEmailAsSpam)
    {
        abort_if($message->ticket_id !== null, 404);

        $rule = $markEmailAsSpam->handle($message, $request->user());

        return response()->json([
            'data' => [
                'message' => new EmailMessageResource($this->loadMessage($message->fresh())),
                'rule' => [
                    'id' => $rule->id,
                    'name' => $rule->name,
                    'is_active' => $rule->is_active,
                    'stop_processing' => $rule->stop_processing,
                ],
            ],
        ]);
    }

    #[OA\Post(
        path: '/api/v1/email/inbox/poll',
        operationId: 'queueInboxPoll',
        summary: 'Queue inbox polling',
        security: [['bearerAuth' => []]],
        tags: ['Email'],
        responses: [
            new OA\Response(response: 202, description: 'Inbox polling queued'),
            new OA\Response(response: 403, description: 'Missing email.update scope'),
        ]
    )]
    public function poll()
    {
        $settings = CommonSetting::where('type', 'emailhub')
            ->get()
            ->pluck('value', 'name')
            ->toArray();
        $batchSize = (int) ($settings['batch_size'] ?? 20);
        $accounts = EmailAccount::query()->where('is_active', true)->get();

        foreach ($accounts as $account) {
            FetchImapAccount::dispatch($account->id, $batchSize);
        }

        return response()->json([
            'data' => [
                'queued_accounts' => $accounts->count(),
                'batch_size' => $batchSize,
            ],
        ], 202);
    }

    private function loadMessage(EmailMessage $message): EmailMessage
    {
        return $message->load(['account', 'attachments', 'tags']);
    }
}
