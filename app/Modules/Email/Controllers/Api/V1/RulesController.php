<?php

namespace App\Modules\Email\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailRule;
use App\Modules\Email\Services\InboundEmailRuleEngine;
use App\Modules\Email\Services\MailboxAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RulesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeRuleRead($request);

        $rules = EmailRule::query()
            ->adminManaged()
            ->with(['accounts:id,address,account_kind', 'publishedVersion'])
            ->orderBy('weight')
            ->orderBy('id')
            ->get()
            ->map(fn (EmailRule $rule): array => $this->serializeRule($rule))
            ->values();

        return response()->json(['data' => $rules]);
    }

    public function show(Request $request, EmailRule $rule): JsonResponse
    {
        $this->authorizeRuleRead($request);
        abort_unless($rule->isAdminManaged(), 404);

        $rule->load(['accounts:id,address,account_kind', 'publishedVersion']);

        return response()->json(['data' => $this->serializeRule($rule, includeSnapshot: true)]);
    }

    public function preview(
        Request $request,
        EmailRule $rule,
        InboundEmailRuleEngine $ruleEngine,
        MailboxAccess $mailboxAccess,
    ): JsonResponse {
        $this->authorizeRuleRead($request);
        abort_unless($rule->isAdminManaged(), 404);

        $data = $request->validate([
            'email_message_id' => 'required|integer|exists:email_messages,id',
        ]);

        $message = EmailMessage::query()
            ->with(['account', 'latestPlacement'])
            ->findOrFail($data['email_message_id']);

        if (! $mailboxAccess->canViewMessage($request->user(), $message)) {
            abort(404);
        }

        return response()->json([
            'data' => $ruleEngine->previewRule($rule, $message),
        ]);
    }

    private function authorizeRuleRead(Request $request): void
    {
        abort_unless($request->user()?->can('email.rule_manage'), 403);
    }

    private function serializeRule(EmailRule $rule, bool $includeSnapshot = false): array
    {
        $version = $rule->publishedVersion;
        $data = [
            'id' => $rule->id,
            'name' => $rule->name,
            'description' => $rule->description,
            'trigger' => $rule->trigger,
            'routing_phase' => $rule->routing_phase,
            'weight' => $rule->weight,
            'is_active' => $rule->is_active,
            'lifecycle_status' => $rule->lifecycle_status,
            'stop_processing' => $rule->stop_processing,
            'published_version' => $version ? [
                'id' => $version->id,
                'version_number' => $version->version_number,
                'status' => $version->status,
                'snapshot_hash' => $version->snapshot_hash,
                'published_at' => $version->published_at?->toIso8601String(),
            ] : null,
            'accounts' => $rule->accounts
                ->map(fn ($account): array => [
                    'id' => $account->id,
                    'address' => $account->address,
                    'account_kind' => $account->account_kind,
                ])
                ->values()
                ->all(),
            'last_hit_at' => $rule->last_hit_at?->toIso8601String(),
            'hit_count' => $rule->hit_count,
        ];

        if ($includeSnapshot) {
            $data['conditions'] = $version?->conditions_json ?? $rule->conditions_json ?? [];
            $data['actions'] = $version?->actions_json ?? $rule->actions_json ?? [];
            $data['account_ids'] = $version?->account_ids_json ?? $rule->accounts->pluck('id')->values()->all();
        }

        return $data;
    }
}
