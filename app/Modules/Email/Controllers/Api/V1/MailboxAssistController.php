<?php

namespace App\Modules\Email\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Email\Actions\AssistEmailComposerWithAi;
use App\Modules\Email\Actions\CreatePersonalEmailRule;
use App\Modules\Email\Actions\SummarizeEmailWithAi;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Queries\EmailMailboxReadQuery;
use App\Modules\Email\Services\EmailSignatureRenderer;
use App\Modules\Email\Support\EmailApiAccess;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** AI returns a proposal only; signature and personal rules use their established owning services. */
final class MailboxAssistController extends Controller
{
    public function signature(Request $request, EmailApiAccess $access, EmailSignatureRenderer $signatures)
    {
        $actor = $access->actor($request);

        return response()->json(['data' => $this->signatureData($signatures->signatureFor($actor), $actor, $signatures)]);
    }

    public function updateSignature(Request $request, EmailApiAccess $access, EmailSignatureRenderer $signatures)
    {
        $actor = $access->actor($request);
        $data = $request->validate([
            'name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'body_html' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'use_on_compose' => ['sometimes', 'boolean'], 'use_on_reply' => ['sometimes', 'boolean'],
            'use_on_reply_all' => ['sometimes', 'boolean'], 'use_on_forward' => ['sometimes', 'boolean'],
            'use_default_template' => ['sometimes', 'boolean'],
        ]);
        $existing = $signatures->signatureFor($actor);
        foreach (['use_on_compose', 'use_on_reply', 'use_on_reply_all', 'use_on_forward'] as $key) {
            $data[$key] ??= (bool) $existing->$key;
        }

        return response()->json(['data' => $this->signatureData($signatures->update($actor, $data), $actor, $signatures)]);
    }

    public function summary(Request $request, EmailMailboxPlacement $placement, EmailApiAccess $access, SummarizeEmailWithAi $summarize)
    {
        $placement = $access->placement($request, $placement);
        $thread = (new EmailMailboxReadQuery($request->user()))->conversation($placement, [(int) $placement->account_id]);

        return response()->json(['data' => $summarize->handle($placement, $request->user(), $thread['placements'])]);
    }

    public function composerAssist(Request $request, EmailMailboxPlacement $placement, EmailApiAccess $access, AssistEmailComposerWithAi $assist)
    {
        $data = $request->validate([
            'intent' => ['required', Rule::in(['draft_reply', 'improve', 'shorten', 'friendly', 'translate_norwegian'])],
            'composer_mode' => ['required', Rule::in(['reply', 'reply_all', 'forward'])],
            'subject' => ['nullable', 'string', 'max:512'], 'current_body_html' => ['nullable', 'string', 'max:120000'],
            'user_instruction' => ['nullable', 'string', 'max:5000'],
        ]);
        $placement = $access->placement($request, $placement);
        $thread = (new EmailMailboxReadQuery($request->user()))->conversation($placement, [(int) $placement->account_id]);

        return response()->json(['data' => $assist->handle($placement, $request->user(), $data, $thread['placements'])]);
    }

    public function personalRule(Request $request, EmailMailboxPlacement $placement, EmailApiAccess $access, CreatePersonalEmailRule $create)
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'condition_field' => ['required', Rule::in(['from', 'from_domain', 'subject', 'to', 'cc'])],
            'condition_value' => ['required', 'string', 'max:1000'],
            'action_type' => ['required', Rule::in(['move_to_folder', 'archive'])],
            'target_folder_id' => ['required_if:action_type,move_to_folder', 'nullable', 'integer', 'min:1'],
        ]);
        $placement = $access->placement($request, $placement);
        $rule = $create->handle($placement, $request->user(), $data);

        return response()->json(['data' => $rule->only(['id', 'name', 'rule_kind', 'owner_id', 'is_active',
            'routing_phase', 'conditions_json', 'actions_json', 'lifecycle_status'])], 201);
    }

    public function newComposerAssist(Request $request, \App\Modules\Email\Models\EmailAccount $account,
        EmailApiAccess $access, AssistEmailComposerWithAi $assist)
    {
        $actor = $access->ordinary($request, $account, \App\Modules\Email\Services\MailboxAccess::SEND);
        $data = $request->validate([
            'intent' => ['required', Rule::in(['improve', 'shorten', 'friendly', 'translate_norwegian'])],
            'subject' => ['nullable', 'string', 'max:512'],
            'current_body_html' => ['nullable', 'string', 'max:120000'],
            'user_instruction' => ['nullable', 'string', 'max:5000'],
        ]);
        $data['composer_mode'] = 'compose';

        return response()->json(['data' => $assist->handleNew($account, $actor, $data)]);
    }

    private function signatureData($signature, $actor, EmailSignatureRenderer $signatures): array
    {
        return $signature->only(['name', 'body_html', 'body_text', 'use_on_compose', 'use_on_reply', 'use_on_reply_all', 'use_on_forward'])
            + ['rendered_body_html' => $signatures->renderBodyHtml($signature, $actor),
                'tokens' => $signatures->tokenDescriptions()];
    }
}
