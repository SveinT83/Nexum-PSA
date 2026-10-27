<?php

namespace App\Modules\Marketing\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Clients\ClientUser;
use App\Modules\Contact\Models\Contact;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailTemplate;
use App\Modules\Email\Services\EmailTemplateRenderer;
use App\Modules\Integration\Services\AiAgentResolver;
use App\Modules\Marketing\Actions\PreviewMarketingCampaignEmail;
use App\Modules\Marketing\Actions\PullWordPressContentSources;
use App\Modules\Marketing\Models\MarketingApiCommand;
use App\Modules\Marketing\Models\MarketingCampaign;
use App\Modules\Marketing\Models\MarketingConsentCategory;
use App\Modules\Marketing\Models\MarketingList;
use App\Modules\Marketing\Queries\MarketingDashboard;
use App\Modules\Marketing\Resources\Api\V1\MarketingCampaignRecipientResource;
use App\Modules\Marketing\Resources\Api\V1\MarketingCampaignResource;
use App\Modules\Marketing\Support\MarketingApiAccess;
use App\Modules\Marketing\Support\MarketingApiState;
use App\Modules\Marketing\Support\MarketingSettings;
use App\Modules\Taxonomy\Models\Category;
use App\Modules\Taxonomy\Models\Tag;
use Illuminate\Http\Request;

/** Workflow contexts and bounded reads expose only fields required by Marketing. */
class MarketingWorkspaceController extends Controller
{
    public function dashboard()
    {
        $data = app(MarketingDashboard::class)->handle();

        return response()->json(['data' => [
            'summary' => $data['dashboard'], 'settings' => $data['settings'],
            'sender' => $data['marketingDefaultAccount']?->only(['id', 'address', 'from_name']),
            'recent_campaigns' => MarketingCampaignResource::collection($data['recentCampaigns']),
            'due_recipients' => MarketingCampaignRecipientResource::collection($data['dueRecipients']),
            'recent_events' => $data['recentEvents']->map(fn ($event) => $event->only(['id', 'marketing_campaign_id', 'type', 'occurred_at'])),
        ]]);
    }

    public function listContext(Request $request, ?MarketingList $list = null)
    {
        return response()->json(['data' => [
            'settings' => app(MarketingSettings::class)->get(),
            'audience_types' => ['all_business_contacts', 'manual_contacts'],
            'contract_filters' => ['any', 'with_contract', 'without_contract', 'active_contract', 'without_active_contract', 'won_contract'],
            'list_id' => $list?->id, 'segment_criteria' => $list?->segment_criteria,
            'campaign_usage_count' => $list?->campaignUsageCount() ?? 0,
            'can_delete' => $list ? ! $list->isUsedByCampaigns() : false,
            'selected_tags' => Tag::whereIn('id', collect($list?->segment_criteria ?? [])->only(['contact_tag_ids', 'client_tag_ids'])->flatten())->orderBy('id')->get(['id', 'name', 'color']),
            'selected_industries' => Category::whereIn('id', ($list?->segment_criteria ?? [])['sales_category_ids'] ?? [])->orderBy('id')->get(['id', 'name']),
            'lookup_url' => url('/api/v1/marketing/lookups/{kind}'),
        ]]);
    }

    public function campaignContext(Request $request, ?MarketingCampaign $campaign = null)
    {
        return response()->json(['data' => [
            'settings' => app(MarketingSettings::class)->get(),
            'campaign_id' => $campaign?->id,
            'schedule_frequencies' => MarketingCampaign::SCHEDULE_FREQUENCIES,
            'sequence_interval_units' => MarketingCampaign::SEQUENCE_INTERVAL_UNITS,
            'weekdays' => MarketingCampaign::WEEKDAYS,
            'new_recipient_policies' => MarketingCampaign::NEW_RECIPIENT_POLICIES,
            'ai_available' => (bool) app(AiAgentResolver::class)->defaultAgent($request->user(), 'marketing'),
            'api_provider_execution_enabled' => (bool) config('marketing.api_provider_execution_enabled'),
            'lookup_url' => url('/api/v1/marketing/lookups/{kind}'),
            'preview_url' => $campaign ? route('api.v1.marketing.campaigns.emails.preview', $campaign) : null,
            'template_sample_variables' => app(EmailTemplateRenderer::class)->sampleVariables(new EmailTemplate(['scope' => 'marketing', 'variables' => ['contact_name', 'client_name', 'company_name', 'unsubscribe_url']])),
        ]]);
    }

    public function lookup(Request $request, string $kind)
    {
        $listKinds = ['contacts', 'legacy-contacts', 'tags', 'industries', 'consent-categories'];
        $campaignKinds = ['lists', 'accounts', 'templates'];
        abort_unless(in_array($kind, [...$listKinds, ...$campaignKinds], true), 404);
        $actor = $request->user();
        if (in_array($kind, $listKinds, true)) {
            MarketingApiAccess::authorize($actor, ['marketing.list.manage']);
            abort_unless($actor->tokenCan('marketing.lists.manage'), 403);
        } else {
            $create = MarketingApiAccess::allows($actor, 'marketing.campaign.create') && $actor->tokenCan('marketing.campaigns.create');
            $edit = MarketingApiAccess::allows($actor, 'marketing.campaign.edit') && $actor->tokenCan('marketing.campaigns.update');
            abort_unless($create || $edit, 403);
        }
        $query = match ($kind) {
            'contacts' => Contact::with('emails')->where('status', 'active')->where('do_not_email', false)->whereHas('emails')
                ->when(app(MarketingSettings::class)->get()['consent_mode'] === 'explicit_opt_in', fn ($q) => $q->where('marketing_consent', true)),
            'legacy-contacts' => ClientUser::whereNull('contact_id')->where('active', true)->whereNotNull('email'),
            'tags' => Tag::where('active', true), 'industries' => Category::where('is_active', true),
            'consent-categories' => MarketingConsentCategory::where('is_active', true),
            'lists' => MarketingList::query(), 'accounts' => EmailAccount::where('is_active', true),
            'templates' => EmailTemplate::where('scope', 'marketing')->where('is_active', true),
        };
        if ($request->filled('list_id')) {
            $request->validate(['list_id' => ['integer', 'exists:marketing_lists,id']]);
            abort_unless($kind === 'contacts', 422);
            $list = MarketingList::findOrFail($request->integer('list_id'));
            $query->whereNotIn('id', $list->members()->whereNotNull('contact_id')->pluck('contact_id'));
        }
        $column = match ($kind) {
            'contacts' => 'display_name', 'accounts' => 'address', default => 'name'
        };
        if ($request->filled('q')) {
            $query->where($column, 'like', '%'.trim($request->string('q')).'%');
        }
        $page = $query->orderBy($column)->orderBy('id')->paginate($request->integer('per_page') ?: 25);
        $page->setCollection($page->getCollection()->map(function ($item) use ($kind) {
            if ($kind === 'contacts') {
                return ['id' => $item->id, 'name' => $item->display_name, 'emails' => $item->emails->map->only(['id', 'email', 'is_primary'])->all()];
            }
            $columns = match ($kind) {
                'accounts' => ['id', 'address', 'from_name', 'defaults_for'],
                'templates' => ['id', 'name', 'subject', 'body_html', 'body_text', 'variables', 'layout_mode', 'layout_html', 'is_default'],
                'legacy-contacts' => ['id', 'name', 'email', 'client_site_id'],
                'tags' => ['id', 'name', 'color'], default => ['id', 'name'],
            };

            return $item->only($columns);
        }));

        return response()->json(['data' => $page->items(), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]]);
    }

    public function recipients(Request $request, MarketingCampaign $campaign)
    {
        $query = $campaign->recipients()->with(['campaignEmail', 'client', 'delivery'])->orderByDesc('updated_at')->orderByDesc('id');
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('q')) {
            $query->where(fn ($q) => $q->where('email', 'like', '%'.$request->string('q').'%')->orWhere('name', 'like', '%'.$request->string('q').'%'));
        }

        return MarketingCampaignRecipientResource::collection($query->paginate($request->integer('per_page') ?: 50));
    }

    public function contentSources(Request $request, MarketingCampaign $campaign)
    {
        return response()->json(['data' => $campaign->contentSources()->where('status', 'active')->orderByDesc('published_at')->orderByDesc('id')->paginate($request->integer('per_page') ?: 25)->through(fn ($source) => $source->only(['id', 'source_type', 'source_url', 'external_id', 'title', 'excerpt', 'content_html', 'published_at', 'fetched_at', 'status']))]);
    }

    public function events(Request $request, MarketingCampaign $campaign)
    {
        $query = $campaign->events()->orderByDesc('occurred_at')->orderByDesc('id');
        if ($request->filled('type')) {
            $query->where('type', $request->string('type'));
        }

        return response()->json(['data' => $query->paginate($request->integer('per_page') ?: 25)->through(fn ($event) => $event->only(['id', 'marketing_campaign_email_id', 'marketing_campaign_recipient_id', 'contact_id', 'client_id', 'type', 'url', 'occurred_at']))]);
    }

    public function preview(Request $request, MarketingCampaign $campaign)
    {
        return response()->json(['data' => app(PreviewMarketingCampaignEmail::class)->handle($campaign, $request, app(EmailTemplateRenderer::class))]);
    }

    public function pullWordPress(Request $request, MarketingCampaign $campaign)
    {
        abort_unless(in_array($campaign->status, ['draft', 'paused', 'approved', 'active'], true), 422, 'Campaign content sources cannot be changed in this state.');
        $data = $request->validate(['wordpress_url' => ['required', 'url', 'max:2048'], 'limit' => ['sometimes', 'integer', 'min:1', 'max:10']]);
        try {
            app(\App\Modules\Marketing\Support\MarketingPublicUrl::class)->options($data['wordpress_url']);
        } catch (\RuntimeException $error) {
            throw \Illuminate\Validation\ValidationException::withMessages(['wordpress_url' => $error->getMessage()]);
        }
        $request->attributes->set('marketing_provider_started', true);
        $count = app(PullWordPressContentSources::class)->handle($campaign, $data['wordpress_url'], $data['limit'] ?? 5);

        return response()->json(['data' => ['fetched_count' => $count, 'sources' => $campaign->contentSources()->where('status', 'active')->orderBy('id')->get()->map->only(['id', 'title', 'source_url', 'excerpt', 'content_html', 'published_at', 'fetched_at'])]]);
    }

    public function actionPreview(Request $request, MarketingCampaign $campaign)
    {
        return response()->json(['data' => [
            'campaign_id' => $campaign->id, 'status' => $campaign->status, 'revision' => MarketingApiState::etag($request),
            'active_emails' => $campaign->emails()->where('status', 'active')->count(),
            'due_pending' => $campaign->recipients()->where('status', 'pending')->where('due_at', '<=', now())->count(),
            'suppression_rechecked_at_send' => true, 'settings' => app(MarketingSettings::class)->get(),
            'sender' => ($campaign->emailAccount ?: app(\App\Modules\Email\Services\DefaultEmailAccountResolver::class)->forScope('marketing'))?->only(['id', 'address', 'from_name']),
            'api_provider_execution_enabled' => (bool) config('marketing.api_provider_execution_enabled'),
            'can_send' => MarketingApiAccess::allows($request->user(), 'marketing.campaign.send') && $request->user()->tokenCan('marketing.campaigns.send') && $request->user()->tokenCan('marketing.providers.execute') && config('marketing.api_provider_execution_enabled') && in_array($campaign->status, ['approved', 'active'], true),
        ]]);
    }

    public function operation(Request $request, string $operation)
    {
        $command = MarketingApiCommand::whereKey($operation)->where('actor_id', $request->user()->id)->where('token_hash', MarketingApiAccess::tokenHash($request))->firstOrFail();
        $result = $command->result;
        MarketingApiAccess::authorize($request->user(), $result['permissions']);
        abort_unless($request->user()->tokenCan($result['ability']), 403);
        abort_if(($result['provider'] ?? false) && ! $request->user()->tokenCan('marketing.providers.execute'), 403);

        return response()->json(['data' => ['id' => $command->id, 'operation' => $command->operation, 'state' => $command->state,
            'status' => $result['status'], 'result' => $result['body'], 'revision' => $result['etag'], 'created_at' => $command->created_at, 'updated_at' => $command->updated_at]]);
    }
}
