<?php

namespace App\Modules\Intake\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Modules\Intake\Actions\StoreIntakeSubmission;
use App\Modules\Intake\Models\IntakeForm;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class IntakeFormController extends Controller
{
    public function show(IntakeForm $form): View
    {
        abort_unless($form->isActive(), 404);

        $form->load('activeFields');

        return view('intake::Public.show', [
            'form' => $form,
            'fields' => $form->activeFields,
        ]);
    }

    public function store(Request $request, IntakeForm $form, StoreIntakeSubmission $storeSubmission): RedirectResponse
    {
        $submission = $storeSubmission->handle($request, $form);

        return redirect()
            ->route('intake.forms.thanks', $form)
            ->with('intake_submission_id', $submission->id);
    }

    public function thanks(IntakeForm $form): View
    {
        abort_unless($form->isActive(), 404);

        return view('intake::Public.thanks', ['form' => $form]);
    }
}
