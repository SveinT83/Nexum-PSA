<?php

namespace App\Modules\Email\Controllers\Admin;

use App\Modules\Email\Actions\BuildEmailSmartInboxRulePrefill;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailRemoteOperation;
use App\Modules\Email\Models\EmailRule;
use App\Modules\Email\Models\EmailRuleExecutionAttempt;
use App\Modules\Email\Models\EmailRuleReprocessRun;
use App\Modules\Email\Services\EmailRuleDraftService;
use App\Modules\Email\Services\EmailRulePublisher;
use App\Modules\Email\Services\EmailRuleReprocessService;
use App\Modules\Email\Services\EmailRuleReversalService;
use App\Modules\Email\Services\MailboxAccess;
use App\Modules\Taxonomy\Models\Category;
use App\Modules\Taxonomy\Models\Tag;
use App\Modules\Ticket\Models\TicketQueue;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RulesController extends Controller
{
    public function index(Request $request): View
    {
        $rules = Schema::hasTable('email_rules')
            ? EmailRule::query()->adminManaged()->with(['accounts', 'publishedVersion', 'draft'])->orderBy('weight')->orderBy('id')->get()
            : collect();

        $previewId = $request->session()->get('email_rule_reprocess_preview');
        $reprocessPreview = is_string($previewId)
            ? EmailRuleReprocessRun::query()->with(['rule', 'items'])->where('public_id', $previewId)->first()
            : null;

        return view('email::Admin.Rules.index', [
            'rules' => $rules,
            'systemRules' => $this->systemRules(),
            'missingTable' => ! Schema::hasTable('email_rules'),
            'reprocessPreview' => $reprocessPreview,
        ]);
    }

    public function create(
        Request $request,
        BuildEmailSmartInboxRulePrefill $smartInboxRulePrefill,
    ): View {
        $trustedPrefill = null;

        if ($request->query->has(BuildEmailSmartInboxRulePrefill::ADMIN_PREFILL_TOKEN_QUERY)) {
            abort_unless($request->user(), 404);
            $trustedPrefill = $smartInboxRulePrefill->consumeAdminPrefill(
                $request->query(BuildEmailSmartInboxRulePrefill::ADMIN_PREFILL_TOKEN_QUERY),
                $request->user(),
            );
            abort_if($trustedPrefill === null, 404);
        }

        $prefill = $this->prefillFromMailWorkspace($request, $trustedPrefill);

        return view('email::Admin.Rules.create', [
            'rule' => new EmailRule([
                'name' => $prefill['name'],
                'trigger' => EmailRule::TRIGGER_INBOUND,
                'routing_phase' => EmailRule::ROUTING_PHASE_NORMAL,
                'weight' => 10,
                'is_active' => $prefill['is_active'],
                'lifecycle_status' => EmailRule::LIFECYCLE_DRAFT,
                'stop_processing' => $prefill['stop_processing'],
                'conditions_json' => $prefill['conditions'],
                'actions_json' => $prefill['actions'],
            ]),
            'mode' => 'create',
            'tags' => $this->tags(),
            'emailCategories' => $this->emailCategories(),
            'accounts' => $this->ruleAccounts(),
            'providerFolders' => $this->providerFolders(),
            'selectedAccountIds' => $prefill['account_ids'],
            'draftPayload' => null,
            'draftLockVersion' => null,
            'publicationPreview' => null,
        ]);
    }

    public function store(
        Request $request,
        EmailRuleDraftService $drafts,
        EmailRulePublisher $publisher,
    ): RedirectResponse {
        $data = $this->validatedRule($request);
        $accountIds = $data['account_ids'];
        unset($data['account_ids']);
        $intent = $request->input('intent', 'save_draft');
        if ($intent === 'publish') {
            $this->authorizePublish($request);
        }

        $rule = EmailRule::create([
            'name' => $data['name'],
            'description' => null,
            'trigger' => EmailRule::TRIGGER_INBOUND,
            'routing_phase' => EmailRule::ROUTING_PHASE_NORMAL,
            'rule_kind' => EmailRule::KIND_ADMIN,
            'owner_id' => null,
            'weight' => 10,
            'is_active' => false,
            'lifecycle_status' => EmailRule::LIFECYCLE_DRAFT,
            'stop_processing' => false,
            'conditions_json' => [],
            'actions_json' => [],
            'created_by' => $request->user()?->id,
            'updated_by' => $request->user()?->id,
        ]);
        $draft = $drafts->save($rule, $data + ['account_ids' => $accountIds], $request->user());

        if ($intent === 'publish') {
            $publisher->publishDraft($rule, $request->user(), $draft->checksum);

            return redirect()->route('tech.admin.settings.email.rules')
                ->with('success', 'Email rule published.');
        }

        return redirect()->route('tech.admin.settings.email.rules.edit', $rule)
            ->with('success', 'Email rule draft saved. The published rule was not changed.');
    }

    public function edit(EmailRule $rule, EmailRuleDraftService $drafts): View
    {
        abort_unless($rule->isAdminManaged(), 404);

        $rule->load(['accounts', 'draft', 'publishedVersion']);

        return view('email::Admin.Rules.create', [
            'rule' => $rule,
            'mode' => 'edit',
            'tags' => $this->tags(),
            'emailCategories' => $this->emailCategories(),
            'accounts' => $this->ruleAccounts(),
            'providerFolders' => $this->providerFolders(),
            'draftPayload' => $rule->draft?->payload_json,
            'draftLockVersion' => $rule->draft?->lock_version,
            'publicationPreview' => $rule->draft ? $drafts->publicationPreview($rule) : null,
        ]);
    }

    public function update(
        Request $request,
        EmailRule $rule,
        EmailRuleDraftService $drafts,
        EmailRulePublisher $publisher,
    ): RedirectResponse {
        abort_unless($rule->isAdminManaged(), 404);

        $data = $this->validatedRule($request);
        $accountIds = $data['account_ids'];
        $intent = $request->input('intent', 'save_draft');
        if ($intent === 'publish') {
            $this->authorizePublish($request);
        }
        $draft = $drafts->save(
            $rule,
            $data,
            $request->user(),
            $request->integer('draft_lock_version') ?: null,
        );

        if ($intent === 'publish') {
            $publisher->publishDraft($rule, $request->user(), $draft->checksum);

            return redirect()->route('tech.admin.settings.email.rules')
                ->with('success', 'Email rule published as a new immutable version.');
        }

        return redirect()->route('tech.admin.settings.email.rules.edit', $rule)
            ->with('success', 'Email rule draft saved. The published rule is still active unchanged.');
    }

    public function toggle(
        Request $request,
        EmailRule $rule,
        EmailRuleDraftService $drafts,
        EmailRulePublisher $publisher,
    ): RedirectResponse {
        abort_unless($rule->isAdminManaged(), 404);
        $this->authorizePublish($request);
        if ($rule->draft()->exists()) {
            return back()->with('error', 'Publish or discard the saved draft before changing rule status.');
        }
        $payload = $this->payloadFromPublishedRule($rule);
        $payload['is_active'] = ! $payload['is_active'];
        $draft = $drafts->save($rule, $payload, $request->user(), $rule->draft?->lock_version);
        $publisher->publishDraft($rule, $request->user(), $draft->checksum);

        return back()->with('success', 'Email rule status updated.');
    }

    public function destroy(Request $request, EmailRule $rule): RedirectResponse
    {
        abort_unless($rule->isAdminManaged(), 404);
        $this->authorizePublish($request);

        $rule->delete();

        return back()->with('success', 'Email rule deleted.');
    }

    public function reprocessPreview(
        Request $request,
        EmailRule $rule,
        EmailRuleReprocessService $runs,
    ): RedirectResponse {
        $this->authorizeReprocess($request);
        abort_unless($rule->isAdminManaged(), 404);
        $data = $request->validate([
            'email_message_id' => ['required', 'integer', 'exists:email_messages,id'],
        ]);
        $message = EmailMessage::query()->findOrFail((int) $data['email_message_id']);
        $run = $runs->preview($rule, $request->user(), [
            'account_id' => $message->account_id,
            'message_ids' => [$message->id],
            'cap' => 1,
        ]);

        return back()->with('email_rule_reprocess_preview', $run->public_id)
            ->with('success', 'Preview created. No rule actions have run.');
    }

    public function applyReprocess(
        Request $request,
        EmailRuleReprocessRun $run,
        EmailRuleReprocessService $runs,
    ): RedirectResponse {
        $this->authorizeReprocess($request);
        $runs->apply($run, $request->user());

        return back()->with('success', 'The verified reprocess run was queued on email-rules.');
    }

    public function undoExecution(
        Request $request,
        EmailRuleExecutionAttempt $attempt,
        EmailRuleReversalService $reversalService,
        MailboxAccess $mailboxAccess,
    ): RedirectResponse {
        abort_unless($request->user()?->can('email.rule_manage'), 403);

        $attempt->loadMissing(['rule', 'version', 'message.account', 'placement.account']);
        $account = $attempt->placement?->account ?? $attempt->message?->account;
        $ruleKind = $attempt->version?->rule_kind ?? $attempt->rule?->rule_kind;
        abort_if(
            $ruleKind !== EmailRule::KIND_ADMIN
            || ! $account
            || ! $mailboxAccess->canAccessAccount($request->user(), $account, MailboxAccess::VIEW),
            404,
        );

        try {
            $inverse = $reversalService->revert($attempt, $request->user());

            return match ($inverse->status) {
                EmailRemoteOperation::STATUS_SUCCEEDED => back()->with(
                    'success',
                    'The rule provider effect was undone through a verified inverse operation.',
                ),
                EmailRemoteOperation::STATUS_PENDING,
                EmailRemoteOperation::STATUS_RUNNING => back()->with(
                    'success',
                    'Rule Undo was recorded and is waiting for provider acknowledgement.',
                ),
                EmailRemoteOperation::STATUS_SUPERSEDED => back()->with(
                    'error',
                    'Rule Undo stopped safely because mailbox or provider state changed.',
                ),
                default => back()->with(
                    'error',
                    'Rule Undo is recorded but requires safe mailbox-operation recovery.',
                ),
            };
        } catch (AuthorizationException) {
            return back()->with('error', 'Current rule-management and Mailbox Organize access is required.');
        } catch (ValidationException $exception) {
            return back()->with(
                'error',
                (string) (collect($exception->errors())->flatten()->first()
                    ?: 'This rule execution cannot be undone safely.'),
            );
        } catch (\Throwable) {
            return back()->with('error', 'Rule Undo could not be started safely. No success was reported.');
        }
    }

    private function validatedRule(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'weight' => 'required|integer|min:0|max:100000',
            'account_ids' => 'required|array|min:1',
            'account_ids.*' => 'integer|exists:email_accounts,id',
            'routing_phase' => 'nullable|string|in:normal,preclassification',
            'is_active' => 'nullable|boolean',
            'stop_processing' => 'nullable|boolean',
            'condition_match' => 'nullable|string|in:all,any',
            'conditions' => 'required|array|min:1',
            'conditions.*.field' => 'required|string|in:from,from_domain,to,cc,subject,body,message_id,is_reply,has_ticket_key',
            'conditions.*.operator' => 'required|string|in:contains,equals,not_equals,starts_with,ends_with,regex,present',
            'conditions.*.value' => 'nullable|string|max:1000',
            'conditions.*.group' => 'nullable|string|max:80',
            'conditions.*.group_match' => 'nullable|string|in:all,any',
            'actions' => 'required|array|min:1',
            'actions.*.type' => 'required|string|in:link_ticket_by_subject_token,create_ticket,archive,tag,tag_message,tag_conversation,set_conversation_category,emit_signal,provider_archive,provider_move',
            'actions.*.value' => 'nullable|string|max:255',
            'actions.*.target_folder_id' => 'nullable|integer|exists:email_folders,id',
            'actions.*.severity' => 'nullable|string|in:info,warning,error,critical',
            'actions.*.confidence' => 'nullable|integer|min:0|max:100',
            'actions.*.summary' => 'nullable|string|max:255',
            'actions.*.payload_note' => 'nullable|string|max:1000',
        ]);

        $accountIds = $this->eligibleRuleAccountIds($data['account_ids']);
        $actions = collect($data['actions'])
            ->map(function (array $action): array {
                $type = $action['type'];
                $value = $type === 'emit_signal'
                    ? $this->normalizeSignalType($action['value'] ?? '')
                    : trim((string) ($action['value'] ?? ''));

                if ($type === 'set_conversation_category') {
                    $value = (string) $this->resolveEmailCategory($value)->id;
                }

                $mapped = [
                    'type' => $type,
                    'value' => $value,
                ];

                if (in_array($type, [
                    BuildEmailSmartInboxRulePrefill::ADMIN_ACTION_PROVIDER_ARCHIVE,
                    BuildEmailSmartInboxRulePrefill::ADMIN_ACTION_PROVIDER_MOVE,
                ], true)) {
                    $mapped['target_folder_id'] = (int) ($action['target_folder_id'] ?? $action['value'] ?? 0);
                }

                if ($type === 'emit_signal') {
                    $mapped['signal_type'] = $mapped['value'];
                    foreach (['severity', 'confidence', 'summary', 'payload_note'] as $field) {
                        if (array_key_exists($field, $action) && filled($action[$field])) {
                            $mapped[$field] = $action[$field];
                        }
                    }
                }

                return $mapped;
            })
            ->values()
            ->all();

        $actions = $this->normalizeProviderActionTargets($actions, $accountIds);
        $this->ensureActionTargetsExist($actions);

        return [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'weight' => $data['weight'],
            'routing_phase' => $data['routing_phase'] ?? EmailRule::ROUTING_PHASE_NORMAL,
            'is_active' => (bool) ($data['is_active'] ?? false),
            'stop_processing' => (bool) ($data['stop_processing'] ?? false),
            'conditions_json' => $this->groupedConditions($data),
            'actions_json' => $actions,
            'account_ids' => $accountIds,
        ];
    }

    private function authorizePublish(Request $request): void
    {
        abort_unless($request->user()?->isActive()
            && $request->user()?->can('email.rule_manage')
            && $request->user()?->can('email.rule_publish'), 403);
    }

    private function authorizeReprocess(Request $request): void
    {
        abort_unless($request->user()?->isActive()
            && $request->user()?->can('email.rule_manage')
            && $request->user()?->can('email.rule_reprocess'), 403);
    }

    /** @return array<string, mixed> */
    private function payloadFromPublishedRule(EmailRule $rule): array
    {
        $rule->loadMissing(['accounts', 'publishedVersion', 'draft']);
        $version = $rule->publishedVersion;

        return [
            'name' => $version?->name ?? $rule->name,
            'description' => $version?->description ?? $rule->description,
            'weight' => $version?->weight ?? $rule->weight,
            'routing_phase' => $version?->routing_phase ?? $rule->routing_phase,
            'is_active' => (bool) ($version?->is_active ?? $rule->is_active),
            'stop_processing' => (bool) ($version?->stop_processing ?? $rule->stop_processing),
            'conditions_json' => $version?->conditions_json ?? $rule->conditions_json ?? [],
            'actions_json' => $version?->actions_json ?? $rule->actions_json ?? [],
            'account_ids' => $version?->account_ids_json ?? $rule->accounts->pluck('id')->all(),
        ];
    }

    private function groupedConditions(array $data): array
    {
        $rows = collect($data['conditions'])
            ->map(fn (array $condition): array => [
                'field' => $condition['field'],
                'operator' => $condition['operator'],
                'value' => $condition['value'] ?? '',
                'group' => trim((string) ($condition['group'] ?? '')),
                'group_match' => ($condition['group_match'] ?? 'all') === 'any' ? 'any' : 'all',
            ])
            ->values();

        $groups = $rows
            ->groupBy(fn (array $condition): string => $condition['group'] !== '' ? $condition['group'] : 'Default')
            ->map(function ($conditions, string $name): array {
                $first = $conditions->first();

                return [
                    'name' => $name,
                    'match' => ($first['group_match'] ?? 'all') === 'any' ? 'any' : 'all',
                    'conditions' => $conditions
                        ->map(fn (array $condition): array => [
                            'field' => $condition['field'],
                            'operator' => $condition['operator'],
                            'value' => $condition['value'],
                        ])
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();

        return [
            'match' => ($data['condition_match'] ?? 'all') === 'any' ? 'any' : 'all',
            'groups' => $groups,
        ];
    }

    private function eligibleRuleAccountIds(array $accountIds): array
    {
        $eligibleIds = $this->ruleAccounts()
            ->whereIn('id', $accountIds)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();

        if ($eligibleIds === []) {
            throw ValidationException::withMessages([
                'account_ids' => 'Choose at least one shared or system mailbox with Ticket ingress enabled.',
            ]);
        }

        return $eligibleIds;
    }

    private function ensureActionTargetsExist(array $actions): void
    {
        foreach ($actions as $action) {
            $type = $action['type'] ?? '';
            $value = trim((string) ($action['value'] ?? ''));

            if ($type === 'create_ticket' && $value !== '') {
                // Optional create-ticket values target a queue by id or slug before the rule is saved.
                $queueExists = TicketQueue::query()
                    ->whereKey($value)
                    ->orWhere('slug', $value)
                    ->exists();

                if (! $queueExists) {
                    throw ValidationException::withMessages([
                        'actions' => 'Create ticket action queue target does not exist.',
                    ]);
                }
            }

            if ($type === 'emit_signal' && $this->normalizeSignalType($value) === '') {
                throw ValidationException::withMessages([
                    'actions' => 'Emit Signal action requires a signal type.',
                ]);
            }

            if (in_array($type, ['tag_conversation', 'set_conversation_category'], true) && $value === '') {
                throw ValidationException::withMessages([
                    'actions' => 'Conversation classification actions require an existing target.',
                ]);
            }

            if (! in_array($type, ['tag', 'tag_message', 'tag_conversation'], true) || $value === '') {
                continue;
            }

            $slug = Str::slug($value);
            $existing = Tag::withTrashed()
                ->where(function ($tags) use ($value, $slug): void {
                    $tags->where('name', $value)->orWhere('slug', $slug);
                })
                ->first();

            if ($existing?->trashed() || ($existing && ! $existing->active)) {
                throw ValidationException::withMessages([
                    'actions' => 'Rule tag targets must be active Taxonomy tags.',
                ]);
            }

            // A draft is definition-only. New taxonomy targets are created
            // inside the separately authorized publication transaction.
        }
    }

    /**
     * Provider-authoritative cleanup rules are intentionally limited to one
     * mailbox because provider folder IDs never cross account boundaries.
     *
     * @param  array<int, array<string, mixed>>  $actions
     * @param  array<int, int>  $accountIds
     * @return array<int, array<string, mixed>>
     */
    private function normalizeProviderActionTargets(array $actions, array $accountIds): array
    {
        return collect($actions)
            ->map(function (array $action) use ($accountIds): array {
                $type = (string) ($action['type'] ?? '');
                if (! in_array($type, [
                    BuildEmailSmartInboxRulePrefill::ADMIN_ACTION_PROVIDER_ARCHIVE,
                    BuildEmailSmartInboxRulePrefill::ADMIN_ACTION_PROVIDER_MOVE,
                ], true)) {
                    return $action;
                }

                if (count($accountIds) !== 1) {
                    throw ValidationException::withMessages([
                        'actions' => 'Provider Archive and Move rules must target exactly one mailbox.',
                    ]);
                }

                $folder = EmailFolder::query()
                    ->whereKey((int) ($action['target_folder_id'] ?? 0))
                    ->where('account_id', $accountIds[0])
                    ->where('is_selectable', true)
                    ->where('sync_enabled', true)
                    ->when(
                        $type === BuildEmailSmartInboxRulePrefill::ADMIN_ACTION_PROVIDER_ARCHIVE,
                        fn ($folders) => $folders->where('role', EmailFolder::ROLE_ARCHIVE),
                    )
                    ->first();

                if (! $folder) {
                    throw ValidationException::withMessages([
                        'actions' => 'Choose an active provider folder from the selected mailbox.',
                    ]);
                }

                $action['target_folder_id'] = (int) $folder->id;
                $action['value'] = (string) $folder->path;

                return $action;
            })
            ->values()
            ->all();
    }

    private function tags()
    {
        return Tag::query()
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    private function emailCategories()
    {
        return Category::query()
            ->where('type', Category::TYPE_EMAIL)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);
    }

    private function resolveEmailCategory(string $value): Category
    {
        $category = Category::query()
            ->where('type', Category::TYPE_EMAIL)
            ->where('is_active', true)
            ->where(function ($query) use ($value): void {
                $query->where('id', ctype_digit($value) ? (int) $value : 0)
                    ->orWhere('slug', $value)
                    ->orWhere('name', $value);
            })
            ->first();

        if (! $category) {
            throw ValidationException::withMessages([
                'actions' => 'Set conversation category requires an active Email category.',
            ]);
        }

        return $category;
    }

    private function ruleAccounts()
    {
        return EmailAccount::query()
            ->where('account_kind', '!=', EmailAccount::KIND_PERSONAL)
            ->where('ticket_ingress_enabled', true)
            ->orderBy('address')
            ->get(['id', 'address', 'account_kind']);
    }

    private function providerFolders()
    {
        return EmailFolder::query()
            ->whereIn('account_id', $this->ruleAccounts()->pluck('id'))
            ->where('is_selectable', true)
            ->where('sync_enabled', true)
            ->with('account:id,address')
            ->orderBy('account_id')
            ->orderBy('name')
            ->get(['id', 'account_id', 'name', 'path', 'role']);
    }

    /**
     * @return array{name: string, conditions: array<int, array{field: string, operator: string, value: string}>, actions: array<int, array<string, mixed>>, account_ids: array<int>, is_active: bool, stop_processing: bool}
     */
    private function prefillFromMailWorkspace(Request $request, ?array $trustedPrefill = null): array
    {
        // Existing non-Smart Mail workspace links remain compatible. Smart
        // Inbox uses a one-use server-side token so sender/subject data never
        // appears in browser history, access logs, or referrer URLs.
        $input = $trustedPrefill ?? $request->query();
        $accountId = (int) ($input['account_id'] ?? 0);
        $eligibleAccountIds = $accountId > 0
            ? $this->ruleAccounts()
                ->where('id', $accountId)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->values()
                ->all()
            : [];

        $field = (string) ($input['condition_field'] ?? 'subject');
        if (! in_array($field, ['from', 'from_domain', 'subject'], true)) {
            $field = 'subject';
        }

        $value = Str::limit(trim((string) ($input['condition_value'] ?? '')), 1000, '');
        $operator = $field === 'subject' ? 'contains' : 'equals';
        $name = Str::limit(trim((string) ($input['name'] ?? '')), 255, '');

        if ($name === '') {
            $name = 'Mailbox rule from Mail workspace';
        }

        $actions = [['type' => 'tag_message', 'value' => '']];
        $actionType = trim((string) ($input['action_type'] ?? ''));
        $targetFolderId = (int) ($input['target_folder_id'] ?? 0);

        if (count($eligibleAccountIds) === 1
            && in_array($actionType, [
                BuildEmailSmartInboxRulePrefill::ADMIN_ACTION_PROVIDER_ARCHIVE,
                BuildEmailSmartInboxRulePrefill::ADMIN_ACTION_PROVIDER_MOVE,
            ], true)) {
            $folder = EmailFolder::query()
                ->whereKey($targetFolderId)
                ->where('account_id', $eligibleAccountIds[0])
                ->where('is_selectable', true)
                ->where('sync_enabled', true)
                ->when(
                    $actionType === BuildEmailSmartInboxRulePrefill::ADMIN_ACTION_PROVIDER_ARCHIVE,
                    fn ($folders) => $folders->where('role', EmailFolder::ROLE_ARCHIVE),
                )
                ->first();

            if ($folder) {
                $actions = [[
                    'type' => $actionType,
                    'value' => $folder->path,
                    'target_folder_id' => (int) $folder->id,
                ]];
            }
        }

        return [
            'name' => $name,
            'conditions' => [[
                'field' => $field,
                'operator' => $operator,
                'value' => $value,
            ]],
            'actions' => $actions,
            'account_ids' => $eligibleAccountIds,
            'is_active' => $actions[0]['type'] === 'tag_message',
            'stop_processing' => $actions[0]['type'] !== 'tag_message',
        ];
    }

    private function normalizeSignalType(mixed $value): string
    {
        return str((string) $value)
            ->trim()
            ->lower()
            ->replace([' ', '-'], '_')
            ->toString();
    }

    private function systemRules(): array
    {
        return [[
            'name' => 'Link inbound reply to ticket by subject token',
            'trigger' => EmailRule::TRIGGER_INBOUND,
            'condition' => 'Subject contains ticket key like TD-2026-000004',
            'action' => 'Create public customer reply and mark ticket unread',
            'status' => 'Active',
        ], [
            'name' => 'Create ticket from routed inbound email',
            'trigger' => EmailRule::TRIGGER_INBOUND,
            'condition' => 'Custom rule matches mailbox, recipient, sender, or subject',
            'action' => 'Create ticket, attach the email as the first public customer message, and mark it unread',
            'status' => 'Configurable',
        ]];
    }
}
