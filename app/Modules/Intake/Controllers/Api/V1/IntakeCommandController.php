<?php

namespace App\Modules\Intake\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Intake\Actions\ExecuteIntakeApiCommand;
use App\Modules\Intake\Actions\LinkIntakeSubmissionTarget;
use App\Modules\Intake\Actions\MarkIntakeSubmissionOutcome;
use App\Modules\Intake\Actions\RouteIntakeSubmissionToSales;
use App\Modules\Intake\Actions\RouteIntakeSubmissionToTask;
use App\Modules\Intake\Actions\RouteIntakeSubmissionToTicket;
use App\Modules\Intake\Actions\SaveIntakeForm;
use App\Modules\Intake\Models\IntakeForm;
use App\Modules\Intake\Models\IntakeSubmission;
use App\Modules\Intake\Support\IntakeApiAccess;
use App\Modules\Intake\Support\IntakeApiInput;
use App\Modules\Intake\Support\IntakeApiPayload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Thin API transport over the same form and review/routing actions as the browser. */
class IntakeCommandController extends Controller
{
    public const FORM_KEYS = ['name', 'slug', 'description', 'status', 'success_message',
        'submit_button_label', 'purpose', 'language', 'scope_type', 'scope_client_id',
        'scope_service_id', 'scope_campaign_key', 'target_type', 'routing_mode', 'owner_id',
        'spam_honeypot_field', 'max_files', 'max_file_size_kb', 'allowed_mime_types_text',
        'auto_create_client', 'auto_create_contact', 'fields'];

    public function store(Request $request, SaveIntakeForm $save, ExecuteIntakeApiCommand $commands)
    {
        return $this->save($request, $save, $commands);
    }

    public function update(Request $request, IntakeForm $form, SaveIntakeForm $save, ExecuteIntakeApiCommand $commands)
    {
        return $this->save($request, $save, $commands, $form);
    }

    private function save(Request $request, SaveIntakeForm $save, ExecuteIntakeApiCommand $commands, ?IntakeForm $form = null)
    {
        self::formInput($request);

        // Replay lookup occurs before mutable uniqueness validation; a completed create can be repeated.
        return $commands->execute($request, function () use ($request, $save, $form) {
            $record = $save->handle($request, $form);

            return [['state' => 'completed', 'data' => IntakeApiPayload::form($record)], $form ? 200 : 201];
        }, $form);
    }

    public static function formInput(Request $request): void
    {
        IntakeApiInput::allowed($request, self::FORM_KEYS);
        $request->validate(['fields' => 'required|array|max:100', 'fields.*' => 'array']);
        $keys = ['id', 'key', 'label', 'field_type', 'maps_to', 'help_text', 'placeholder',
            'options_text', 'is_required', 'is_active', 'max_files', 'max_file_size_kb',
            'allowed_mime_types_text', 'layout_width', 'visibility_mode', 'visibility_match', 'visibility_rules'];
        foreach ($request->input('fields') as $index => $row) {
            if (array_diff(array_keys($row), $keys)) {
                throw ValidationException::withMessages(['fields.'.$index => 'Unsupported field setting.']);
            }
        }
    }

    public function toggle(Request $request, IntakeForm $form, SaveIntakeForm $save, ExecuteIntakeApiCommand $commands)
    {
        IntakeApiInput::allowed($request, []);

        return $commands->execute($request, fn () => [
            ['state' => 'completed', 'data' => IntakeApiPayload::form($save->toggle($form))], 200,
        ], $form);
    }

    public function review(Request $request, IntakeSubmission $submission, ExecuteIntakeApiCommand $commands)
    {
        $action = $request->route()->defaults['intake_action'];
        $validated = $this->validateAction($request, $action, $submission);

        return $commands->execute($request, function () use ($request, $submission, $action, $validated) {
            $apply = function () use ($request, $submission, $action, $validated) {
                return match ($action) {
                    'reviewed' => app(MarkIntakeSubmissionOutcome::class)->handle($submission, 'reviewed', $request->user()),
                    'outcome' => app(MarkIntakeSubmissionOutcome::class)->handle($submission, $validated['status'], $request->user(),
                        $validated['reason'] ?? null, empty($validated['duplicate_of_submission_id']) ? [] :
                            ['duplicate_of_submission_id' => (int) $validated['duplicate_of_submission_id']]),
                    'link-existing' => app(LinkIntakeSubmissionTarget::class)->handle($submission,
                        $validated['target_type'], $validated['reference'], $request->user()),
                    'route-sales' => app(RouteIntakeSubmissionToSales::class)->handle($submission, true, $request->user()),
                    'route-ticket' => app(RouteIntakeSubmissionToTicket::class)->handle($submission, true, $request->user()),
                    'route-task' => app(RouteIntakeSubmissionToTask::class)->handle($submission, true, $request->user()),
                };
            };
            // Sales may call provider-authoritative customer creation; do not wrap that in a DB transaction.
            if ($action === 'route-sales') {
                $request->attributes->set('intake_effect_started', true);
                $apply();
            } else {
                DB::transaction($apply);
            }
            $submission->refresh();
            $state = str_starts_with($action, 'route-') && ! $submission->hasTarget() ? 'skipped' : 'completed';
            if ($submission->routing_result['reconciliation_required'] ?? false) {
                $state = 'unknown';
            }

            return [['state' => $state, 'reconciliation_required' => $state === 'unknown',
                'data' => IntakeApiPayload::submission($submission, $request)], $state === 'unknown' ? 202 : 200];
        }, $submission);
    }

    public function validateAction(Request $request, string $action, IntakeSubmission $submission): array
    {
        $keys = match ($action) {
            'reviewed', 'route-sales', 'route-ticket', 'route-task' => [],
            'outcome' => ['status', 'reason', 'duplicate_of_submission_id'],
            'link-existing' => ['target_type', 'reference'],
            default => abort(422, 'Unsupported Intake action.'),
        };
        IntakeApiInput::allowed($request, $keys);
        if ($action === 'outcome') {
            $data = $request->validate(['status' => ['required', Rule::in(['spam', 'duplicate', 'rejected', 'archived'])],
                'reason' => 'nullable|string|max:1000', 'duplicate_of_submission_id' => 'nullable|integer|exists:intake_submissions,id']);
            if (! empty($data['duplicate_of_submission_id']) && ((int) $data['duplicate_of_submission_id'] === $submission->id || $data['status'] !== 'duplicate')) {
                throw ValidationException::withMessages(['duplicate_of_submission_id' => 'Choose another submission for a duplicate outcome.']);
            }
            if ($submission->hasTarget() && $data['status'] !== 'archived') {
                throw ValidationException::withMessages(['status' => 'A routed submission can only be marked reviewed or archived.']);
            }

            return $data;
        }
        if ($action === 'link-existing') {
            $data = $request->validate(['target_type' => ['required', Rule::in(array_keys(LinkIntakeSubmissionTarget::targetLabels()))],
                'reference' => 'required|string|max:160']);
            $permission = match ($data['target_type']) {
                'client' => 'client.view', 'contact' => 'contact.view', 'ticket' => 'ticket.view',
                'task' => 'task.view', 'sales_opportunity' => 'sales.view',
            };
            IntakeApiAccess::require($request->user(), $permission);
            app(LinkIntakeSubmissionTarget::class)->resolve($data['target_type'], $data['reference']);
            $request->attributes->set('intake_result_permissions', [$permission]);

            return $data;
        }

        return [];
    }

    public function preview(Request $request, SaveIntakeForm $save)
    {
        IntakeApiInput::allowed($request, ['operation', 'form_slug', 'submission_id', 'data']);
        $data = $request->validate(['operation' => 'required|in:create-form,update-form,toggle-form,reviewed,outcome,link-existing,route-sales,route-ticket,route-task',
            'form_slug' => 'nullable|string|max:255', 'submission_id' => 'nullable|integer|min:1', 'data' => 'sometimes|array']);
        $operation = $data['operation'];
        $formOperation = in_array($operation, ['create-form', 'update-form', 'toggle-form'], true);
        $ability = $formOperation ? 'intake.forms.write' : (str_starts_with($operation, 'route-') ? 'intake.submissions.route' : 'intake.submissions.review');
        $permission = $formOperation ? 'intake.manage' : 'intake.submission_review';
        IntakeApiAccess::branch($request, [[$ability, $permission]]);
        $record = null;
        if ($formOperation && $operation !== 'create-form') {
            $record = IntakeForm::where('slug', $data['form_slug'] ?? '')->firstOrFail();
        } elseif (! $formOperation) {
            $record = IntakeSubmission::findOrFail($data['submission_id'] ?? 0);
        }
        $input = Request::create('/', 'POST', $data['data'] ?? []);
        $input->setUserResolver($request->getUserResolver());
        if (in_array($operation, ['create-form', 'update-form'], true)) {
            self::formInput($input);
            $save->prepare($input, $record);
        } elseif ($formOperation) {
            IntakeApiInput::allowed($input, []);
        } else {
            $this->validateAction($input, $operation, $record);
        }
        $effects = $formOperation ? ['form_configuration'] : (str_starts_with($operation, 'route-')
            ? ['submission', 'native_target', 'audit_event'] : ['submission', 'audit_event']);

        return response()->json(['data' => ['operation' => $operation, 'valid' => true,
            'revision' => $record ? IntakeApiInput::revision($record) : null,
            'effects' => $effects, 'blocked' => $record instanceof IntakeSubmission
                && str_starts_with($operation, 'route-') && ($record->isClosedForRouting() || $record->hasTarget()),
            'provider_customer_creation_possible' => $operation === 'route-sales'
                && ! $record->matched_client_id && (bool) $record->form?->auto_create_client,
        ]]);
    }
}
