<?php

namespace App\Modules\Intake\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Intake\Actions\RouteIntakeSubmissionToSales;
use App\Modules\Intake\Models\IntakeSubmission;
use App\Modules\Intake\Models\IntakeSubmissionAttachment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class IntakeSubmissionController extends Controller
{
    public function show(IntakeSubmission $submission)
    {
        $submission->load([
            'form',
            'attachments.field',
            'events.actor',
            'matchedClient',
            'matchedSite',
            'matchedContact',
            'matchedClientUser',
            'target',
            'reviewedBy',
        ]);

        return view('intake::Admin.submissions.show', ['submission' => $submission]);
    }

    public function downloadAttachment(IntakeSubmission $submission, IntakeSubmissionAttachment $attachment): StreamedResponse
    {
        abort_unless($attachment->intake_submission_id === $submission->id, 404);
        abort_unless(Storage::disk($attachment->disk)->exists($attachment->path), 404);

        return Storage::disk($attachment->disk)->download(
            $attachment->path,
            $attachment->original_filename ?: $attachment->filename,
        );
    }

    public function markReviewed(Request $request, IntakeSubmission $submission): RedirectResponse
    {
        $submission->forceFill([
            'status' => $submission->status === IntakeSubmission::STATUS_ROUTED
                ? IntakeSubmission::STATUS_ROUTED
                : IntakeSubmission::STATUS_REVIEWED,
            'reviewed_at' => now(),
            'reviewed_by' => $request->user()?->id,
        ])->save();

        $submission->events()->create([
            'actor_id' => $request->user()?->id,
            'type' => 'reviewed',
            'message' => 'Submission marked as reviewed.',
        ]);

        return back()->with('success', 'Submission marked as reviewed.');
    }

    public function routeSales(
        Request $request,
        IntakeSubmission $submission,
        RouteIntakeSubmissionToSales $routeToSales,
    ): RedirectResponse {
        $opportunity = $routeToSales->handle($submission, true, $request->user());

        if (! $opportunity) {
            $submission->refresh();

            return back()->with('warning', $submission->routing_result['message'] ?? 'Submission could not be routed to Sales.');
        }

        return redirect()
            ->route('tech.admin.system.intake.submissions.show', $submission)
            ->with('success', 'Sales opportunity '.$opportunity->opportunity_key.' created.');
    }
}
