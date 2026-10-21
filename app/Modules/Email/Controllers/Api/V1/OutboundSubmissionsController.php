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
    public function show(Request $request, string $submission): EmailOutboundSubmissionResource
    {
        $submission = $this->visibleSubmission($request, $submission);

        return new EmailOutboundSubmissionResource(
            $submission->load(['draft', 'emailLog', 'sentReconciliation']),
        );
    }

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
