<?php

namespace App\Modules\Signal\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Models\Core\User;
use App\Modules\CustomerPortal\Models\CustomerPortalMembership;
use App\Modules\Intake\Models\IntakeForm;
use App\Modules\Signal\Actions\EnsureSignalDefaults;
use App\Modules\Signal\Models\SignalRule;
use App\Modules\Signal\Support\SignalRuleDefinition;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SignalRuleController extends Controller
{
    public function index(EnsureSignalDefaults $defaults): View
    {
        $defaults->handle();

        return view('signal::Tech.rules.index', [
            'rules' => SignalRule::query()->withCount('executions')->orderBy('priority')->orderBy('id')->paginate(25),
        ]);
    }

    public function create(Request $request, EnsureSignalDefaults $defaults): View
    {
        $defaults->handle();

        return view('signal::Tech.rules.form', [
            'rule' => $this->newRuleFromRequest($request),
            'definition' => app(SignalRuleDefinition::class),
            'actorOptions' => $this->actorOptions(),
            'portalRoleOptions' => CustomerPortalMembership::roleOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $rule = SignalRule::query()->create([
            ...$data,
            'created_by' => $request->user()?->id,
            'updated_by' => $request->user()?->id,
        ]);

        return redirect()->route('tech.admin.system.signals.rules.show', $rule)->with('status', 'Signal rule created.');
    }

    public function show(SignalRule $rule): View
    {
        return view('signal::Tech.rules.show', [
            'rule' => $rule->load('executions.signal'),
            'definition' => app(SignalRuleDefinition::class),
            'actorOptions' => $this->actorOptions(),
            'portalRoleOptions' => CustomerPortalMembership::roleOptions(),
        ]);
    }

    public function update(SignalRule $rule, Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $rule->forceFill([
            ...$data,
            'updated_by' => $request->user()?->id,
        ])->save();

        return redirect()->route('tech.admin.system.signals.rules.show', $rule)->with('status', 'Signal rule updated.');
    }

    private function validated(Request $request): array
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
        $definition = app(SignalRuleDefinition::class);
        [$conditions, $actions] = $request->boolean('use_advanced_json') || (! $request->has('conditions') && ! $request->has('actions'))
            ? $definition->decodeAndValidate($data['conditions_json'] ?? '{}', $data['actions_json'])
            : $definition->buildAndValidate((array) $request->input('conditions', []), (array) $request->input('actions', []));

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

    private function actorOptions()
    {
        return User::query()
            ->where('status', User::STATUS_ACTIVE)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);
    }

    private function newRuleFromRequest(Request $request): SignalRule
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
