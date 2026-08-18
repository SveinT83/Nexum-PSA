<?php

namespace App\Modules\Email\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Email\Actions\CancelEmailRemoteOperation;
use App\Modules\Email\Actions\RetryEmailRemoteOperation;
use App\Modules\Email\Actions\UndoEmailRemoteOperation;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailRemoteOperation;
use App\Modules\Email\Resources\Api\V1\EmailRemoteOperationResource;
use App\Modules\Email\Services\EmailRemoteOperationUndoEligibility;
use App\Modules\Email\Services\MailboxAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Email', description: 'API endpoints for inbox messages and email operations.')]
class RemoteOperationsController extends Controller
{
    #[OA\Get(
        path: '/api/v1/email/mailbox/remote-operations',
        operationId: 'listEmailRemoteOperations',
        summary: 'List authorized mailbox provider operations',
        security: [['bearerAuth' => []]],
        tags: ['Email'],
        responses: [
            new OA\Response(response: 200, description: 'Account-scoped operation list'),
            new OA\Response(response: 404, description: 'Requested mailbox is outside the caller scope'),
        ],
    )]
    public function index(Request $request, MailboxAccess $mailboxAccess)
    {
        $validated = $request->validate([
            'account_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', Rule::in([
                EmailRemoteOperation::STATUS_PENDING,
                EmailRemoteOperation::STATUS_RUNNING,
                EmailRemoteOperation::STATUS_SUCCEEDED,
                EmailRemoteOperation::STATUS_FAILED,
                EmailRemoteOperation::STATUS_CANCELLED,
                EmailRemoteOperation::STATUS_SUPERSEDED,
            ])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $accountIds = $mailboxAccess->scopeAccounts(
            EmailAccount::query(),
            $request->user(),
            MailboxAccess::VIEW,
        )->pluck('id');

        if (isset($validated['account_id']) && ! $accountIds->contains((int) $validated['account_id'])) {
            abort(404);
        }

        $operations = EmailRemoteOperation::query()
            ->with(['account:id,address', 'attemptRecords', 'inverseOf:id', 'inverseOperation:id,inverse_of_email_remote_operation_id,status'])
            ->whereIn('account_id', $accountIds)
            ->when(isset($validated['account_id']), fn ($query) => $query->where('account_id', $validated['account_id']))
            ->when(isset($validated['status']), fn ($query) => $query->where('status', $validated['status']))
            ->latest('id')
            ->paginate((int) ($validated['per_page'] ?? 25));

        return EmailRemoteOperationResource::collection($operations);
    }

    #[OA\Get(
        path: '/api/v1/email/mailbox/remote-operations/{operation}',
        operationId: 'showEmailRemoteOperation',
        summary: 'Show one authorized mailbox provider operation and its attempts',
        security: [['bearerAuth' => []]],
        tags: ['Email'],
        parameters: [new OA\Parameter(name: 'operation', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Operation detail'),
            new OA\Response(response: 404, description: 'Operation not found in caller mailbox scope'),
        ],
    )]
    public function show(
        Request $request,
        EmailRemoteOperation $operation,
        MailboxAccess $mailboxAccess,
    ): EmailRemoteOperationResource {
        $this->assertVisible($request, $operation, $mailboxAccess);

        return new EmailRemoteOperationResource($operation->load([
            'account:id,address',
            'attemptRecords',
            'inverseOf:id,operation_type,status',
            'inverseOperation:id,inverse_of_email_remote_operation_id,operation_type,status',
        ]));
    }

    #[OA\Post(
        path: '/api/v1/email/mailbox/remote-operations/{operation}/retry',
        operationId: 'retryEmailRemoteOperation',
        summary: 'Safely retry an eligible mailbox provider operation',
        security: [['bearerAuth' => []]],
        tags: ['Email'],
        parameters: [new OA\Parameter(name: 'operation', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Retry outcome'),
            new OA\Response(response: 403, description: 'Mailbox Organize access required'),
            new OA\Response(response: 404, description: 'Operation not found in caller mailbox scope'),
            new OA\Response(response: 422, description: 'Operation is not safely retryable'),
        ],
    )]
    public function retry(
        Request $request,
        EmailRemoteOperation $operation,
        MailboxAccess $mailboxAccess,
        RetryEmailRemoteOperation $retryOperation,
    ): EmailRemoteOperationResource {
        $this->assertVisible($request, $operation, $mailboxAccess);

        return new EmailRemoteOperationResource(
            $retryOperation->handle($operation, $request->user())->load('attemptRecords'),
        );
    }

    #[OA\Post(
        path: '/api/v1/email/mailbox/remote-operations/{operation}/cancel',
        operationId: 'cancelEmailRemoteOperation',
        summary: 'Cancel an eligible mailbox provider operation',
        security: [['bearerAuth' => []]],
        tags: ['Email'],
        parameters: [new OA\Parameter(name: 'operation', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Cancellation outcome'),
            new OA\Response(response: 403, description: 'Mailbox Organize access required'),
            new OA\Response(response: 404, description: 'Operation not found in caller mailbox scope'),
            new OA\Response(response: 422, description: 'Operation is already running or terminal'),
        ],
    )]
    public function cancel(
        Request $request,
        EmailRemoteOperation $operation,
        MailboxAccess $mailboxAccess,
        CancelEmailRemoteOperation $cancelOperation,
    ): EmailRemoteOperationResource {
        $this->assertVisible($request, $operation, $mailboxAccess);

        return new EmailRemoteOperationResource(
            $cancelOperation->handle($operation, $request->user())->load('attemptRecords'),
        );
    }

    #[OA\Get(
        path: '/api/v1/email/mailbox/remote-operations/{operation}/undo',
        operationId: 'showEmailRemoteOperationUndoEligibility',
        summary: 'Show verified Undo eligibility for one mailbox provider operation',
        security: [['bearerAuth' => []]],
        tags: ['Email'],
        parameters: [new OA\Parameter(name: 'operation', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Current local Undo eligibility and reason'),
            new OA\Response(response: 404, description: 'Operation not found in caller mailbox scope'),
        ],
    )]
    public function undoEligibility(
        Request $request,
        EmailRemoteOperation $operation,
        MailboxAccess $mailboxAccess,
        EmailRemoteOperationUndoEligibility $eligibility,
    ): JsonResponse {
        $this->assertVisible($request, $operation, $mailboxAccess);

        $result = $eligibility->evaluate($operation, $request->user());

        return response()->json(['data' => [
            ...$result,
            'expires_at' => $result['expires_at']?->toIso8601String(),
        ]]);
    }

    #[OA\Post(
        path: '/api/v1/email/mailbox/remote-operations/{operation}/undo',
        operationId: 'applyEmailRemoteOperationUndo',
        summary: 'Create or return the verified inverse mailbox provider operation',
        security: [['bearerAuth' => []]],
        tags: ['Email'],
        parameters: [new OA\Parameter(name: 'operation', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Existing or newly executed inverse operation'),
            new OA\Response(response: 403, description: 'Mailbox Organize access required'),
            new OA\Response(response: 404, description: 'Operation not found in caller mailbox scope'),
            new OA\Response(response: 422, description: 'Source result is no longer safely reversible'),
        ],
    )]
    public function undo(
        Request $request,
        EmailRemoteOperation $operation,
        MailboxAccess $mailboxAccess,
        UndoEmailRemoteOperation $undoOperation,
    ): EmailRemoteOperationResource {
        $this->assertVisible($request, $operation, $mailboxAccess);

        return new EmailRemoteOperationResource(
            $undoOperation->handle($operation, $request->user())->load([
                'attemptRecords',
                'inverseOf:id,operation_type,status',
            ]),
        );
    }

    private function assertVisible(
        Request $request,
        EmailRemoteOperation $operation,
        MailboxAccess $mailboxAccess,
    ): void {
        $operation->loadMissing('account');

        abort_if(
            ! $operation->account
            || ! $mailboxAccess->canAccessAccount($request->user(), $operation->account, MailboxAccess::VIEW),
            404,
        );
    }
}
