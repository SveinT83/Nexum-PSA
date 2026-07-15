<?php

namespace App\Modules\Marketing\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Email\Models\EmailTemplate;
use App\Modules\Marketing\Actions\ApproveMarketingCampaign;
use App\Modules\Marketing\Actions\BuildMarketingCampaignEmailSnapshot;
use App\Modules\Marketing\Actions\CountMarketingCampaignAudienceRecipients;
use App\Modules\Marketing\Actions\DraftMarketingCampaignEmailWithAi;
use App\Modules\Marketing\Actions\DraftMarketingCampaignPlanWithAi;
use App\Modules\Marketing\Actions\EnsureMarketingDefaults;
use App\Modules\Marketing\Actions\ResolveMarketingListMembers;
use App\Modules\Marketing\Actions\SendMarketingCampaignEmailTest;
use App\Modules\Marketing\Actions\SyncMarketingCampaignRecipients;
use App\Modules\Marketing\Jobs\SendDueMarketingCampaignEmails;
use App\Modules\Marketing\Models\MarketingCampaign;
use App\Modules\Marketing\Models\MarketingCampaignEmail;
use App\Modules\Marketing\Resources\Api\V1\MarketingCampaignEmailResource;
use App\Modules\Marketing\Resources\Api\V1\MarketingCampaignResource;
use App\Modules\Marketing\Support\MarketingSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use RuntimeException;
use Throwable;

class MarketingCampaignController extends Controller
{
    private const SCHEDULE_FIELDS = [
        'starts_at',
        'schedule_frequency',
        'first_send_date',
        'send_time',
        'send_weekday',
        'month_day',
        'custom_interval_value',
        'custom_interval_unit',
        'batch_size',
        'send_interval_minutes',
        'sequence_interval_value',
        'sequence_interval_unit',
        'new_recipient_policy',
        'completion_behavior',
        'repeat_interval_value',
        'repeat_interval_unit',
    ];

    public function __construct(
        private readonly CountMarketingCampaignAudienceRecipients $audienceCounter,
    ) {
    }

    public function index(Request $request, EnsureMarketingDefaults $defaults)
    {
        $defaults->handle();

        $query = MarketingCampaign::query()
            ->with(['list.members', 'lists.members', 'emailAccount'])
            ->withCount(['emails', 'recipients', 'events'])
            ->latest('updated_at');

        if ($request->filled('q')) {
            $needle = '%'.trim((string) $request->input('q')).'%';
            $query->where(function ($inner) use ($needle): void {
                $inner->where('name', 'like', $needle)
                    ->orWhere('description', 'like', $needle)
                    ->orWhereHas('list', fn ($list) => $list->where('name', 'like', $needle))
                    ->orWhereHas('lists', fn ($list) => $list->where('name', 'like', $needle));
            });
        }

        foreach (['status', 'email_account_id'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $field === 'email_account_id' ? $request->integer($field) : $request->input($field));
            }
        }

        if ($request->filled('marketing_list_id')) {
            $listId = $request->integer('marketing_list_id');
            $query->where(function ($inner) use ($listId): void {
                $inner->where('marketing_list_id', $listId)
                    ->orWhereHas('lists', fn ($list) => $list->whereKey($listId));
            });
        }

        $campaigns = $query->paginate($request->integer('per_page') ?: 15);
        $this->attachAudienceRecipientCounts($campaigns->getCollection());

        return MarketingCampaignResource::collection($campaigns);
    }

    public function store(
        Request $request,
        ResolveMarketingListMembers $resolve,
        MarketingSettings $settings,
        EnsureMarketingDefaults $defaults,
    ) {
        $defaults->handle();
        $data = $this->validatedCampaignData($request, creating: true);
        $marketingListIds = $this->campaignListIds($data);
        $schedule = $this->normalizeSchedulePayload($data);
        $settingsPayload = $settings->get();

        $campaign = MarketingCampaign::query()->create([
            'marketing_list_id' => $marketingListIds[0],
            'email_account_id' => $data['email_account_id'] ?? null,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'status' => 'draft',
            'starts_at' => $schedule['starts_at'],
            'batch_size' => $data['batch_size'] ?? $settingsPayload['default_batch_size'],
            'send_interval_minutes' => $data['send_interval_minutes'] ?? $settingsPayload['default_send_interval_minutes'],
            'sequence_interval_value' => $schedule['sequence_interval_value'],
            'sequence_interval_unit' => $schedule['sequence_interval_unit'],
            'new_recipient_policy' => $data['new_recipient_policy'] ?? 'start_at_first_email',
            'completion_behavior' => $data['completion_behavior'] ?? 'stop',
            'repeat_interval_value' => $data['repeat_interval_value'] ?? 1,
            'repeat_interval_unit' => $data['repeat_interval_unit'] ?? 'months',
            'current_cycle' => 1,
            'track_opens' => $request->boolean('track_opens', $settingsPayload['open_tracking_enabled']),
            'track_clicks' => $request->boolean('track_clicks', $settingsPayload['click_tracking_enabled']),
            'created_by' => $request->user()?->id,
            'updated_by' => $request->user()?->id,
        ]);

        $campaign->lists()->sync($marketingListIds);

        $campaign->load('lists');
        foreach ($campaign->lists as $list) {
            $resolve->handle($list);
        }

        return (new MarketingCampaignResource($this->loadCampaign($campaign)))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, MarketingCampaign $campaign)
    {
        return new MarketingCampaignResource($this->loadCampaign($campaign, $request->boolean('include_recipients')));
    }

    public function update(
        Request $request,
        MarketingCampaign $campaign,
        ResolveMarketingListMembers $resolve,
        SyncMarketingCampaignRecipients $syncRecipients,
    ) {
        abort_if(! in_array($campaign->status, ['draft', 'paused', 'approved', 'active'], true), 422, 'Campaign can only be changed before completion.');

        $data = $this->validatedCampaignData($request, creating: false);
        $marketingListIds = $this->campaignListIds($data);
        $listChanged = $marketingListIds !== []
            && $campaign->audienceLists()->pluck('id')->sort()->values()->all() !== collect($marketingListIds)->sort()->values()->all();
        $scheduleChanged = $this->hasScheduleData($data);
        $attributes = ['updated_by' => $request->user()?->id];

        foreach (['name', 'description', 'email_account_id'] as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = $data[$field];
            }
        }

        if ($marketingListIds !== []) {
            $attributes['marketing_list_id'] = $marketingListIds[0];
        }

        foreach (['track_opens', 'track_clicks'] as $field) {
            if ($request->has($field)) {
                $attributes[$field] = $request->boolean($field);
            }
        }

        if ($scheduleChanged) {
            $scheduleData = $this->scheduleDataFromCampaign($campaign, $data);
            $schedule = $this->normalizeSchedulePayload($scheduleData);

            $attributes = array_merge($attributes, [
                'starts_at' => $schedule['starts_at'],
                'batch_size' => $scheduleData['batch_size'] ?? null,
                'send_interval_minutes' => $scheduleData['send_interval_minutes'] ?? null,
                'sequence_interval_value' => $schedule['sequence_interval_value'],
                'sequence_interval_unit' => $schedule['sequence_interval_unit'],
                'new_recipient_policy' => $scheduleData['new_recipient_policy'] ?? 'start_at_first_email',
                'completion_behavior' => $scheduleData['completion_behavior'] ?? 'stop',
                'repeat_interval_value' => $scheduleData['repeat_interval_value'] ?? 1,
                'repeat_interval_unit' => $scheduleData['repeat_interval_unit'] ?? 'months',
            ]);
        }

        $campaign->forceFill($attributes)->save();

        if ($marketingListIds !== []) {
            $campaign->lists()->sync($marketingListIds);
        }

        $created = 0;
        $rescheduled = 0;
        if ($listChanged) {
            $campaign = $campaign->fresh(['lists', 'list']);

            foreach ($campaign->audienceLists() as $list) {
                $resolve->handle($list);
            }

            if (in_array($campaign->status, ['approved', 'active'], true)) {
                $created = $syncRecipients->handle($campaign->fresh(['emails', 'lists.members', 'list.members', 'recipients']));
            }
        }

        if ($scheduleChanged) {
            $rescheduled = $syncRecipients->reschedulePending($campaign->fresh(['emails', 'lists.members', 'list.members', 'recipients']));
        }

        return (new MarketingCampaignResource($this->loadCampaign($campaign)))
            ->additional(['meta' => [
                'queued_recipients' => $created,
                'rescheduled_pending_recipients' => $rescheduled,
            ]]);
    }

    public function updateSchedule(
        MarketingCampaign $campaign,
        Request $request,
        SyncMarketingCampaignRecipients $syncRecipients,
    ) {
        abort_if(! in_array($campaign->status, ['draft', 'paused', 'approved', 'active'], true), 422, 'Campaign schedule can only be changed before completion.');

        $data = array_merge($this->schedulePayloadFromCampaign($campaign), $this->validatedScheduleData($request));
        $schedule = $this->normalizeSchedulePayload($data);

        $campaign->forceFill([
            'starts_at' => $schedule['starts_at'],
            'batch_size' => $data['batch_size'] ?? null,
            'send_interval_minutes' => $data['send_interval_minutes'] ?? null,
            'sequence_interval_value' => $schedule['sequence_interval_value'],
            'sequence_interval_unit' => $schedule['sequence_interval_unit'],
            'new_recipient_policy' => $data['new_recipient_policy'] ?? 'start_at_first_email',
            'completion_behavior' => $data['completion_behavior'] ?? 'stop',
            'repeat_interval_value' => $data['repeat_interval_value'] ?? 1,
            'repeat_interval_unit' => $data['repeat_interval_unit'] ?? 'months',
            'updated_by' => $request->user()?->id,
        ])->save();

        $updated = $syncRecipients->reschedulePending($campaign->fresh(['emails', 'lists.members', 'list.members', 'recipients']));

        return (new MarketingCampaignResource($this->loadCampaign($campaign)))
            ->additional(['meta' => ['rescheduled_pending_recipients' => $updated]]);
    }

    public function storeEmail(
        MarketingCampaign $campaign,
        Request $request,
        SyncMarketingCampaignRecipients $syncRecipients,
        BuildMarketingCampaignEmailSnapshot $snapshot,
    ) {
        abort_if(! in_array($campaign->status, ['draft', 'paused', 'approved', 'active'], true), 422, 'Campaign emails can only be changed before completion.');

        $data = $this->validatedCampaignEmailData($request, $campaign, creating: true);
        $template = EmailTemplate::query()
            ->whereKey($data['email_template_id'])
            ->where('scope', 'marketing')
            ->where('is_active', true)
            ->firstOrFail();

        $email = $campaign->emails()->create([
            ...$snapshot->fromTemplate($template, [
                'name' => $data['email_name'] ?? null,
                'email_subject' => $data['email_subject'],
                'body_html' => $data['body_html'] ?? null,
                'body_text' => $data['body_text'] ?? null,
            ]),
            'sequence_order' => $data['sequence_order'],
            'status' => $data['status'] ?? 'active',
            'scheduled_at' => null,
            'delay_minutes' => $data['delay_minutes'],
        ]);

        $created = in_array($campaign->status, ['approved', 'active'], true)
            ? $syncRecipients->handle($campaign->fresh(['emails', 'lists.members', 'list.members']))
            : 0;

        return (new MarketingCampaignEmailResource($this->loadCampaignEmail($email)))
            ->additional(['meta' => ['queued_recipients' => $created]])
            ->response()
            ->setStatusCode(201);
    }

    public function updateEmail(
        MarketingCampaign $campaign,
        MarketingCampaignEmail $email,
        Request $request,
        SyncMarketingCampaignRecipients $syncRecipients,
        BuildMarketingCampaignEmailSnapshot $snapshot,
    ) {
        abort_if($email->marketing_campaign_id !== $campaign->id, 404);
        abort_if(! in_array($campaign->status, ['draft', 'paused', 'approved', 'active'], true), 422, 'Campaign emails can only be changed before completion.');

        $data = $this->validatedCampaignEmailData($request, $campaign, creating: false, email: $email);

        $template = null;
        if (array_key_exists('email_template_id', $data)) {
            $template = EmailTemplate::query()
                ->whereKey($data['email_template_id'])
                ->where('scope', 'marketing')
                ->where('is_active', true)
                ->firstOrFail();
        }

        $content = $template
            ? $snapshot->fromTemplate($template, [
                'name' => $data['email_name'] ?? null,
                'email_subject' => $data['email_subject'],
                'body_html' => $data['body_html'] ?? null,
                'body_text' => $data['body_text'] ?? null,
            ])
            : $snapshot->editableContent([
                'name' => $data['email_name'] ?? null,
                'email_subject' => $data['email_subject'],
                'body_html' => $data['body_html'] ?? null,
                'body_text' => $data['body_text'] ?? null,
            ]);

        $email->forceFill(array_merge($content, [
            'sequence_order' => $data['sequence_order'],
            'delay_minutes' => $data['delay_minutes'],
            'scheduled_at' => null,
            'status' => $data['status'],
        ]))->save();

        $updated = $syncRecipients->reschedulePending($campaign->fresh(['emails', 'lists.members', 'list.members', 'recipients']));

        return (new MarketingCampaignEmailResource($this->loadCampaignEmail($email)))
            ->additional(['meta' => ['rescheduled_pending_recipients' => $updated]]);
    }

    public function testSendEmail(
        MarketingCampaign $campaign,
        MarketingCampaignEmail $email,
        Request $request,
        SendMarketingCampaignEmailTest $sendTest,
    ) {
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
            $messageId = $sendTest->handle($campaign, $email, $request->user(), $payload);
        } catch (Throwable $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'data' => [
                'message_id' => $messageId,
                'to_email' => $data['test_to_email'],
            ],
        ]);
    }

    public function draftEmailWithAi(
        MarketingCampaign $campaign,
        Request $request,
        DraftMarketingCampaignEmailWithAi $draftEmail,
    ) {
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
            return response()->json(['data' => $draftEmail->handle($request->user(), $campaign, $email, $data)]);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function draftPlanWithAi(
        MarketingCampaign $campaign,
        Request $request,
        DraftMarketingCampaignPlanWithAi $draftPlan,
    ) {
        $data = $request->validate([
            'prompt' => ['required', 'string', 'max:4000'],
        ]);

        try {
            return response()->json(['data' => $draftPlan->handle($request->user(), $campaign, $data)]);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function destroyEmail(MarketingCampaign $campaign, MarketingCampaignEmail $email)
    {
        abort_if($email->marketing_campaign_id !== $campaign->id, 404);
        abort_if(! in_array($campaign->status, ['draft', 'paused', 'approved', 'active'], true), 422, 'Campaign emails can only be changed before completion.');

        $sentExists = $email->recipients()->where('status', 'sent')->exists();

        if ($sentExists) {
            $email->forceFill(['status' => 'inactive'])->save();
            $email->recipients()->where('status', 'pending')->update(['status' => 'cancelled', 'updated_at' => now()]);

            return (new MarketingCampaignEmailResource($this->loadCampaignEmail($email)))
                ->additional(['meta' => ['deactivated' => true]]);
        }

        $email->recipients()->delete();
        $email->delete();

        return response()->noContent();
    }

    public function approve(MarketingCampaign $campaign, Request $request, ApproveMarketingCampaign $approve)
    {
        abort_if(! in_array($campaign->status, ['draft', 'paused'], true), 422, 'Only draft or paused campaigns can be approved.');
        abort_if($campaign->emails()->where('status', 'active')->doesntExist(), 422, 'Campaign must have at least one active email.');

        $count = $approve->handle($campaign, $request->user());

        return (new MarketingCampaignResource($this->loadCampaign($campaign)))
            ->additional(['meta' => ['queued_recipients' => $count]]);
    }

    public function sendDue(MarketingCampaign $campaign)
    {
        abort_if(! in_array($campaign->status, ['approved', 'active'], true), 422, 'Campaign must be approved before sending.');

        SendDueMarketingCampaignEmails::dispatch($campaign->id)->onQueue('email');

        return (new MarketingCampaignResource($this->loadCampaign($campaign)))
            ->additional(['meta' => ['queued_send_job' => true]]);
    }

    private function validatedCampaignData(Request $request, bool $creating): array
    {
        $nameRule = $creating ? 'required' : 'sometimes';
        $listRule = $creating ? 'required_without:marketing_list_ids' : 'sometimes';
        $listIdsRule = $creating ? 'required_without:marketing_list_id' : 'sometimes';

        return $request->validate([
            'name' => [$nameRule, 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'marketing_list_id' => [$listRule, 'integer', 'exists:marketing_lists,id'],
            'marketing_list_ids' => [$listIdsRule, 'array', 'min:1'],
            'marketing_list_ids.*' => ['integer', 'distinct', 'exists:marketing_lists,id'],
            'email_account_id' => ['sometimes', 'nullable', 'exists:email_accounts,id'],
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

    private function validatedScheduleData(Request $request): array
    {
        return $request->validate([
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
        ]);
    }

    private function validatedCampaignEmailData(
        Request $request,
        MarketingCampaign $campaign,
        bool $creating,
        ?MarketingCampaignEmail $email = null,
    ): array {
        $sequenceRule = Rule::unique('marketing_campaign_emails', 'sequence_order')
            ->where('marketing_campaign_id', $campaign->id);

        if ($email) {
            $sequenceRule->ignore($email->id);
        }

        return $request->validate([
            'email_template_id' => [$creating ? 'required' : 'sometimes', 'exists:email_templates,id'],
            'email_name' => ['nullable', 'string', 'max:255'],
            'email_subject' => ['required', 'string', 'max:255'],
            'body_html' => ['nullable', 'string'],
            'body_text' => ['nullable', 'string'],
            'sequence_order' => ['required', 'integer', 'min:1', 'max:999', $sequenceRule],
            'delay_minutes' => ['required', 'integer', 'min:0', 'max:525600'],
            'status' => [$creating ? 'nullable' : 'required', 'string', 'in:active,inactive'],
        ]);
    }

    private function schedulePayloadFromCampaign(MarketingCampaign $campaign): array
    {
        return [
            'starts_at' => $campaign->starts_at?->toDateTimeString(),
            'batch_size' => $campaign->batch_size,
            'send_interval_minutes' => $campaign->send_interval_minutes,
            'sequence_interval_value' => $campaign->sequence_interval_value ?: 1,
            'sequence_interval_unit' => $campaign->sequence_interval_unit ?: 'days',
            'new_recipient_policy' => $campaign->new_recipient_policy ?: 'start_at_first_email',
            'completion_behavior' => $campaign->completion_behavior ?: 'stop',
            'repeat_interval_value' => $campaign->repeat_interval_value ?: 1,
            'repeat_interval_unit' => $campaign->repeat_interval_unit ?: 'months',
        ];
    }

    private function hasScheduleData(array $data): bool
    {
        foreach (self::SCHEDULE_FIELDS as $field) {
            if (array_key_exists($field, $data)) {
                return true;
            }
        }

        return false;
    }

    private function scheduleDataFromCampaign(MarketingCampaign $campaign, array $data): array
    {
        $scheduleData = $this->schedulePayloadFromCampaign($campaign);

        foreach (self::SCHEDULE_FIELDS as $field) {
            if (array_key_exists($field, $data)) {
                $scheduleData[$field] = $data[$field];
            }
        }

        return $scheduleData;
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

    private function loadCampaign(MarketingCampaign $campaign, bool $includeRecipients = false): MarketingCampaign
    {
        $campaign = $campaign->fresh() ?: $campaign;
        $campaign->load([
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
        ])->loadCount(['emails', 'recipients', 'events']);

        if ($includeRecipients) {
            $campaign->load([
                'recipients' => fn ($query) => $query
                    ->with(['campaignEmail', 'client'])
                    ->latest('updated_at'),
            ]);
        }

        $campaign->setAttribute('audience_recipients_count', $this->audienceCounter->handle($campaign));

        return $campaign;
    }

    private function attachAudienceRecipientCounts(iterable $campaigns): void
    {
        foreach ($campaigns as $campaign) {
            $campaign->setAttribute('audience_recipients_count', $this->audienceCounter->handle($campaign));
        }
    }

    private function loadCampaignEmail(MarketingCampaignEmail $email): MarketingCampaignEmail
    {
        return ($email->fresh() ?: $email)
            ->load('template')
            ->loadCount([
                'recipients',
                'recipients as sent_recipients_count' => fn ($query) => $query->where('status', 'sent'),
                'events as open_events_count' => fn ($query) => $query->where('type', 'open'),
                'events as click_events_count' => fn ($query) => $query->where('type', 'click'),
            ]);
    }
}
