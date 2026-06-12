<?php

namespace App\Modules\Marketing\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Modules\Email\Actions\EnsureDefaultEmailTemplates;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailTemplate;
use App\Modules\Email\Services\EmailTemplateRenderer;
use App\Modules\Marketing\Actions\ApproveMarketingCampaign;
use App\Modules\Marketing\Actions\BuildMarketingCampaignEmailSnapshot;
use App\Modules\Marketing\Actions\DraftMarketingCampaignEmailWithAi;
use App\Modules\Marketing\Actions\DraftMarketingCampaignPlanWithAi;
use App\Modules\Marketing\Actions\EnsureMarketingDefaults;
use App\Modules\Marketing\Actions\ResolveMarketingListMembers;
use App\Modules\Marketing\Actions\SendMarketingCampaignEmailTest;
use App\Modules\Marketing\Actions\SyncMarketingCampaignRecipients;
use App\Modules\Integration\Services\AiAgentResolver;
use App\Modules\Marketing\Jobs\SendDueMarketingCampaignEmails;
use App\Modules\Marketing\Models\MarketingCampaign;
use App\Modules\Marketing\Models\MarketingCampaignEmail;
use App\Modules\Marketing\Models\MarketingInterestTag;
use App\Modules\Marketing\Models\MarketingList;
use App\Modules\Marketing\Models\MarketingListMember;
use App\Modules\Marketing\Support\MarketingSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

class MarketingCampaignController extends Controller
{
    public function index(EnsureMarketingDefaults $marketingDefaults, EnsureDefaultEmailTemplates $emailDefaults): View
    {
        $marketingDefaults->handle();
        $emailDefaults->handle();

        return view('marketing::Tech.campaigns.index', [
            'campaigns' => MarketingCampaign::query()
                ->with(['list', 'emailAccount'])
                ->withCount(['emails', 'recipients'])
                ->latest('updated_at')
                ->paginate(25),
            'listsCount' => MarketingList::query()->count(),
            'marketingTemplatesCount' => EmailTemplate::query()
                ->where('scope', 'marketing')
                ->where('is_active', true)
                ->count(),
        ]);
    }

    public function create(
        EnsureMarketingDefaults $marketingDefaults,
        EnsureDefaultEmailTemplates $emailDefaults,
        MarketingSettings $settings,
        Request $request,
    ): RedirectResponse|View {
        $marketingDefaults->handle();
        $emailDefaults->handle();

        $lists = MarketingList::query()->withCount('members')->orderBy('name')->get();
        $templates = EmailTemplate::query()
            ->where('scope', 'marketing')
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        if ($lists->isEmpty()) {
            $target = $request->user()?->can('marketing.list.manage')
                ? 'tech.marketing.lists.create'
                : 'tech.marketing.campaigns.index';

            return redirect()
                ->route($target)
                ->with('status', 'Create a mailing list before creating a marketing campaign.');
        }

        if ($templates->isEmpty()) {
            $target = $request->user()?->can('email.template_manage')
                ? 'tech.admin.system.templatesManagement.email.index'
                : 'tech.marketing.campaigns.index';

            return redirect()
                ->route($target, $target === 'tech.admin.system.templatesManagement.email.index' ? ['scope' => 'marketing'] : [])
                ->with('status', 'Create an active marketing email template before creating a campaign.');
        }

        return view('marketing::Tech.campaigns.form', [
            'campaign' => new MarketingCampaign([
                'status' => 'draft',
                'track_opens' => $settings->get()['open_tracking_enabled'],
                'track_clicks' => $settings->get()['click_tracking_enabled'],
            ]),
            'lists' => $lists,
            'templates' => $templates,
            'templateSnapshots' => $templates
                ->mapWithKeys(fn (EmailTemplate $template): array => [(string) $template->id => [
                    'name' => $template->name,
                    'subject' => $template->subject,
                    'body_html' => $template->body_html,
                    'body_text' => $template->body_text,
                ]])
                ->all(),
            'accounts' => EmailAccount::query()->where('is_active', true)->orderBy('address')->get(),
            'settings' => $settings->get(),
            'sequenceIntervalUnits' => MarketingCampaign::SEQUENCE_INTERVAL_UNITS,
            'newRecipientPolicies' => MarketingCampaign::NEW_RECIPIENT_POLICIES,
        ]);
    }

    public function store(Request $request, ResolveMarketingListMembers $resolve, BuildMarketingCampaignEmailSnapshot $snapshot): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'marketing_list_id' => ['required', 'exists:marketing_lists,id'],
            'email_account_id' => ['nullable', 'exists:email_accounts,id'],
            'email_template_id' => ['required', 'exists:email_templates,id'],
            'email_name' => ['nullable', 'string', 'max:255'],
            'email_subject' => ['required', 'string', 'max:255'],
            'body_html' => ['nullable', 'string'],
            'body_text' => ['nullable', 'string'],
            'starts_at' => ['nullable', 'date'],
            'batch_size' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'send_interval_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'sequence_interval_value' => ['nullable', 'integer', 'min:1', 'max:999'],
            'sequence_interval_unit' => ['nullable', 'string', Rule::in(array_keys(MarketingCampaign::SEQUENCE_INTERVAL_UNITS))],
            'new_recipient_policy' => ['nullable', 'string', Rule::in(array_keys(MarketingCampaign::NEW_RECIPIENT_POLICIES))],
            'track_opens' => ['nullable', 'boolean'],
            'track_clicks' => ['nullable', 'boolean'],
        ]);

        $template = EmailTemplate::query()
            ->whereKey($data['email_template_id'])
            ->where('scope', 'marketing')
            ->where('is_active', true)
            ->firstOrFail();

        $campaign = MarketingCampaign::query()->create([
            'marketing_list_id' => $data['marketing_list_id'],
            'email_account_id' => $data['email_account_id'] ?? null,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'status' => 'draft',
            'starts_at' => $data['starts_at'] ?? null,
            'batch_size' => $data['batch_size'] ?? null,
            'send_interval_minutes' => $data['send_interval_minutes'] ?? null,
            'sequence_interval_value' => $data['sequence_interval_value'] ?? 1,
            'sequence_interval_unit' => $data['sequence_interval_unit'] ?? 'days',
            'new_recipient_policy' => $data['new_recipient_policy'] ?? 'start_at_first_email',
            'track_opens' => $request->boolean('track_opens'),
            'track_clicks' => $request->boolean('track_clicks'),
            'created_by' => $request->user()?->id,
            'updated_by' => $request->user()?->id,
        ]);

        $campaign->emails()->create([
            ...$snapshot->fromTemplate($template, [
                'name' => $data['email_name'] ?? null,
                'email_subject' => $data['email_subject'],
                'body_html' => $data['body_html'] ?? null,
                'body_text' => $data['body_text'] ?? null,
            ]),
            'sequence_order' => 1,
            'status' => 'active',
            'scheduled_at' => null,
        ]);

        $resolve->handle($campaign->list);

        return redirect()
            ->route('tech.marketing.campaigns.show', $campaign)
            ->with('status', 'Marketing campaign created as draft.');
    }

    public function show(
        Request $request,
        MarketingCampaign $campaign,
        MarketingSettings $settings,
        EnsureMarketingDefaults $marketingDefaults,
        AiAgentResolver $aiAgentResolver,
        EmailTemplateRenderer $templateRenderer,
    ): View
    {
        $marketingDefaults->handle();

        $interestKeyCounts = $campaign->events()
            ->get(['metadata'])
            ->flatMap(fn ($event) => $event->metadata['interest_tag_keys'] ?? [])
            ->filter()
            ->countBy();
        $interestTags = MarketingInterestTag::query()
            ->whereIn('key', $interestKeyCounts->keys())
            ->get()
            ->keyBy('key');

        $templates = EmailTemplate::query()
            ->where('scope', 'marketing')
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();
        $campaign = $campaign->load([
            'list',
            'emailAccount',
            'approver',
            'emails' => fn ($query) => $query->with('template')->withCount('recipients')->orderBy('sequence_order'),
        ])->loadCount(['emails', 'recipients', 'events']);
        $previewMember = $this->previewMember($campaign);

        return view('marketing::Tech.campaigns.show', [
            'campaign' => $campaign,
            'recipients' => $campaign->recipients()->with(['campaignEmail.template', 'client'])->latest('updated_at')->paginate(50),
            'templates' => $templates,
            'templateSnapshots' => $templates
                ->mapWithKeys(fn (EmailTemplate $template): array => [(string) $template->id => [
                    'name' => $template->name,
                    'subject' => $template->subject,
                    'body_html' => $template->body_html,
                    'body_text' => $template->body_text,
                    'variables' => (array) $template->variables,
                    'sample_variables' => $this->previewVariables($template, $campaign, null, $previewMember, $templateRenderer),
                ]])
                ->all(),
            'campaignEmailPreviewVariables' => $campaign->emails
                ->mapWithKeys(function (MarketingCampaignEmail $email) use ($campaign, $previewMember, $templateRenderer): array {
                    $template = $email->renderableTemplate();

                    return [(string) $email->id => $template
                        ? $this->previewVariables($template, $campaign, $email, $previewMember, $templateRenderer)
                        : []];
                })
                ->all(),
            'interestSummary' => $interestKeyCounts
                ->map(fn (int $count, string $key): array => [
                    'key' => $key,
                    'name' => $interestTags->get($key)?->name ?? str($key)->replace('-', ' ')->title()->toString(),
                    'count' => $count,
                ])
                ->sortByDesc('count')
                ->values(),
            'settings' => $settings->get(),
            'aiDraftAvailable' => $request->user() ? (bool) $aiAgentResolver->defaultAgent($request->user(), 'marketing') : false,
            'sequenceIntervalUnits' => MarketingCampaign::SEQUENCE_INTERVAL_UNITS,
            'newRecipientPolicies' => MarketingCampaign::NEW_RECIPIENT_POLICIES,
        ]);
    }

    public function updateSchedule(
        MarketingCampaign $campaign,
        Request $request,
        SyncMarketingCampaignRecipients $syncRecipients,
    ): RedirectResponse {
        abort_if(! in_array($campaign->status, ['draft', 'paused', 'approved', 'active'], true), 422, 'Campaign schedule can only be changed before completion.');

        $data = $request->validate([
            'starts_at' => ['nullable', 'date'],
            'batch_size' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'send_interval_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'sequence_interval_value' => ['required', 'integer', 'min:1', 'max:999'],
            'sequence_interval_unit' => ['required', 'string', Rule::in(array_keys(MarketingCampaign::SEQUENCE_INTERVAL_UNITS))],
            'new_recipient_policy' => ['required', 'string', Rule::in(array_keys(MarketingCampaign::NEW_RECIPIENT_POLICIES))],
        ]);

        $campaign->forceFill([
            'starts_at' => $data['starts_at'] ?? null,
            'batch_size' => $data['batch_size'] ?? null,
            'send_interval_minutes' => $data['send_interval_minutes'] ?? null,
            'sequence_interval_value' => $data['sequence_interval_value'],
            'sequence_interval_unit' => $data['sequence_interval_unit'],
            'new_recipient_policy' => $data['new_recipient_policy'],
            'updated_by' => $request->user()?->id,
        ])->save();

        $updated = $syncRecipients->reschedulePending($campaign->fresh(['emails', 'list.members', 'recipients']));

        return redirect()
            ->route('tech.marketing.campaigns.show', $campaign)
            ->with('status', "Campaign schedule updated. {$updated} pending recipients were rescheduled.");
    }

    public function storeEmail(
        MarketingCampaign $campaign,
        Request $request,
        SyncMarketingCampaignRecipients $syncRecipients,
        BuildMarketingCampaignEmailSnapshot $snapshot,
    ): RedirectResponse {
        abort_if(! in_array($campaign->status, ['draft', 'paused', 'approved', 'active'], true), 422, 'Campaign emails can only be changed before completion.');

        $data = $request->validate([
            'email_template_id' => ['required', 'exists:email_templates,id'],
            'email_name' => ['nullable', 'string', 'max:255'],
            'email_subject' => ['required', 'string', 'max:255'],
            'body_html' => ['nullable', 'string'],
            'body_text' => ['nullable', 'string'],
            'sequence_order' => [
                'required',
                'integer',
                'min:1',
                'max:999',
                Rule::unique('marketing_campaign_emails', 'sequence_order')->where('marketing_campaign_id', $campaign->id),
            ],
            'delay_minutes' => ['required', 'integer', 'min:0', 'max:525600'],
        ]);

        $template = EmailTemplate::query()
            ->whereKey($data['email_template_id'])
            ->where('scope', 'marketing')
            ->where('is_active', true)
            ->firstOrFail();

        $campaign->emails()->create([
            ...$snapshot->fromTemplate($template, [
                'name' => $data['email_name'] ?? null,
                'email_subject' => $data['email_subject'],
                'body_html' => $data['body_html'] ?? null,
                'body_text' => $data['body_text'] ?? null,
            ]),
            'sequence_order' => $data['sequence_order'],
            'status' => 'active',
            'scheduled_at' => null,
            'delay_minutes' => $data['delay_minutes'],
        ]);

        $created = in_array($campaign->status, ['approved', 'active'], true)
            ? $syncRecipients->handle($campaign->fresh(['emails', 'list.members']))
            : 0;

        return redirect()
            ->route('tech.marketing.campaigns.show', $campaign)
            ->with('status', $created > 0 ? "Campaign email added and {$created} recipients queued." : 'Campaign email added.');
    }

    public function updateEmail(
        MarketingCampaign $campaign,
        MarketingCampaignEmail $email,
        Request $request,
        SyncMarketingCampaignRecipients $syncRecipients,
        BuildMarketingCampaignEmailSnapshot $snapshot,
    ): RedirectResponse {
        abort_if($email->marketing_campaign_id !== $campaign->id, 404);
        abort_if(! in_array($campaign->status, ['draft', 'paused', 'approved', 'active'], true), 422, 'Campaign emails can only be changed before completion.');

        $data = $request->validate([
            'email_name' => ['nullable', 'string', 'max:255'],
            'email_subject' => ['required', 'string', 'max:255'],
            'body_html' => ['nullable', 'string'],
            'body_text' => ['nullable', 'string'],
            'sequence_order' => [
                'required',
                'integer',
                'min:1',
                'max:999',
                Rule::unique('marketing_campaign_emails', 'sequence_order')
                    ->where('marketing_campaign_id', $campaign->id)
                    ->ignore($email->id),
            ],
            'delay_minutes' => ['required', 'integer', 'min:0', 'max:525600'],
            'status' => ['required', 'string', 'in:active,inactive'],
        ]);

        $email->forceFill(array_merge($snapshot->editableContent([
            'name' => $data['email_name'] ?? null,
            'email_subject' => $data['email_subject'],
            'body_html' => $data['body_html'] ?? null,
            'body_text' => $data['body_text'] ?? null,
        ]), [
            'sequence_order' => $data['sequence_order'],
            'delay_minutes' => $data['delay_minutes'],
            'scheduled_at' => null,
            'status' => $data['status'],
        ]))->save();

        $syncRecipients->reschedulePending($campaign->fresh(['emails', 'list.members', 'recipients']));

        return redirect()
            ->route('tech.marketing.campaigns.show', $campaign)
            ->with('status', 'Campaign email updated.');
    }

    public function testSendEmail(
        MarketingCampaign $campaign,
        MarketingCampaignEmail $email,
        Request $request,
        SendMarketingCampaignEmailTest $sendTest,
    ): RedirectResponse {
        abort_if($email->marketing_campaign_id !== $campaign->id, 404);

        $data = $request->validate([
            'test_to_email' => ['required', 'email', 'max:255'],
            'test_to_name' => ['nullable', 'string', 'max:255'],
            'email_name' => ['nullable', 'string', 'max:255'],
            'email_subject' => ['nullable', 'string', 'max:255'],
            'body_html' => ['nullable', 'string'],
            'body_text' => ['nullable', 'string'],
        ]);

        $payload = [
            'to_email' => $data['test_to_email'],
            'to_name' => $data['test_to_name'] ?? null,
        ];

        foreach (['email_name', 'email_subject', 'body_html', 'body_text'] as $key) {
            if (array_key_exists($key, $data)) {
                $payload[$key] = $data[$key];
            }
        }

        try {
            $sendTest->handle($campaign, $email, $request->user(), $payload);
        } catch (Throwable $exception) {
            return back()
                ->withErrors(['test_send' => $exception->getMessage()])
                ->withInput();
        }

        return back()->with('status', 'Test email sent to '.$data['test_to_email'].'.');
    }

    public function draftEmailWithAi(
        MarketingCampaign $campaign,
        Request $request,
        DraftMarketingCampaignEmailWithAi $draftEmail,
    ): JsonResponse {
        $data = $request->validate([
            'campaign_email_id' => ['nullable', 'integer', 'exists:marketing_campaign_emails,id'],
            'prompt' => ['required', 'string', 'max:4000'],
            'email_name' => ['nullable', 'string', 'max:255'],
            'email_subject' => ['nullable', 'string', 'max:255'],
            'body_html' => ['nullable', 'string'],
            'body_text' => ['nullable', 'string'],
        ]);
        $email = null;

        if (! empty($data['campaign_email_id'])) {
            $email = MarketingCampaignEmail::query()->findOrFail($data['campaign_email_id']);
            abort_if($email->marketing_campaign_id !== $campaign->id, 404);
        }

        try {
            return response()->json($draftEmail->handle($request->user(), $campaign, $email, $data));
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function draftPlanWithAi(
        MarketingCampaign $campaign,
        Request $request,
        DraftMarketingCampaignPlanWithAi $draftPlan,
    ): JsonResponse {
        $data = $request->validate([
            'prompt' => ['required', 'string', 'max:4000'],
        ]);

        try {
            return response()->json($draftPlan->handle($request->user(), $campaign, $data));
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function destroyEmail(MarketingCampaign $campaign, MarketingCampaignEmail $email): RedirectResponse
    {
        abort_if($email->marketing_campaign_id !== $campaign->id, 404);
        abort_if(! in_array($campaign->status, ['draft', 'paused', 'approved', 'active'], true), 422, 'Campaign emails can only be changed before completion.');

        $sentExists = $email->recipients()->where('status', 'sent')->exists();

        if ($sentExists) {
            $email->forceFill(['status' => 'inactive'])->save();
            $email->recipients()->where('status', 'pending')->update(['status' => 'cancelled', 'updated_at' => now()]);
        } else {
            $email->recipients()->delete();
            $email->delete();
        }

        return redirect()
            ->route('tech.marketing.campaigns.show', $campaign)
            ->with('status', $sentExists ? 'Campaign email deactivated. Sent history was kept.' : 'Campaign email removed.');
    }

    public function approve(MarketingCampaign $campaign, Request $request, ApproveMarketingCampaign $approve): RedirectResponse
    {
        abort_if(! in_array($campaign->status, ['draft', 'paused'], true), 422, 'Only draft or paused campaigns can be approved.');
        abort_if($campaign->emails()->where('status', 'active')->doesntExist(), 422, 'Campaign must have at least one active email.');

        $count = $approve->handle($campaign, $request->user());

        return redirect()
            ->route('tech.marketing.campaigns.show', $campaign)
            ->with('status', "Campaign approved with {$count} queued recipients.");
    }

    public function sendDue(MarketingCampaign $campaign): RedirectResponse
    {
        abort_if(! in_array($campaign->status, ['approved', 'active'], true), 422, 'Campaign must be approved before sending.');

        SendDueMarketingCampaignEmails::dispatch($campaign->id)->onQueue('email');

        return redirect()
            ->route('tech.marketing.campaigns.show', $campaign)
            ->with('status', 'Due campaign email send job queued.');
    }

    private function previewVariables(
        EmailTemplate $template,
        MarketingCampaign $campaign,
        ?MarketingCampaignEmail $email,
        ?MarketingListMember $member,
        EmailTemplateRenderer $templateRenderer,
    ): array {
        return array_merge($templateRenderer->sampleVariables($template), [
            'campaign_name' => $campaign->name,
            'campaign_email_name' => $email?->displayName() ?? $template->name,
            'contact_name' => $member ? ($member->name ?: 'there') : 'Ola Nordmann',
            'client_name' => $member ? ($member->client?->name ?? '') : 'Example Client AS',
            'unsubscribe_url' => url('/marketing/unsubscribe/example'),
        ]);
    }

    private function previewMember(MarketingCampaign $campaign): ?MarketingListMember
    {
        if (! $campaign->list) {
            return null;
        }

        return $campaign->list->members()
            ->with('client')
            ->where('status', 'eligible')
            ->orderBy('id')
            ->first()
            ?: $campaign->list->members()
                ->with('client')
                ->orderBy('id')
                ->first();
    }
}
