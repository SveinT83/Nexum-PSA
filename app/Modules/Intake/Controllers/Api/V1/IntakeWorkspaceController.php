<?php

namespace App\Modules\Intake\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Clients\Client;
use App\Models\Core\User;
use App\Modules\Commercial\Models\Services\Services;
use App\Modules\Intake\Models\IntakeApiCommand;
use App\Modules\Intake\Models\IntakeForm;
use App\Modules\Intake\Models\IntakeFormField;
use App\Modules\Intake\Models\IntakeSubmission;
use App\Modules\Intake\Models\IntakeSubmissionAttachment;
use App\Modules\Intake\Support\IntakeApiAccess;
use App\Modules\Intake\Support\IntakeApiInput;
use App\Modules\Intake\Support\IntakeApiPayload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** Typed reads never initialize records or invoke automation. */
class IntakeWorkspaceController extends Controller
{
    public function context(Request $request)
    {
        IntakeApiInput::allowed($request, []);

        return response()->json(['data' => [
            'form_count' => IntakeForm::count(),
            'open_submission_count' => IntakeSubmission::whereIn('status', ['new', 'routing_skipped'])->count(),
            'statuses' => IntakeSubmission::statusLabels(),
            'capabilities' => collect(['forms' => ['intake.forms.write', 'intake.manage'],
                'review' => ['intake.submissions.review', 'intake.submission_review'],
                'route' => ['intake.submissions.route', 'intake.submission_review'],
                'attachments' => ['intake.attachments.read', 'intake.view']])
                ->map(fn ($branch) => $request->user()->tokenCan($branch[0]) && IntakeApiAccess::permits($request->user(), $branch[1]))->all(),
        ]]);
    }

    public function formContext(Request $request)
    {
        IntakeApiInput::allowed($request, []);

        return response()->json(['data' => [
            'statuses' => IntakeForm::statusLabels(), 'targets' => IntakeForm::targetLabels(),
            'scopes' => IntakeForm::scopeLabels(), 'routing_modes' => IntakeForm::routingModeLabels(),
            'field_types' => IntakeFormField::FIELD_TYPES, 'map_targets' => IntakeFormField::MAP_TARGETS,
            'layout_widths' => [12, 6, 4, 3], 'visibility_modes' => IntakeFormField::VISIBILITY_MODES,
            'visibility_match_modes' => IntakeFormField::VISIBILITY_MATCH_MODES,
            'visibility_operators' => IntakeFormField::VISIBILITY_OPERATORS,
            'defaults' => ['status' => 'draft', 'target_type' => 'review_only', 'auto_create_client' => false,
                'auto_create_contact' => true, 'spam_honeypot_field' => 'intake_website', 'max_files' => 5,
                'max_file_size_kb' => 20480, 'allowed_mime_types' => IntakeForm::DEFAULT_ALLOWED_MIME_TYPES,
                'fields' => []],
        ]]);
    }

    public function forms(Request $request)
    {
        IntakeApiInput::allowed($request, ['q', 'status', 'page', 'per_page']);
        $data = $request->validate(['q' => 'sometimes|string|max:200', 'status' => 'sometimes|in:draft,published,active,paused,archived']);
        $query = IntakeForm::query()->orderBy('name')->orderBy('id');
        if (isset($data['q'])) {
            $query->where('name', 'like', '%'.$data['q'].'%');
        }
        if (isset($data['status'])) {
            $query->where('status', $data['status']);
        }
        $page = $query->paginate(IntakeApiInput::page($request));

        return response()->json(['data' => $page->getCollection()->map(fn ($form) => IntakeApiPayload::form($form)),
            'meta' => self::meta($page)]);
    }

    public function form(Request $request, IntakeForm $form)
    {
        IntakeApiInput::allowed($request, []);

        return response()->json(['data' => IntakeApiPayload::form($form)])
            ->header('ETag', IntakeApiInput::revision($form));
    }

    public function submissions(Request $request)
    {
        IntakeApiInput::allowed($request, ['q', 'status', 'form_id', 'client_id', 'from', 'to', 'page', 'per_page']);
        $data = $request->validate(['q' => 'sometimes|string|max:200',
            'status' => 'sometimes|in:'.implode(',', array_keys(IntakeSubmission::statusLabels())),
            'form_id' => 'sometimes|integer|min:1', 'client_id' => 'sometimes|integer|min:1',
            'from' => 'sometimes|date_format:Y-m-d', 'to' => 'sometimes|date_format:Y-m-d|after_or_equal:from']);
        $query = IntakeSubmission::query()->latest('submitted_at')->latest('id');
        foreach (['status' => 'status', 'form_id' => 'intake_form_id', 'client_id' => 'matched_client_id'] as $key => $column) {
            if (isset($data[$key])) {
                if ($key === 'client_id') {
                    IntakeApiAccess::require($request->user(), 'client.view');
                }
                $query->where($column, $data[$key]);
            }
        }
        if (isset($data['q'])) {
            $query->where('normalized_payload', 'like', '%'.$data['q'].'%');
        }
        foreach (['from' => '>=', 'to' => '<='] as $key => $operator) {
            if (isset($data[$key])) {
                $query->whereDate('submitted_at', $operator, $data[$key]);
            }
        }
        $page = $query->paginate(IntakeApiInput::page($request));

        return response()->json(['data' => $page->getCollection()->map(fn ($row) => IntakeApiPayload::submission($row, $request)),
            'meta' => self::meta($page)]);
    }

    public function submission(Request $request, IntakeSubmission $submission)
    {
        IntakeApiInput::allowed($request, []);

        return response()->json(['data' => IntakeApiPayload::submission($submission, $request)])
            ->header('ETag', IntakeApiInput::revision($submission));
    }

    public function events(Request $request, IntakeSubmission $submission)
    {
        IntakeApiInput::allowed($request, ['page', 'per_page']);
        $page = $submission->events()->paginate(IntakeApiInput::page($request));
        $allowed = IntakeApiPayload::events($submission)->keyBy('id');

        return response()->json(['data' => $page->getCollection()->map(fn ($event) => $allowed[$event->id]),
            'meta' => self::meta($page)]);
    }

    public function attachments(Request $request, IntakeSubmission $submission)
    {
        IntakeApiInput::allowed($request, ['page', 'per_page']);
        $page = $submission->attachments()->orderBy('id')->paginate(IntakeApiInput::page($request));

        return response()->json(['data' => $page->getCollection()->map(IntakeApiPayload::attachment(...)),
            'meta' => self::meta($page)]);
    }

    public function download(Request $request, IntakeSubmission $submission, IntakeSubmissionAttachment $attachment)
    {
        IntakeApiInput::allowed($request, []);
        abort_unless($attachment->intake_submission_id === $submission->id, 404);
        abort_unless(Storage::disk($attachment->disk)->exists($attachment->path), 404);

        return Storage::disk($attachment->disk)->download($attachment->path, $attachment->original_filename ?: $attachment->filename,
            ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    public function lookups(Request $request, string $kind)
    {
        IntakeApiInput::allowed($request, ['q', 'page', 'per_page']);
        $data = $request->validate(['q' => 'sometimes|string|max:200']);
        if (in_array($kind, ['owners', 'clients', 'services'], true)) {
            IntakeApiAccess::branch($request, [['intake.forms.write', 'intake.manage']]);
        }
        [$query, $columns, $label, $permission] = match ($kind) {
            'owners' => [User::where('status', User::STATUS_ACTIVE), ['id', 'name'], 'name', null],
            'clients', 'link-clients' => [Client::query(), ['id', 'name', 'client_number'], 'name', $kind === 'clients' ? null : 'client.view'],
            'services' => [Services::query(), ['id', 'name', 'sku'], 'name', null],
            'link-contacts' => [\App\Modules\Contact\Models\Contact::query(), ['id', 'display_name'], 'display_name', 'contact.view'],
            'link-tickets' => [\App\Modules\Ticket\Models\Ticket::query(), ['id', 'ticket_key', 'subject'], 'subject', 'ticket.view'],
            'link-tasks' => [\App\Modules\Task\Models\Task::query(), ['id', 'title'], 'title', 'task.view'],
            'link-sales' => [\App\Modules\Sales\Models\SalesOpportunity::query(), ['id', 'opportunity_key', 'title'], 'title', 'sales.view'],
            default => abort(404),
        };
        if ($permission) {
            IntakeApiAccess::branch($request, [['intake.submissions.review', 'intake.submission_review']]);
            IntakeApiAccess::require($request->user(), $permission);
        }
        if (isset($data['q'])) {
            $query->where($label, 'like', '%'.$data['q'].'%');
        }
        $page = $query->orderBy($label)->orderBy('id')->paginate(IntakeApiInput::page($request), $columns);

        return response()->json(['data' => $page->items(), 'meta' => self::meta($page)]);
    }

    public function command(Request $request, string $command)
    {
        IntakeApiInput::allowed($request, []);
        abort_unless(\Illuminate\Support\Facades\Schema::hasTable('intake_api_commands'), 503, 'The Intake command migration is required.');
        $record = IntakeApiCommand::whereKey($command)->where('actor_key', 'staff:'.$request->user()->id)
            ->where('token_hash', IntakeApiAccess::tokenHash($request))->firstOrFail();

        return app(\App\Modules\Intake\Actions\ExecuteIntakeApiCommand::class)->response($request, $record);
    }

    private static function meta($page): array
    {
        return ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(),
            'total' => $page->total(), 'last_page' => $page->lastPage()];
    }
}
