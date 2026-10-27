<?php

namespace App\Modules\Signal\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Clients\Client;
use App\Models\Core\User;
use App\Modules\Contact\Models\Contact;
use App\Modules\CustomerPortal\Models\CustomerPortalMembership;
use App\Modules\Signal\Models\Signal;
use App\Modules\Signal\Models\SignalRule;
use App\Modules\Signal\Models\SignalRuleExecution;
use App\Modules\Signal\Queries\RelatedSignals;
use App\Modules\Signal\Queries\SignalFeed;
use App\Modules\Signal\Resources\Api\V1\SignalApiView as View;
use App\Modules\Signal\Support\SignalApiAccess as Access;
use App\Modules\Signal\Support\SignalApiInput as Input;
use App\Modules\Signal\Support\SignalRuleDefinition;
use App\Modules\Signal\Support\SignalRuleForm;
use App\Modules\Signal\Support\SignalSettings;
use App\Modules\Task\Models\TaskTemplateGroup;
use Illuminate\Http\Request;

class SignalWorkspaceController extends Controller
{
    public function index(Request $request, SignalFeed $feed)
    {
        return response()->json(View::page($feed->query($request)->paginate(Input::page($request)), View::signal(...)));
    }

    public function context()
    {
        return response()->json(['data' => [
            'ranges' => ['7', '30', '90', 'custom', 'all'], 'default_range' => '30',
            'sorts' => ['signal_type', 'source_domain', 'severity', 'confidence', 'status', 'executions_count', 'occurred_at'],
            'directions' => ['asc', 'desc'], 'default_sort' => 'occurred_at', 'default_direction' => 'desc',
            'default_per_page' => 50, 'maximum_per_page' => 100,
            'sources' => Signal::distinct()->orderBy('source_domain')->pluck('source_domain')->all(),
            'types' => Signal::distinct()->orderBy('signal_type')->pluck('signal_type')->all(),
            'severities' => Signal::distinct()->orderBy('severity')->pluck('severity')->all(),
            'statuses' => Signal::distinct()->orderBy('status')->pluck('status')->all(),
            'external_execution_enabled' => (bool) config('signal.api_external_execution_enabled', false),
        ]]);
    }

    public function show(Signal $signal)
    {
        return response()->json(['data' => View::signal($signal)]);
    }

    public function executions(Request $request, Signal $signal)
    {
        return response()->json(View::page($signal->executions()->with('rule')->orderByDesc('executed_at')->orderByDesc('id')
            ->paginate(Input::page($request)), View::execution(...)));
    }

    public function execution(Signal $signal, SignalRuleExecution $execution)
    {
        abort_unless($execution->signal_id === $signal->id, 404);

        return response()->json(['data' => View::execution($execution)]);
    }

    public function rules(Request $request)
    {
        return response()->json(View::page(SignalRule::withCount('executions')->orderBy('priority')->orderBy('id')
            ->paginate(Input::page($request)), View::rule(...)));
    }

    public function rule(SignalRule $rule, Request $request)
    {
        return response()->json(['data' => View::rule($rule),
            'executions' => View::page($rule->executions()->orderByDesc('id')->paginate(Input::page($request)), View::execution(...))]);
    }

    public function ruleContext(Request $request, SignalRuleForm $form)
    {
        $request->validate(['source_domain' => 'sometimes|string|max:80', 'intake_form_id' => 'sometimes|integer|exists:intake_forms,id']);
        // The browser Signal rule form uses signal.rule.manage for this same prefill.
        $rule = $form->newRuleFromRequest($request);

        return response()->json(['data' => [
            'defaults' => $rule->only(['name', 'description', 'is_active', 'priority', 'stop_processing', 'conditions', 'actions']),
            'condition_fields' => SignalRuleDefinition::BUILDER_CONDITION_FIELDS,
            'operators' => SignalRuleDefinition::CONDITION_OPERATORS,
            'action_types' => SignalRuleDefinition::ACTION_TYPES,
            'action_optional_fields' => SignalRuleDefinition::ACTION_OPTIONAL_FIELDS,
            'legacy_condition_fields' => SignalRuleDefinition::CONDITION_FIELDS,
            'portal_roles' => CustomerPortalMembership::roleOptions(),
            'lookups' => ['actors', 'task-templates'],
        ]]);
    }

    public function lookups(Request $request, string $kind)
    {
        $request->validate(['q' => 'sometimes|nullable|string|max:255']);
        $query = match ($kind) {
            'actors' => User::where('status', User::STATUS_ACTIVE)->select(['id', 'name']),
            'task-templates' => TaskTemplateGroup::where('is_active', true)->select(['id', 'name']),
            default => abort(404),
        };
        if ($q = trim((string) $request->query('q'))) {
            $query->where('name', 'like', '%'.$q.'%');
        }

        return response()->json(View::page($query->orderBy('name')->orderBy('id')->paginate(Input::page($request)), fn ($row) => $row->toArray()));
    }

    public function relatedClient(Request $request, Client $client, RelatedSignals $related)
    {
        return response()->json(['data' => $related->forClient($client, Input::page($request))->map(View::signal(...))->all()]);
    }

    public function relatedContact(Request $request, Contact $contact, RelatedSignals $related)
    {
        return response()->json(['data' => $related->forContact($contact, Input::page($request))->map(View::signal(...))->all()]);
    }

    public function operation(Request $request, string $operation)
    {
        $command = \App\Modules\Signal\Models\SignalApiCommand::whereKey($operation)->where('actor_id', $request->user()->id)
            ->where('token_hash', Access::tokenHash($request))->firstOrFail();
        Access::authorizeReceipt($request, $command);

        return response()->json(['data' => ['id' => $command->id, 'operation' => $command->operation,
            'state' => $command->state, 'status' => $command->result['status'],
            'result' => $command->result['body'], 'revision' => $command->result['etag'],
            'reconciliation_required' => $command->state === 'unknown', 'created_at' => $command->created_at?->toISOString()]]);
    }

    public function storeRule(Request $request, SignalRuleForm $form)
    {
        $data = $form->validated($request);
        $this->validateRuleActors($request, $data['actions']);
        $rule = SignalRule::create([...$data, 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);

        return response()->json(['data' => View::rule($rule)], 201);
    }

    public function updateRule(Request $request, SignalRule $rule, SignalRuleForm $form)
    {
        $data = $form->validated($request);
        $this->validateRuleActors($request, $data['actions']);
        $rule->forceFill([...$data, 'updated_by' => $request->user()->id])->save();

        return response()->json(['data' => View::rule($rule)]);
    }

    /** Configurable automation ownership is not permission to impersonate another operator. */
    private function validateRuleActors(Request $request, array $actions): void
    {
        foreach ($actions as $action) {
            foreach (['actor_id', 'creator_id'] as $field) {
                abort_if(filled($action[$field] ?? null) && (int) $action[$field] !== (int) $request->user()->id,
                    403, 'API rule actions must use the current actor.');
            }
            if (($action['type'] ?? '') === 'webhook') {
                Access::requirePermissions($request, ['signal.webhook.manage']);
            }
        }
    }

    public function settings(\App\Modules\Signal\Support\SignalSettingsForm $form, SignalSettings $settings)
    {
        $agent = $form->signalAgent();

        return response()->json(['data' => [
            'settings' => $settings->get(),
            'agent' => $agent ? ['id' => $agent->id, 'name' => $agent->name, 'is_active' => (bool) $agent->is_active,
                'provider_name' => $agent->provider?->name, 'can_execute_actions' => false] : null,
        ]]);
    }

    public function updateSettings(Request $request, \App\Modules\Signal\Support\SignalSettingsForm $form, SignalSettings $settings)
    {
        $settings->update($form->validated($request));

        return $this->settings($form, $settings);
    }

    public function retryPreview(Request $request, Signal $signal, SignalRuleExecution $execution)
    {
        abort_unless($execution->signal_id === $signal->id, 404);
        $request->validate(['mode' => 'required|in:failed,all']);
        $root = $execution->rootExecution()->loadMissing(['signal', 'rule', 'retries']);
        abort_unless($root->rule && $root->signal, 409, 'The execution source is no longer available.');
        $successful = collect([$root, ...$root->retries])->flatMap(function ($attempt) {
            return collect((array) $attempt->results)->values()->map(fn ($row, $index) => is_array($row) ? ['action_index' => $index, ...$row] : ['action_index' => $index]);
        })->filter(fn ($r) => in_array($r['status'] ?? null, ['done', 'queued', 'skipped'], true))->pluck('action_index')->unique()->all();
        $actions = [];
        foreach ((array) $root->actions as $index => $action) {
            if ($request->input('mode') === 'failed' && in_array($index, $successful, true)) {
                continue;
            }
            $actions[] = ['action_index' => $index, 'type' => $action['type'] ?? 'unknown',
                'action' => View::safe($action),
                'policy' => app(\App\Modules\Signal\Support\SignalApiExecutionPolicy::class)->inspect($request, $signal, $action)];
        }

        return response()->json(['data' => ['signal_id' => $signal->id, 'root_execution_id' => $root->id,
            'mode' => $request->input('mode'), 'actions' => $actions, 'has_work' => $actions !== [],
            'revision' => Input::etag($request)]]);
    }

    public function retry(Request $request, Signal $signal, SignalRuleExecution $execution, \App\Modules\Signal\Actions\ProcessSignalRules $rules)
    {
        abort_unless($execution->signal_id === $signal->id, 404);
        $request->validate(['mode' => 'required|in:failed,all']);
        // Current policy is rechecked in the shared action boundary, under the Signal lock.
        $attempt = $rules->retry($execution, $request->input('mode') === 'all');

        return response()->json(['data' => ['execution' => $attempt ? View::execution($attempt) : null,
            'has_work' => $attempt !== null, 'state' => $attempt ? $attempt->status : 'no_work']]);
    }
}
