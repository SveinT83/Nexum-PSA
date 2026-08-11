<?php

namespace App\Modules\Intake\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Intake\Actions\LinkIntakeSubmissionTarget;
use App\Modules\Intake\Actions\MarkIntakeSubmissionOutcome;
use App\Modules\Intake\Actions\RouteIntakeSubmissionToSales;
use App\Modules\Intake\Actions\RouteIntakeSubmissionToTask;
use App\Modules\Intake\Actions\RouteIntakeSubmissionToTicket;
use App\Modules\Intake\Models\IntakeSubmission;
use App\Modules\Intake\Models\IntakeSubmissionAttachment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
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

        return view('intake::Admin.submissions.show', [
            'submission' => $submission,
            'linkTargetTypes' => LinkIntakeSubmissionTarget::targetLabels(),
        ]);
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

    public function markReviewed(
        Request $request,
        IntakeSubmission $submission,
        MarkIntakeSubmissionOutcome $markOutcome,
    ): RedirectResponse
    {
        $markOutcome->handle($submission, IntakeSubmission::STATUS_REVIEWED, $request->user());

        return back()->with('success', 'Submission marked as reviewed.');
    }

    public function markOutcome(
        Request $request,
        IntakeSubmission $submission,
        MarkIntakeSubmissionOutcome $markOutcome,
    ): RedirectResponse {
        $validated = $request->validate([
            'status' => ['required', Rule::in([
                IntakeSubmission::STATUS_SPAM,
                IntakeSubmission::STATUS_DUPLICATE,
                IntakeSubmission::STATUS_REJECTED,
                IntakeSubmission::STATUS_ARCHIVED,
            ])],
            'reason' => ['nullable', 'string', 'max:1000'],
            'duplicate_of_submission_id' => ['nullable', 'integer', 'exists:intake_submissions,id'],
        ]);

        $metadata = [];

        if (! empty($validated['duplicate_of_submission_id'])) {
            $metadata['duplicate_of_submission_id'] = (int) $validated['duplicate_of_submission_id'];
        }

        $markOutcome->handle(
            $submission,
            $validated['status'],
            $request->user(),
            $validated['reason'] ?? null,
            $metadata,
        );

        return back()->with('success', 'Submission outcome updated.');
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

    public function routeTicket(
        Request $request,
        IntakeSubmission $submission,
        RouteIntakeSubmissionToTicket $routeToTicket,
    ): RedirectResponse {
        $ticket = $routeToTicket->handle($submission, true, $request->user());

        if (! $ticket) {
            $submission->refresh();

            return back()->with('warning', $submission->routing_result['message'] ?? 'Submission could not be routed to Ticket.');
        }

        return redirect()
            ->route('tech.admin.system.intake.submissions.show', $submission)
            ->with('success', 'Ticket '.$ticket->ticket_key.' created.');
    }

    public function routeTask(
        Request $request,
        IntakeSubmission $submission,
        RouteIntakeSubmissionToTask $routeToTask,
    ): RedirectResponse {
        $task = $routeToTask->handle($submission, true, $request->user());

        if (! $task) {
            $submission->refresh();

            return back()->with('warning', $submission->routing_result['message'] ?? 'Submission could not be routed to Task.');
        }

        return redirect()
            ->route('tech.admin.system.intake.submissions.show', $submission)
            ->with('success', 'Task #'.$task->id.' created.');
    }

    public function linkExisting(
        Request $request,
        IntakeSubmission $submission,
        LinkIntakeSubmissionTarget $linkTarget,
    ): RedirectResponse {
        $validated = $request->validate([
            'target_type' => ['required', Rule::in(array_keys(LinkIntakeSubmissionTarget::targetLabels()))],
            'reference' => ['required', 'string', 'max:160'],
        ]);

        $linkTarget->handle($submission, $validated['target_type'], $validated['reference'], $request->user());

        return redirect()
            ->route('tech.admin.system.intake.submissions.show', $submission)
            ->with('success', 'Existing record linked.');
    }
}
