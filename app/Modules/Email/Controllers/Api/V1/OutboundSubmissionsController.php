<?php

namespace App\Modules\Email\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Email\Models\EmailOutboundSubmission;
use App\Modules\Email\Resources\Api\V1\EmailOutboundSubmissionResource;
use App\Modules\Email\Services\MailboxAccess;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Email Mail submissions', description: 'Safe outbound and provider Sent status for private Mail submissions.')]
class OutboundSubmissionsController extends Controller
{
    #[OA\Get(path: '/api/v1/email/mailbox/submissions/{submission}', operationId: 'showEmailMailboxSubmission', summary: 'Show one owned outbound Mail submission', security: [['bearerAuth' => []]], tags: ['Email Mail submissions'], parameters: [new OA\Parameter(name: 'submission', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Safe outbound state and Email-log status'), new OA\Response(response: 404, description: 'Submission outside current scope')])]
    public function show(Request $request, string $submission): EmailOutboundSubmissionResource
    {
        $submission = $this->visibleSubmission($request, $submission);

        return new EmailOutboundSubmissionResource(
            $submission->load(['draft', 'emailLog', 'sentReconciliation']),
        );
    }

    #[OA\Get(path: '/api/v1/email/mailbox/submissions/{submission}/sent-reconciliation', operationId: 'showEmailMailboxSubmissionSentReconciliation', summary: 'Show exact provider Sent reconciliation status', security: [['bearerAuth' => []]], tags: ['Email Mail submissions'], parameters: [new OA\Parameter(name: 'submission', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Safe submission and Sent-reconciliation status'), new OA\Response(response: 404, description: 'Submission outside current scope')])]
    public function sentReconciliation(Request $request, string $submission): EmailOutboundSubmissionResource
    {
        $submission = $this->visibleSubmission($request, $submission);

        return new EmailOutboundSubmissionResource(
            $submission->load(['draft', 'emailLog', 'sentReconciliation']),
        );
    }

    private function visibleSubmission(Request $request, string $publicId): EmailOutboundSubmission
    {
        $actor = $request->user();
        abort_if(! $actor?->isActive() || $actor->isSystemActor(), 403);
        $submission = EmailOutboundSubmission::query()
            ->with('account')
            ->where('public_id', $publicId)
            ->where('actor_id', $actor->id)
            ->firstOrFail();

        abort_if(
            ! $submission->account
            || ! app(MailboxAccess::class)->canAccessAccount(
                $actor,
                $submission->account,
                MailboxAccess::SEND,
            ),
            404,
        );

        return $submission;
    }
}
