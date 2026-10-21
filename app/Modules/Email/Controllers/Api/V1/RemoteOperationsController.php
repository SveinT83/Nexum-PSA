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
