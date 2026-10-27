<?php

namespace App\Modules\Intake\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Intake\Actions\ExecuteIntakeApiCommand;
use App\Modules\Intake\Actions\StoreIntakeSubmission;
use App\Modules\Intake\Models\IntakeForm;
use App\Modules\Intake\Support\IntakeApiInput;
use App\Modules\Intake\Support\IntakeApiPayload;
use Illuminate\Http\Request;

/** Public projection and acknowledgement never disclose spam, matches or downstream records. */
class PublicIntakeController extends Controller
{
    public function show(Request $request, IntakeForm $form)
    {
        IntakeApiInput::allowed($request, []);
        abort_unless($form->isActive(), 404);

        return response()->json(['data' => IntakeApiPayload::form($form, true)])
            ->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, IntakeForm $form, StoreIntakeSubmission $store, ExecuteIntakeApiCommand $commands)
    {
        abort_unless($form->isActive(), 404);
        IntakeApiInput::allowed($request, ['fields', 'files', $form->spam_honeypot_field]);
        $request->attributes->set('intake_api', true);

        return $commands->execute($request, function () use ($request, $form, $store) {
            $store->validateRequest($request, $form);
            $request->attributes->set('intake_effect_started', true);
            $submission = $store->handle($request, $form);
            $request->attributes->set('intake_submission_id', $submission->id);

            return [['accepted' => true, 'message' => $form->success_message ?: 'Thank you. Your request has been received.'], 202];
        }, $form, true);
    }
}
