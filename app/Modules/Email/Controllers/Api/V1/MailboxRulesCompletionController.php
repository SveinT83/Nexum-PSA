<?php

namespace App\Modules\Email\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Email\Actions\ApplyEmailSmartInboxSuggestionBatch;
use App\Modules\Email\Actions\BuildEmailSmartInboxRulePrefill;
use App\Modules\Email\Actions\DeleteEmailAdminRule;
use App\Modules\Email\Concerns\SerializesEmailRuleApi;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailRule;
use App\Modules\Email\Models\EmailRuleExecutionAttempt;
use App\Modules\Email\Models\EmailRuleReprocessRun;
use App\Modules\Email\Models\EmailSmartInboxSuggestion;
use App\Modules\Email\Services\EmailRuleFormReferences;
use App\Modules\Email\Services\MailboxAccess;
use App\Modules\Email\Support\EmailApiAccess;
use App\Modules\Integration\Support\DomainApiAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Complete browser rule/review outcomes without bypassing draft publication or source evidence. */
final class MailboxRulesCompletionController extends Controller
{
    use SerializesEmailRuleApi;

    public function context(Request $request, EmailApiAccess $access, EmailRuleFormReferences $references)
    {
        $access->actor($request);
        $this->authorizeRuleRead($request);

        return response()->json(['data' => ['tags' => $references->tags(),
            'categories' => $references->emailCategories(), 'accounts' => $references->ruleAccounts(),
            'folders' => $references->providerFolders()->map(fn ($folder) => $folder->only(['id', 'account_id', 'name', 'path', 'role']))]]);
    }

    public function placementContext(Request $request, EmailMailboxPlacement $placement, EmailApiAccess $access)
    {
        $placement = $access->placement($request, $placement);
        $account = $placement->account;
        $personal = $account->isPersonal() && (int) $account->owner_id === (int) $request->user()->id
            && app(MailboxAccess::class)->canAccessAccount($request->user(), $account, MailboxAccess::ORGANIZE)
            && ! $placement->provider_draft && $placement->folder?->role !== \App\Modules\Email\Models\EmailFolder::ROLE_DRAFTS;
        $targets = $personal ? \App\Modules\Email\Models\EmailFolder::query()
            ->where('account_id', $account->id)->where('is_selectable', true)
            ->whereKeyNot((int) $placement->email_folder_id)->orderBy('role')->orderBy('name')->get() : collect();
        $from = trim((string) $placement->message->from_email);
        $condition = ['field' => $from !== '' ? 'from' : 'subject',
            'value' => $from !== '' ? $from : trim((string) ($placement->message->subject ?: '(no subject)'))];
        $actions = [];
        if ($targets->isNotEmpty()) {
            $actions[] = 'move_to_folder';
        }
        if ($targets->contains('role', 'archive')) {
            $actions[] = 'archive';
        }

        return response()->json(['data' => [
            'source_placement_id' => $placement->id,
            'personal_rule' => ['available' => $personal && $actions !== [], 'condition_options' => ['from', 'from_domain', 'subject', 'to', 'cc'],
                'action_options' => $actions, 'prefill_condition' => $personal ? $condition : null,
                'target_folders' => $targets->map(fn ($folder) => $folder->only(['id', 'account_id', 'path', 'name', 'role']))],
            'admin_prefill' => ! $account->isPersonal() && $request->user()->can('email.rule_manage')
                ? ['account_id' => $account->id, 'condition' => $condition] : null,
        ]]);
    }

    public function destroy(Request $request, EmailRule $rule, EmailApiAccess $access, DeleteEmailAdminRule $delete)
    {
        $delete->handle($access->actor($request), $rule);

        return response()->json(['data' => ['id' => $rule->id, 'deleted' => true]]);
    }

    public function discardDraft(Request $request, EmailRule $rule, EmailApiAccess $access, DomainApiAudit $audit)
    {
        $actor = $access->actor($request);
        $this->authorizeRuleWrite($request);
        abort_unless($rule->isAdminManaged(), 404);
        $data = $request->validate(['lock_version' => ['required', 'integer', 'min:1']]);
        DB::transaction(function () use ($rule, $data, $actor, $audit): void {
            $draft = $rule->draft()->lockForUpdate()->firstOrFail();
            abort_unless((int) $draft->lock_version === (int) $data['lock_version'], 409);
            $audit->record($actor, 'email', 'email.rules.discard-draft', $rule,
                ['draft_lock_version' => $draft->lock_version], []);
            $draft->delete();
        });

        return response()->json(['data' => ['id' => $rule->id, 'draft_discarded' => true]]);
    }

    public function executions(Request $request, EmailMailboxPlacement $placement, EmailApiAccess $access)
    {
        $placement = $access->placement($request, $placement);
        // Mail shows this bounded outcome history to every current source reader.
        // Detailed admin execution/undo APIs retain their separate rule permissions.
        $rows = EmailRuleExecutionAttempt::query()->with('rule')
            ->where('email_message_id', $placement->email_message_id)
            ->where(fn ($query) => $query->where('email_mailbox_placement_id', $placement->id)->orWhereNull('email_mailbox_placement_id'))
            ->latest('started_at')->latest('id')->limit(8)->get()
            ->map(fn ($attempt) => ['id' => $attempt->id, 'status' => $attempt->status,
                'rule_id' => $attempt->rule_id, 'rule_name' => $attempt->rule?->name,
                'rule_kind' => $attempt->rule?->rule_kind, 'started_at' => $attempt->started_at,
                'finished_at' => $attempt->finished_at,
                'url' => $request->user()->can('email.rule_manage')
                    ? route('api.v1.email.rules.executions.show', $attempt) : null]);

        return response()->json(['data' => $rows->values()]);
    }

    public function runs(Request $request, EmailApiAccess $access)
    {
        $actor = $access->actor($request);
        $this->authorizeRuleRead($request);
        $runs = EmailRuleReprocessRun::query()->where('actor_id', $actor->id)->latest('id')->limit(100)->get();

        return response()->json(['data' => $runs->map(fn ($run) => $this->serializeRun($run))]);
    }

    public function applyBatch(Request $request, EmailApiAccess $access, ApplyEmailSmartInboxSuggestionBatch $apply)
    {
        $actor = $access->reader($request);
        $data = $request->validate(['suggestion_ids' => ['required', 'array', 'min:1', 'max:50'],
            'suggestion_ids.*' => ['integer', 'distinct', 'min:1']]);

        return response()->json(['data' => $apply->handle($data['suggestion_ids'], $actor)]);
    }

    public function rulePrefill(Request $request, EmailSmartInboxSuggestion $suggestion, EmailApiAccess $access, BuildEmailSmartInboxRulePrefill $prefill)
    {
        $data = $prefill->handle($suggestion, $access->reader($request));

        // Structured API output never requires navigating an HTML form or reusing a browser token.
        return response()->json(['data' => ['mode' => $data['mode'], 'personal_payload' => $data['personal_payload'],
            'admin_prefill' => $data['admin_query']]]);
    }
}
