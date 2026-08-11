<?php

namespace App\Modules\Intake\Actions;

use App\Modules\Intake\Models\IntakeForm;
use App\Modules\Intake\Models\IntakeFormField;
use App\Modules\Intake\Models\IntakeSubmission;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Throwable;

class StoreIntakeSubmission
{
    public function __construct(
        private readonly StoreIntakeAttachment $storeAttachment,
        private readonly MatchIntakeSubmissionContext $matchContext,
        private readonly RouteIntakeSubmissionToSales $routeToSales,
        private readonly RouteIntakeSubmissionToTicket $routeToTicket,
        private readonly RouteIntakeSubmissionToTask $routeToTask,
        private readonly RecordIntakeSubmissionSignal $recordSignal,
    ) {}

    public function handle(Request $request, IntakeForm $form): IntakeSubmission
    {
        abort_unless($form->isActive(), 404);

        $form->loadMissing('activeFields');
        $honeypot = trim((string) $request->input($form->spam_honeypot_field, ''));

        if ($honeypot !== '') {
            return $this->recordSpam($request, $form, $honeypot);
        }

        $visibleFields = $this->visibleFields($form, $request);

        $validated = $request->validate(
            $this->rules($visibleFields),
            [],
            $this->attributes($visibleFields),
        );

        $rawFields = Arr::only($request->input('fields', []), $visibleFields->pluck('key')->all());
        $normalized = $this->normalizedPayload($visibleFields, $rawFields);
        $match = $this->matchContext->handle($normalized, $form);

        $submission = DB::transaction(function () use ($request, $form, $rawFields, $normalized, $match, $visibleFields): IntakeSubmission {
            $submission = IntakeSubmission::query()->create([
                'intake_form_id' => $form->id,
                'status' => IntakeSubmission::STATUS_NEW,
                'source_url' => $request->fullUrl(),
                'referrer' => $request->headers->get('referer'),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'raw_payload' => [
                    'fields' => $rawFields,
                    'form_snapshot' => $this->formSnapshot($form),
                    'field_snapshots' => $this->fieldSnapshots($visibleFields),
                ],
                'normalized_payload' => $normalized,
                'matched_client_id' => $match['matched_client_id'],
                'matched_site_id' => $match['matched_site_id'],
                'matched_contact_id' => $match['matched_contact_id'],
                'matched_client_user_id' => $match['matched_client_user_id'],
                'routing_result' => ['match_method' => $match['match_method']],
                'submitted_at' => now(),
            ]);

            foreach ($visibleFields->where('field_type', IntakeFormField::TYPE_FILE) as $field) {
                foreach ($this->filesFor($request, $field) as $file) {
                    $this->storeAttachment->handle($submission, $field, $file);
                }
            }

            $submission->events()->create([
                'type' => 'submitted',
                'message' => 'Public inquiry submitted.',
                'metadata' => ['match_method' => $match['match_method']],
            ]);

            return $submission;
        });

        $this->routeSubmission($submission);

        $submission = $submission->refresh();

        try {
            $this->recordSignal->handle($submission);
        } catch (Throwable $exception) {
            report($exception);

            $submission->events()->create([
                'type' => 'signal_recording_failed',
                'message' => 'Signal automation event could not be recorded.',
                'metadata' => [
                    'error' => $exception->getMessage(),
                ],
            ]);
        }

        return $submission->refresh();
    }

    private function recordSpam(Request $request, IntakeForm $form, string $honeypot): IntakeSubmission
    {
        $submission = IntakeSubmission::query()->create([
            'intake_form_id' => $form->id,
            'status' => IntakeSubmission::STATUS_SPAM,
            'source_url' => $request->fullUrl(),
            'referrer' => $request->headers->get('referer'),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'honeypot_value' => $honeypot,
            'raw_payload' => [
                'fields' => $request->input('fields', []),
                'form_snapshot' => $this->formSnapshot($form),
                'field_snapshots' => $this->fieldSnapshots($form->activeFields),
            ],
            'submitted_at' => now(),
        ]);

        $submission->events()->create([
            'type' => 'spam_detected',
            'message' => 'Honeypot field was filled.',
        ]);

        return $submission;
    }

    private function routeSubmission(IntakeSubmission $submission): void
    {
        $submission->loadMissing('form');
        $form = $submission->form;

        if (! $form || $form->target_type === IntakeForm::TARGET_REVIEW_ONLY) {
            return;
        }

        if (! $form->shouldAutoRoute($submission)) {
            if ($form->routingMode() === IntakeForm::ROUTING_MODE_KNOWN_CLIENT_ONLY && ! $submission->matched_client_id) {
                $this->markRoutingSkipped($submission, 'Routing policy requires an existing client match.', [
                    'reason' => 'known_client_required',
                    'routing_mode' => $form->routingMode(),
                    'target_type' => $form->target_type,
                ]);
            }

            return;
        }

        match ($form->target_type) {
            IntakeForm::TARGET_SALES_LEAD => $this->routeToSales->handle($submission),
            IntakeForm::TARGET_TICKET => $this->routeToTicket->handle($submission),
            IntakeForm::TARGET_TASK => $this->routeToTask->handle($submission),
            default => null,
        };
    }

    private function markRoutingSkipped(IntakeSubmission $submission, string $message, array $metadata): void
    {
        $submission->forceFill([
            'status' => IntakeSubmission::STATUS_ROUTING_SKIPPED,
            'routing_result' => array_replace($submission->routing_result ?: [], $metadata + ['message' => $message]),
        ])->save();

        $submission->events()->create([
            'type' => 'routing_skipped',
            'message' => $message,
            'metadata' => $metadata,
        ]);
    }

    private function formSnapshot(IntakeForm $form): array
    {
        return [
            'id' => $form->id,
            'name' => $form->name,
            'slug' => $form->slug,
            'status' => $form->status,
            'target_type' => $form->target_type,
            'purpose' => $form->purpose(),
            'language' => $form->language(),
            'scope' => [
                'type' => $form->scopeType(),
                'client_id' => $form->scopeClientId(),
                'service_id' => $form->scopeServiceId(),
                'campaign_key' => $form->campaignKey(),
            ],
            'routing' => [
                'mode' => $form->routingMode(),
            ],
        ];
    }

    private function fieldSnapshots($fields): array
    {
        return $fields
            ->map(fn (IntakeFormField $field): array => [
                'id' => $field->id,
                'key' => $field->key,
                'label' => $field->label,
                'field_type' => $field->field_type,
                'maps_to' => $field->maps_to,
                'is_required' => $field->is_required,
                'sort_order' => $field->sort_order,
                'layout_width' => $field->layoutWidth(),
                'visibility' => $field->visibility(),
            ])
            ->values()
            ->all();
    }

    private function rules($fields): array
    {
        $rules = [
            'fields' => ['array'],
            'files' => ['array'],
        ];

        foreach ($fields as $field) {
            $required = $field->is_required ? 'required' : 'nullable';

            if ($field->isFileField()) {
                $rules['files.'.$field->key] = [$required, 'array', 'max:'.$field->maxFiles()];
                $fileRules = ['file', 'max:'.$field->maxFileSizeKb()];
                $allowedMimeTypes = $field->allowedMimeTypes();

                if (! empty($allowedMimeTypes)) {
                    $fileRules[] = 'mimetypes:'.implode(',', $allowedMimeTypes);
                }

                $rules['files.'.$field->key.'.*'] = $fileRules;

                continue;
            }

            $fieldKey = 'fields.'.$field->key;

            $rules[$fieldKey] = match ($field->field_type) {
                IntakeFormField::TYPE_TEXT => [$required, 'string', 'max:255'],
                IntakeFormField::TYPE_TEXTAREA => [$required, 'string', 'max:5000'],
                IntakeFormField::TYPE_EMAIL => [$required, 'email', 'max:255'],
                IntakeFormField::TYPE_PHONE => [$required, 'string', 'max:80'],
                IntakeFormField::TYPE_URL => [$required, 'url', 'max:255'],
                IntakeFormField::TYPE_SELECT => [$required, Rule::in($this->optionValues($field))],
                IntakeFormField::TYPE_MULTISELECT => [$required, 'array'],
                IntakeFormField::TYPE_CHECKBOX, IntakeFormField::TYPE_CONSENT => [$required, 'accepted'],
                default => [$required, 'string', 'max:255'],
            };

            if ($field->field_type === IntakeFormField::TYPE_MULTISELECT) {
                $rules[$fieldKey.'.*'] = [Rule::in($this->optionValues($field))];
            }
        }

        return $rules;
    }

    private function attributes($fields): array
    {
        $attributes = [];

        foreach ($fields as $field) {
            $attributes['fields.'.$field->key] = $field->label;
            $attributes['files.'.$field->key] = $field->label;
            $attributes['files.'.$field->key.'.*'] = $field->label;
        }

        return $attributes;
    }

    private function normalizedPayload($fields, array $rawFields): array
    {
        $normalized = [];

        foreach ($fields as $field) {
            if ($field->isFileField() || ! $field->maps_to) {
                continue;
            }

            $value = Arr::get($rawFields, $field->key);

            if (is_array($value)) {
                $value = array_values(array_filter($value, fn ($item) => trim((string) $item) !== ''));
            } else {
                $value = trim((string) $value);
            }

            $normalized[$field->maps_to] = $value;
        }

        return $normalized;
    }

    private function visibleFields(IntakeForm $form, Request $request)
    {
        $fields = $form->activeFields;
        $fieldsByKey = $fields->keyBy('key');
        $visibleByKey = [];

        return $fields
            ->filter(function (IntakeFormField $field) use ($request, $fieldsByKey, &$visibleByKey): bool {
                $visible = $this->fieldIsVisible($field, $request, $fieldsByKey, $visibleByKey);
                $visibleByKey[$field->key] = $visible;

                return $visible;
            })
            ->values();
    }

    private function fieldIsVisible(IntakeFormField $field, Request $request, $fieldsByKey, array $visibleByKey): bool
    {
        $visibility = $field->visibility();

        if ($visibility['mode'] !== IntakeFormField::VISIBILITY_MODE_CONDITIONAL) {
            return true;
        }

        $results = collect($visibility['rules'])
            ->map(fn (array $rule): bool => $this->visibilityRuleMatches($rule, $request, $fieldsByKey, $visibleByKey))
            ->all();

        if ($results === []) {
            return false;
        }

        return $visibility['match'] === IntakeFormField::VISIBILITY_MATCH_ANY
            ? in_array(true, $results, true)
            : ! in_array(false, $results, true);
    }

    private function visibilityRuleMatches(array $rule, Request $request, $fieldsByKey, array $visibleByKey): bool
    {
        $sourceKey = (string) ($rule['source_key'] ?? '');
        $sourceField = $fieldsByKey->get($sourceKey);

        if (! $sourceField || ! ($visibleByKey[$sourceKey] ?? false)) {
            return false;
        }

        $values = $this->submittedValues($request, $sourceField);
        $expected = trim((string) ($rule['value'] ?? ''));

        return match ((string) ($rule['operator'] ?? IntakeFormField::VISIBILITY_OPERATOR_HAS_VALUE)) {
            IntakeFormField::VISIBILITY_OPERATOR_HAS_VALUE => $values !== [],
            IntakeFormField::VISIBILITY_OPERATOR_EQUALS => in_array($expected, $values, true),
            IntakeFormField::VISIBILITY_OPERATOR_NOT_EQUALS => $values !== [] && ! in_array($expected, $values, true),
            IntakeFormField::VISIBILITY_OPERATOR_CONTAINS => $this->containsVisibilityValue($sourceField, $values, $expected),
            IntakeFormField::VISIBILITY_OPERATOR_CHECKED => $values !== [],
            IntakeFormField::VISIBILITY_OPERATOR_UNCHECKED => $values === [],
            default => false,
        };
    }

    private function containsVisibilityValue(IntakeFormField $field, array $values, string $expected): bool
    {
        if ($expected === '') {
            return false;
        }

        if ($field->field_type === IntakeFormField::TYPE_MULTISELECT) {
            return in_array($expected, $values, true);
        }

        return collect($values)->contains(fn (string $value): bool => str_contains($value, $expected));
    }

    private function submittedValues(Request $request, IntakeFormField $field): array
    {
        if ($field->isFileField()) {
            return $this->filesFor($request, $field) !== [] ? ['1'] : [];
        }

        $value = $request->input('fields.'.$field->key);

        if (is_array($value)) {
            return array_values(array_filter(
                array_map(fn ($item) => trim((string) $item), $value),
                fn (string $item): bool => $item !== '',
            ));
        }

        $value = trim((string) $value);

        return $value !== '' ? [$value] : [];
    }

    /**
     * @return array<int, UploadedFile>
     */
    private function filesFor(Request $request, IntakeFormField $field): array
    {
        $files = $request->file('files.'.$field->key, []);
        $files = is_array($files) ? $files : [$files];

        return array_values(array_filter($files, fn ($file) => $file instanceof UploadedFile && $file->isValid()));
    }

    private function optionValues(IntakeFormField $field): array
    {
        return collect($field->options ?: [])
            ->map(fn ($option) => is_array($option) ? ($option['value'] ?? $option['label'] ?? null) : $option)
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->values()
            ->all();
    }
}
