<?php

namespace App\Modules\Signal\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Modules\CustomerPortal\Models\CustomerPortalMembership;
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
            'rule' => app(\App\Modules\Signal\Support\SignalRuleForm::class)->newRuleFromRequest($request),
            'definition' => app(SignalRuleDefinition::class),
            'actorOptions' => app(\App\Modules\Signal\Support\SignalRuleForm::class)->actorOptions(),
            'portalRoleOptions' => CustomerPortalMembership::roleOptions(),
            'taskTemplateOptions' => app(\App\Modules\Signal\Support\SignalRuleForm::class)->taskTemplateOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = app(\App\Modules\Signal\Support\SignalRuleForm::class)->validated($request);
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
            'actorOptions' => app(\App\Modules\Signal\Support\SignalRuleForm::class)->actorOptions(),
            'portalRoleOptions' => CustomerPortalMembership::roleOptions(),
            'taskTemplateOptions' => app(\App\Modules\Signal\Support\SignalRuleForm::class)->taskTemplateOptions(),
        ]);
    }

    public function update(SignalRule $rule, Request $request): RedirectResponse
    {
        $data = app(\App\Modules\Signal\Support\SignalRuleForm::class)->validated($request);
        $rule->forceFill([
            ...$data,
            'updated_by' => $request->user()?->id,
        ])->save();

        return redirect()->route('tech.admin.system.signals.rules.show', $rule)->with('status', 'Signal rule updated.');
    }
}
