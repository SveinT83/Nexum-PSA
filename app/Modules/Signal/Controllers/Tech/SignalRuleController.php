<?php

namespace App\Modules\Signal\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Modules\Signal\Models\SignalRule;
use App\Modules\Signal\Support\SignalRuleDefinition;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SignalRuleController extends Controller
{
    public function index(): View
    {
        return view('signal::Tech.rules.index', [
            'rules' => SignalRule::query()->withCount('executions')->orderBy('priority')->orderBy('id')->paginate(25),
        ]);
    }

    public function create(): View
    {
        return view('signal::Tech.rules.form', [
            'rule' => new SignalRule(['is_active' => true, 'priority' => 100]),
            'definition' => app(SignalRuleDefinition::class),
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
            'priority' => ['required', 'integer', 'min:1', 'max:10000'],
            'conditions_json' => ['nullable', 'json'],
            'actions_json' => ['required', 'json'],
        ]);
        [$conditions, $actions] = app(SignalRuleDefinition::class)->decodeAndValidate(
            $data['conditions_json'] ?? '{}',
            $data['actions_json'],
        );

        return [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'is_active' => $request->boolean('is_active'),
            'priority' => (int) $data['priority'],
            'conditions' => $conditions,
            'actions' => $actions,
        ];
    }
}
