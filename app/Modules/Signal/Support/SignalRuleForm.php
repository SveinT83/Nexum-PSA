<?php

namespace App\Modules\Signal\Support;

use App\Models\Core\User;
use App\Modules\Intake\Models\IntakeForm;
use App\Modules\Signal\Models\SignalRule;
use App\Modules\Task\Models\TaskTemplateGroup;
use Illuminate\Http\Request;

/** Shared validation and form defaults; reads do not seed permissions or agents. */
class SignalRuleForm
{
    public function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
            'stop_processing' => ['nullable', 'boolean'],
            'priority' => ['required', 'integer', 'min:1', 'max:10000'],
            'use_advanced_json' => ['nullable', 'boolean'],
            'conditions_json' => ['nullable', 'json'],
            'actions_json' => [$request->has('actions') ? 'nullable' : 'required', 'json'],
            'conditions' => ['nullable', 'array'],
            'actions' => ['nullable', 'array'],
        ]);
        if ($request->routeIs('api.v1.signals.*')) {
            foreach ((array) $request->input('actions', []) as $index => $action) {
                if (! is_array($action)) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['actions.'.$index => 'An action object is required.']);
                }
                if (($action['type'] ?? '') === 'webhook' && str_contains((string) ($action['url'] ?? ''), '[redacted]')) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['actions.'.$index.'.url' => 'Supply an authorized webhook URL; redacted credentials cannot be saved.']);
                }
                $unknown = array_diff(array_keys($action), ['type', ...SignalRuleDefinition::ACTION_OPTIONAL_FIELDS]);
                if ($unknown) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['actions.'.$index => 'Unsupported action fields: '.implode(', ', $unknown)]);
                }
            }
        }
        $definition = app(SignalRuleDefinition::class);
        [$conditions, $actions] = $request->boolean('use_advanced_json') || (! $request->has('conditions') && ! $request->has('actions'))
            ? $definition->decodeAndValidate($data['conditions_json'] ?? '{}', $data['actions_json'])
            : $definition->buildAndValidate((array) $request->input('conditions', []), (array) $request->input('actions', []));

        if ($request->routeIs('api.v1.signals.*')) {
            foreach ($actions as $index => $action) {
                if (array_diff(array_keys($action), ['type', ...SignalRuleDefinition::ACTION_OPTIONAL_FIELDS])) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['actions.'.$index => 'Unsupported action fields in the stored definition.']);
                }
                if (($action['type'] ?? '') === 'webhook' && str_contains((string) ($action['url'] ?? ''), '[redacted]')) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['actions.'.$index.'.url' => 'Supply an authorized webhook URL; redacted credentials cannot be saved.']);
                }
            }
        }

        return [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'is_active' => $request->boolean('is_active'),
            'stop_processing' => $request->boolean('stop_processing'),
            'priority' => (int) $data['priority'],
            'conditions' => $conditions,
            'actions' => $actions,
        ];
    }

    public function actorOptions()
    {
        return User::query()
            ->where('status', User::STATUS_ACTIVE)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);
    }

    public function taskTemplateOptions()
    {
        return TaskTemplateGroup::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    public function newRuleFromRequest(Request $request): SignalRule
    {
        if ($request->query('source_domain') !== 'intake') {
            return new SignalRule(['is_active' => true, 'priority' => 100, 'stop_processing' => false]);
        }

        $form = IntakeForm::query()->find($request->integer('intake_form_id'));

        if (! $form) {
            return new SignalRule(['is_active' => true, 'priority' => 100, 'stop_processing' => false]);
        }

        return new SignalRule([
            'name' => 'After '.$form->name.' submission',
            'description' => 'Runs after this Intake form is submitted.',
            'is_active' => true,
            'priority' => 100,
            'stop_processing' => false,
            'conditions' => [
                'source_domain' => ['intake'],
                'signal_type' => ['intake_submission_received'],
                'payload_equals' => [
                    'intake_form_slug' => $form->slug,
                ],
            ],
            'actions' => [
                [
                    'type' => 'ticket_follow_up',
                    'subject' => 'Review '.$form->name.' submission',
                ],
            ],
        ]);
    }
}
