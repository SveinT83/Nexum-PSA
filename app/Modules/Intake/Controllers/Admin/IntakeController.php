<?php

namespace App\Modules\Intake\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Intake\Actions\EnsureIntakeDefaults;
use App\Modules\Intake\Models\IntakeForm;
use App\Modules\Intake\Models\IntakeSubmission;
use Illuminate\View\View;

class IntakeController extends Controller
{
    public function index(EnsureIntakeDefaults $ensureDefaults): View
    {
        if (! IntakeForm::query()->exists()) {
            $ensureDefaults->handle();
        }

        return view('intake::Admin.index', [
            'forms' => IntakeForm::query()
                ->withCount('submissions')
                ->orderBy('name')
                ->get(),
            'submissions' => IntakeSubmission::query()
                ->with(['form', 'matchedClient'])
                ->withCount('attachments')
                ->latest('submitted_at')
                ->limit(25)
                ->get(),
            'openSubmissionCount' => IntakeSubmission::query()
                ->whereIn('status', [IntakeSubmission::STATUS_NEW, IntakeSubmission::STATUS_ROUTING_SKIPPED])
                ->count(),
        ]);
    }
}
