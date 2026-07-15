<?php

namespace App\Modules\Marketing\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Modules\Email\Actions\EnsureDefaultEmailTemplates;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailTemplate;
use App\Modules\Email\Services\EmailTemplateRenderer;
use App\Modules\Marketing\Actions\ApproveMarketingCampaign;
use App\Modules\Marketing\Actions\BuildMarketingCampaignEmailSnapshot;
use App\Modules\Marketing\Actions\CountMarketingCampaignAudienceRecipients;
use App\Modules\Marketing\Actions\DraftMarketingCampaignEmailWithAi;
use App\Modules\Marketing\Actions\DraftMarketingCampaignPlanWithAi;
use App\Modules\Marketing\Actions\EnsureMarketingDefaults;
use App\Modules\Marketing\Actions\PullWordPressContentSources;
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
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

class MarketingCampaignController extends Controller
{
    public function index(
        EnsureMarketingDefaults $marketingDefaults,
        EnsureDefaultEmailTemplates $emailDefaults,
        CountMarketingCampaignAudienceRecipients $audienceCounter,
    ): View
    {
        $marketingDefaults->handle();
        $emailDefaults->handle();

        $campaigns = MarketingCampaign::query()
            ->with(['list.members', 'lists.members', 'emailAccount'])
            ->withCount(['emails', 'recipients'])
            ->latest('updated_at')
            ->paginate(25);
        $this->attachAudienceRecipientCounts($campaigns->getCollection(), $audienceCounter);

        return view('marketing::Tech.campaigns.index', [
            'campaigns' => $campaigns,
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
        if ($lists->isEmpty()) {
            $target = $request->user()?->can('marketing.list.manage')
                ? 'tech.marketing.lists.create'
                : 'tech.marketing.campaigns.index';

            return redirect()
                ->route($target)
                ->with('status', 'Create a mailing list before creating a marketing campaign.');
        }

        return view('marketing::Tech.campaigns.form', [
            'campaign' => new MarketingCampaign([
                'status' => 'draft',
                'track_opens' => $settings->get()['open_tracking_enabled'],
                'track_clicks' => $settings->get()['click_tracking_enabled'],
                'completion_behavior' => 'stop',
                'repeat_interval_value' => 1,
                'repeat_interval_unit' => 'months',
                'current_cycle' => 1,
            ]),
            'lists' => $lists,
            'accounts' => EmailAccount::query()->where('is_active', true)->orderBy('address')->get(),
            'settings' => $settings->get(),
            'scheduleFrequencies' => MarketingCampaign::SCHEDULE_FREQUENCIES,
            'sequenceIntervalUnits' => MarketingCampaign::SEQUENCE_INTERVAL_UNITS,
            'weekdays' => MarketingCampaign::WEEKDAYS,
            'newRecipientPolicies' => MarketingCampaign::NEW_RECIPIENT_POLICIES,
            'completionBehaviors' => MarketingCampaign::COMPLETION_BEHAVIORS,
        ]);
    }

    public function store(Request $request, ResolveMarketingListMembers $resolve): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'marketing_list_id' => ['required_without:marketing_list_ids', 'integer', 'exists:marketing_lists,id'],
            'marketing_list_ids' => ['required_without:marketing_list_id', 'array', 'min:1'],
            'marketing_list_ids.*' => ['integer', 'distinct', 'exists:marketing_lists,id'],
            'email_account_id' => ['nullable', 'exists:email_accounts,id'],
            'starts_at' => ['nullable', 'date'],
            'schedule_frequency' => ['nullable', 'string', Rule::in(array_keys(MarketingCampaign::SCHEDULE_FREQUENCIES))],
            'first_send_date' => ['nullable', 'date_format:Y-m-d'],
            'send_time' => ['nullable', 'date_format:H:i'],
            'send_weekday' => ['nullable', 'integer', 'min:1', 'max:7'],
            'month_day' => ['nullable', 'integer', 'min:1', 'max:31'],
            'custom_interval_value' => ['nullable', 'integer', 'min:1', 'max:999'],
            'custom_interval_unit' => ['nullable', 'string', Rule::in(array_keys(MarketingCampaign::SEQUENCE_INTERVAL_UNITS))],
            'batch_size' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'send_interval_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'sequence_interval_value' => ['nullable', 'integer', 'min:1', 'max:999'],
            'sequence_interval_unit' => ['nullable', 'string', Rule::in(array_keys(MarketingCampaign::SEQUENCE_INTERVAL_UNITS))],
            'new_recipient_policy' => ['nullable', 'string', Rule::in(array_keys(MarketingCampaign::NEW_RECIPIENT_POLICIES))],
            'completion_behavior' => ['nullable', 'string', Rule::in(array_keys(MarketingCampaign::COMPLETION_BEHAVIORS))],
            'repeat_interval_value' => ['nullable', 'integer', 'min:1', 'max:999'],
            'repeat_interval_unit' => ['nullable', 'string', Rule::in(array_keys(MarketingCampaign::SEQUENCE_INTERVAL_UNITS))],
            'track_opens' => ['nullable', 'boolean'],
            'track_clicks' => ['nullable', 'boolean'],
        ]);

        $marketingListIds = $this->campaignListIds($data);

        if ($marketingListIds === []) {
            return back()
                ->withErrors(['marketing_list_ids' => 'Select at least one audience list.'])
                ->withInput();
        }

        $schedule = $this->normalizeSchedulePayload($data);

        $campaign = MarketingCampaign::query()->create([
            'marketing_list_id' => $marketingListIds[0],
            'email_account_id' => $data['email_account_id'] ?? null,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'status' => 'draft',
            'starts_at' => $schedule['starts_at'],
            'batch_size' => $data['batch_size'] ?? null,
            'send_interval_minutes' => $data['send_interval_minutes'] ?? null,
            'sequence_interval_value' => $schedule['sequence_interval_value'],
            'sequence_interval_unit' => $schedule['sequence_interval_unit'],
            'new_recipient_policy' => $data['new_recipient_policy'] ?? 'start_at_first_email',
            'completion_behavior' => $data['completion_behavior'] ?? 'stop',
            'repeat_interval_value' => $data['repeat_interval_value'] ?? 1,
            'repeat_interval_unit' => $data['repeat_interval_unit'] ?? 'months',
            'current_cycle' => 1,
            'track_opens' => $request->boolean('track_opens'),
            'track_clicks' => $request->boolean('track_clicks'),
            'created_by' => $request->user()?->id,
            'updated_by' => $request->user()?->id,
        ]);

        $campaign->lists()->sync($marketingListIds);

        $campaign->load('lists');
        foreach ($campaign->lists as $list) {
            $resolve->handle($list);
        }

        return redirect()
            ->route('tech.marketing.campaigns.show', $campaign)
            ->with('status', 'Marketing campaign created as draft.');
    }

    public function show(
        Request $request,
        MarketingCampaign $campaign,
        MarketingSettings $settings,
        EnsureMarketingDefaults $marketingDefaults,
        CountMarketingCampaignAudienceRecipients $audienceCounter,
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
            'lists',
            'emailAccount',
            'approver',
            'emails' => fn ($query) => $query
                ->with('template')
                ->withCount([
                    'recipients',
                    'recipients as sent_recipients_count' => fn ($query) => $query->where('status', 'sent'),
                    'events as open_events_count' => fn ($query) => $query->where('type', 'open'),
                    'events as click_events_count' => fn ($query) => $query->where('type', 'click'),
                ])
                ->orderBy('sequence_order'),
            'contentSources' => fn ($query) => $query->where('status', 'active')->latest('published_at')->latest('id'),
        ])->loadCount(['emails', 'recipients', 'events']);
        $campaign->setAttribute('audience_recipients_count', $audienceCounter->handle($campaign));
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
            'scheduleFrequencies' => MarketingCampaign::SCHEDULE_FREQUENCIES,
            'sequenceIntervalUnits' => MarketingCampaign::SEQUENCE_INTERVAL_UNITS,
            'weekdays' => MarketingCampaign::WEEKDAYS,
            'newRecipientPolicies' => MarketingCampaign::NEW_RECIPIENT_POLICIES,
            'completionBehaviors' => MarketingCampaign::COMPLETION_BEHAVIORS,
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
            'schedule_frequency' => ['nullable', 'string', Rule::in(array_keys(MarketingCampaign::SCHEDULE_FREQUENCIES))],
            'first_send_date' => ['nullable', 'date_format:Y-m-d'],
            'send_time' => ['nullable', 'date_format:H:i'],
            'send_weekday' => ['nullable', 'integer', 'min:1', 'max:7'],
            'month_day' => ['nullable', 'integer', 'min:1', 'max:31'],
            'custom_interval_value' => ['nullable', 'integer', 'min:1', 'max:999'],
            'custom_interval_unit' => ['nullable', 'string', Rule::in(array_keys(MarketingCampaign::SEQUENCE_INTERVAL_UNITS))],
            'batch_size' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'send_interval_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'sequence_interval_value' => ['nullable', 'integer', 'min:1', 'max:999'],
            'sequence_interval_unit' => ['nullable', 'string', Rule::in(array_keys(MarketingCampaign::SEQUENCE_INTERVAL_UNITS))],
            'new_recipient_policy' => ['required', 'string', Rule::in(array_keys(MarketingCampaign::NEW_RECIPIENT_POLICIES))],
            'completion_behavior' => ['nullable', 'string', Rule::in(array_keys(MarketingCampaign::COMPLETION_BEHAVIORS))],
            'repeat_interval_value' => ['nullable', 'integer', 'min:1', 'max:999'],
            'repeat_interval_unit' => ['nullable', 'string', Rule::in(array_keys(MarketingCampaign::SEQUENCE_INTERVAL_UNITS))],
        ]);
        $schedule = $this->normalizeSchedulePayload($data);

        $campaign->forceFill([
            'starts_at' => $schedule['starts_at'],
            'batch_size' => $data['batch_size'] ?? null,
            'send_interval_minutes' => $data['send_interval_minutes'] ?? null,
            'sequence_interval_value' => $schedule['sequence_interval_value'],
            'sequence_interval_unit' => $schedule['sequence_interval_unit'],
            'new_recipient_policy' => $data['new_recipient_policy'],
            'completion_behavior' => $data['completion_behavior'] ?? ($campaign->completion_behavior ?: 'stop'),
            'repeat_interval_value' => $data['repeat_interval_value'] ?? ($campaign->repeat_interval_value ?: 1),
            'repeat_interval_unit' => $data['repeat_interval_unit'] ?? ($campaign->repeat_interval_unit ?: 'months'),
            'updated_by' => $request->user()?->id,
        ])->save();

        $updated = $syncRecipients->reschedulePending($campaign->fresh(['emails', 'lists.members', 'list.members', 'recipients']));

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
            ? $syncRecipients->handle($campaign->fresh(['emails', 'lists.members', 'list.members']))
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

        $syncRecipients->reschedulePending($campaign->fresh(['emails', 'lists.members', 'list.members', 'recipients']));

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

    public function pullWordPressContent(
        MarketingCampaign $campaign,
        Request $request,
        PullWordPressContentSources $pullContent,
    ): RedirectResponse {
        abort_if(! in_array($campaign->status, ['draft', 'paused', 'approved', 'active'], true), 422, 'Campaign content sources can only be changed before completion.');

        $data = $request->validate([
            'wordpress_url' => ['required', 'url', 'max:2048'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:10'],
        ]);

        try {
            $count = $pullContent->handle($campaign, $data['wordpress_url'], (int) ($data['limit'] ?? 5));
        } catch (RuntimeException $exception) {
            return back()
                ->withErrors(['wordpress_url' => $exception->getMessage()])
                ->withInput();
        }

        return redirect()
            ->route('tech.marketing.campaigns.show', $campaign)
            ->with('status', "{$count} WordPress content items were pulled into this campaign.");
    }

    private function attachAudienceRecipientCounts(iterable $campaigns, CountMarketingCampaignAudienceRecipients $audienceCounter): void
    {
        foreach ($campaigns as $campaign) {
            $campaign->setAttribute('audience_recipients_count', $audienceCounter->handle($campaign));
        }
    }

    private function normalizeSchedulePayload(array $data): array
    {
        $frequency = $data['schedule_frequency'] ?? null;

        if (! $frequency) {
            return [
                'starts_at' => $this->dateTimeFromLegacyPayload($data),
                'sequence_interval_value' => $data['sequence_interval_value'] ?? 1,
                'sequence_interval_unit' => $data['sequence_interval_unit'] ?? 'days',
            ];
        }

        $startsAt = $this->startsAtFromScheduleFields($data);

        return match ($frequency) {
            'daily' => [
                'starts_at' => $startsAt,
                'sequence_interval_value' => 1,
                'sequence_interval_unit' => 'days',
            ],
            'weekly' => [
                'starts_at' => $this->weeklyStart($startsAt, (int) ($data['send_weekday'] ?? 5)),
                'sequence_interval_value' => 1,
                'sequence_interval_unit' => 'weeks',
            ],
            'monthly' => [
                'starts_at' => $this->monthlyStart($startsAt, (int) ($data['month_day'] ?? 1)),
                'sequence_interval_value' => 1,
                'sequence_interval_unit' => 'months',
            ],
            default => [
                'starts_at' => $startsAt,
                'sequence_interval_value' => $data['custom_interval_value'] ?? $data['sequence_interval_value'] ?? 1,
                'sequence_interval_unit' => $data['custom_interval_unit'] ?? $data['sequence_interval_unit'] ?? 'days',
            ],
        };
    }

    private function campaignListIds(array $data): array
    {
        $ids = collect($data['marketing_list_ids'] ?? []);

        if ($ids->isEmpty() && ! empty($data['marketing_list_id'])) {
            $ids->push((int) $data['marketing_list_id']);
        }

        return $ids
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function startsAtFromScheduleFields(array $data): ?Carbon
    {
        if (! empty($data['first_send_date'])) {
            $time = $data['send_time'] ?? '12:00';

            return Carbon::parse($data['first_send_date'].' '.$time)->seconds(0);
        }

        return $this->dateTimeFromLegacyPayload($data);
    }

    private function dateTimeFromLegacyPayload(array $data): ?Carbon
    {
        if (empty($data['starts_at'])) {
            return null;
        }

        return Carbon::parse($data['starts_at'])->seconds(0);
    }

    private function weeklyStart(?Carbon $startsAt, int $weekday): ?Carbon
    {
        if (! $startsAt) {
            return null;
        }

        $weekday = max(1, min(7, $weekday));
        $daysToAdd = ($weekday - (int) $startsAt->format('N') + 7) % 7;

        return $startsAt->copy()->addDays($daysToAdd);
    }

    private function monthlyStart(?Carbon $startsAt, int $monthDay): ?Carbon
    {
        if (! $startsAt) {
            return null;
        }

        $monthDay = max(1, min(31, $monthDay));
        $candidate = $this->dateOnMonthDay($startsAt, $monthDay);

        if ($candidate->lt($startsAt)) {
            $candidate = $this->dateOnMonthDay($startsAt->copy()->addMonthNoOverflow(), $monthDay);
        }

        return $candidate;
    }

    private function dateOnMonthDay(Carbon $date, int $monthDay): Carbon
    {
        $month = $date->copy()->day(1);
        $safeDay = min($monthDay, $month->daysInMonth);

        return $month->day($safeDay)->setTime((int) $date->format('H'), (int) $date->format('i'));
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
        foreach ($campaign->audienceLists() as $list) {
            $member = $list->members()
                ->with('client')
                ->where('status', 'eligible')
                ->orderBy('id')
                ->first();

            if ($member) {
                return $member;
            }
        }

        foreach ($campaign->audienceLists() as $list) {
            $member = $list->members()
                ->with('client')
                ->orderBy('id')
                ->first();

            if ($member) {
                return $member;
            }
        }

        return null;
    }
}
