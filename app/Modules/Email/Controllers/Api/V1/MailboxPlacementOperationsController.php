<?php

namespace App\Modules\Email\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Email\Actions\PerformEmailRemoteOperation;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Resources\Api\V1\EmailRemoteOperationResource;
use App\Modules\Email\Services\MailboxAccess;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Email',
    description: 'API endpoints for inbox messages and email operations.'
)]
class MailboxPlacementOperationsController extends Controller
{
    public function store(
        Request $request,
        EmailMailboxPlacement $placement,
        PerformEmailRemoteOperation $performRemoteOperation,
        MailboxAccess $mailboxAccess,
    ) {
        $placement->loadMissing(['account', 'message']);

        abort_if(
            ! $placement->account
                || ! $placement->message?->hasActiveProviderPlacement($placement)
                || ! $mailboxAccess->canAccessAccount(
                    $request->user(),
                    $placement->account,
                    MailboxAccess::VIEW,
                ),
            404,
        );

        $validated = $request->validate([
            'operation' => ['required', 'string', Rule::in(PerformEmailRemoteOperation::allowedOperations())],
            'target_folder_id' => ['required_if:operation,'.PerformEmailRemoteOperation::MOVE, 'nullable', 'integer', 'exists:email_folders,id'],
        ]);

        $targetFolder = $validated['operation'] === PerformEmailRemoteOperation::MOVE
            ? EmailFolder::query()->find($validated['target_folder_id'])
            : null;

        $operation = $performRemoteOperation->handle($placement, $validated['operation'], $request->user(), $targetFolder);

        return response()->json([
            'data' => [
                'operation' => new EmailRemoteOperationResource($operation),
            ],
        ]);
    }
}
