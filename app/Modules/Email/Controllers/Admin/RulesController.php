<?php

namespace App\Modules\Email\Controllers\Admin;

use App\Modules\Email\Models\EmailRule;
use App\Modules\Taxonomy\Models\Tag;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\View\View;

class RulesController extends Controller
{
    public function index(): View
    {
        $rules = Schema::hasTable('email_rules')
            ? EmailRule::query()->orderBy('weight')->orderBy('id')->get()
            : collect();

        return view('email::Admin.Rules.index', [
            'rules' => $rules,
            'systemRules' => $this->systemRules(),
            'missingTable' => ! Schema::hasTable('email_rules'),
        ]);
    }

    public function create(): View
    {
        return view('email::Admin.Rules.create', [
            'rule' => new EmailRule([
                'trigger' => EmailRule::TRIGGER_INBOUND,
                'weight' => 10,
                'is_active' => true,
                'stop_processing' => false,
                'conditions_json' => [['field' => 'subject', 'operator' => 'contains', 'value' => '']],
                'actions_json' => [['type' => 'tag', 'value' => '']],
            ]),
            'mode' => 'create',
            'tags' => $this->tags(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatedRule($request);

        EmailRule::create($data + [
            'trigger' => EmailRule::TRIGGER_INBOUND,
            'created_by' => $request->user()?->id,
            'updated_by' => $request->user()?->id,
        ]);

        return redirect()->route('tech.admin.settings.email.rules')
            ->with('success', 'Email rule created.');
    }

    public function edit(EmailRule $rule): View
    {
        return view('email::Admin.Rules.create', [
            'rule' => $rule,
            'mode' => 'edit',
            'tags' => $this->tags(),
        ]);
    }

    public function update(Request $request, EmailRule $rule): RedirectResponse
    {
        $rule->update($this->validatedRule($request) + [
            'updated_by' => $request->user()?->id,
        ]);

        return redirect()->route('tech.admin.settings.email.rules')
            ->with('success', 'Email rule updated.');
    }

    public function toggle(EmailRule $rule): RedirectResponse
    {
        $rule->forceFill(['is_active' => ! $rule->is_active])->save();

        return back()->with('success', 'Email rule status updated.');
    }

    public function destroy(EmailRule $rule): RedirectResponse
    {
        $rule->delete();

        return back()->with('success', 'Email rule deleted.');
    }

    private function validatedRule(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'weight' => 'required|integer|min:0|max:100000',
            'is_active' => 'nullable|boolean',
            'stop_processing' => 'nullable|boolean',
            'conditions' => 'required|array|min:1',
            'conditions.*.field' => 'required|string|in:from,from_domain,to,cc,subject,body,message_id,is_reply,has_ticket_key',
            'conditions.*.operator' => 'required|string|in:contains,equals,not_equals,starts_with,ends_with,regex,present',
            'conditions.*.value' => 'nullable|string|max:1000',
            'actions' => 'required|array|min:1',
            'actions.*.type' => 'required|string|in:link_ticket_by_subject_token,archive,tag',
            'actions.*.value' => 'nullable|string|max:255',
        ]);

        $actions = collect($data['actions'])
            ->map(fn (array $action) => [
                'type' => $action['type'],
                'value' => $action['value'] ?? '',
            ])
            ->values()
            ->all();

        $this->ensureActionTagsExist($actions);

        return [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'weight' => $data['weight'],
            'is_active' => (bool) ($data['is_active'] ?? false),
            'stop_processing' => (bool) ($data['stop_processing'] ?? false),
            'conditions_json' => collect($data['conditions'])
                ->map(fn (array $condition) => [
                    'field' => $condition['field'],
                    'operator' => $condition['operator'],
                    'value' => $condition['value'] ?? '',
                ])
                ->values()
                ->all(),
            'actions_json' => $actions,
        ];
    }

    private function ensureActionTagsExist(array $actions): void
    {
        foreach ($actions as $action) {
            if (($action['type'] ?? '') !== 'tag' || trim((string) ($action['value'] ?? '')) === '') {
                continue;
            }

            $name = trim((string) $action['value']);

            Tag::firstOrCreate(
                ['name' => $name],
                [
                    'slug' => Str::slug($name),
                    'color' => '#6c757d',
                    'active' => true,
                ]
            );
        }
    }

    private function tags()
    {
        return Tag::query()
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    private function systemRules(): array
    {
        return [[
            'name' => 'Link inbound reply to ticket by subject token',
            'trigger' => EmailRule::TRIGGER_INBOUND,
            'condition' => 'Subject contains ticket key like TD-2026-000004',
            'action' => 'Create public customer reply and mark ticket unread',
            'status' => 'Active',
        ]];
    }
}
