<?php

namespace App\Modules\Marketing\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Modules\Email\Actions\EnsureDefaultEmailTemplates;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailTemplate;
use App\Modules\Marketing\Actions\ApproveMarketingCampaign;
use App\Modules\Marketing\Actions\EnsureMarketingDefaults;
use App\Modules\Marketing\Actions\ResolveMarketingListMembers;
use App\Modules\Marketing\Actions\SyncMarketingCampaignRecipients;
use App\Modules\Marketing\Jobs\SendDueMarketingCampaignEmails;
use App\Modules\Marketing\Models\MarketingCampaign;
use App\Modules\Marketing\Models\MarketingCampaignEmail;
use App\Modules\Marketing\Models\MarketingInterestTag;
use App\Modules\Marketing\Models\MarketingList;
use App\Modules\Marketing\Support\MarketingSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MarketingCampaignController extends Controller
{
    public function index(EnsureMarketingDefaults $marketingDefaults): View
    {
        $marketingDefaults->handle();

        return view('marketing::Tech.campaigns.index', [
            'campaigns' => MarketingCampaign::query()
                ->with(['list', 'emailAccount'])
                ->withCount(['emails', 'recipients'])
                ->latest('updated_at')
                ->paginate(25),
        ]);
    }

    public function create(
        EnsureMarketingDefaults $marketingDefaults,
        EnsureDefaultEmailTemplates $emailDefaults,
        MarketingSettings $settings,
    ): View {
        $marketingDefaults->handle();
        $emailDefaults->handle();

        return view('marketing::Tech.campaigns.form', [
            'campaign' => new MarketingCampaign([
                'status' => 'draft',
                'track_opens' => $settings->get()['open_tracking_enabled'],
                'track_clicks' => $settings->get()['click_tracking_enabled'],
            ]),
            'lists' => MarketingList::query()->withCount('members')->orderBy('name')->get(),
            'templates' => EmailTemplate::query()->where('scope', 'marketing')->where('is_active', true)->orderByDesc('is_default')->orderBy('name')->get(),
            'accounts' => EmailAccount::query()->where('is_active', true)->orderBy('address')->get(),
            'settings' => $settings->get(),
        ]);
    }

    public function store(Request $request, ResolveMarketingListMembers $resolve): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'marketing_list_id' => ['required', 'exists:marketing_lists,id'],
            'email_account_id' => ['nullable', 'exists:email_accounts,id'],
            'email_template_id' => ['required', 'exists:email_templates,id'],
            'subject_override' => ['nullable', 'string', 'max:255'],
            'starts_at' => ['nullable', 'date'],
            'batch_size' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'send_interval_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
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
            'track_opens' => $request->boolean('track_opens'),
            'track_clicks' => $request->boolean('track_clicks'),
            'created_by' => $request->user()?->id,
            'updated_by' => $request->user()?->id,
        ]);

        $campaign->emails()->create([
            'email_template_id' => $template->id,
            'sequence_order' => 1,
            'status' => 'active',
            'scheduled_at' => $data['starts_at'] ?? null,
            'subject_override' => $data['subject_override'] ?? null,
        ]);

        $resolve->handle($campaign->list);

        return redirect()
            ->route('tech.marketing.campaigns.show', $campaign)
            ->with('status', 'Marketing campaign created as draft.');
    }

    public function show(MarketingCampaign $campaign, MarketingSettings $settings): View
    {
        $interestKeyCounts = $campaign->events()
            ->get(['metadata'])
            ->flatMap(fn ($event) => $event->metadata['interest_tag_keys'] ?? [])
            ->filter()
            ->countBy();
        $interestTags = MarketingInterestTag::query()
            ->whereIn('key', $interestKeyCounts->keys())
            ->get()
            ->keyBy('key');

        return view('marketing::Tech.campaigns.show', [
            'campaign' => $campaign->load([
                'list',
                'emailAccount',
                'approver',
                'emails' => fn ($query) => $query->with('template')->withCount('recipients')->orderBy('sequence_order'),
            ])->loadCount(['emails', 'recipients', 'events']),
            'recipients' => $campaign->recipients()->with(['campaignEmail.template', 'client'])->latest('updated_at')->paginate(50),
            'templates' => EmailTemplate::query()->where('scope', 'marketing')->where('is_active', true)->orderByDesc('is_default')->orderBy('name')->get(),
            'interestSummary' => $interestKeyCounts
                ->map(fn (int $count, string $key): array => [
                    'key' => $key,
                    'name' => $interestTags->get($key)?->name ?? str($key)->replace('-', ' ')->title()->toString(),
                    'count' => $count,
                ])
                ->sortByDesc('count')
                ->values(),
            'settings' => $settings->get(),
        ]);
    }

    public function storeEmail(MarketingCampaign $campaign, Request $request, SyncMarketingCampaignRecipients $syncRecipients): RedirectResponse
    {
        abort_if(! in_array($campaign->status, ['draft', 'paused', 'approved', 'active'], true), 422, 'Campaign emails can only be changed before completion.');

        $data = $request->validate([
            'email_template_id' => ['required', 'exists:email_templates,id'],
            'sequence_order' => [
                'required',
                'integer',
                'min:1',
                'max:999',
                Rule::unique('marketing_campaign_emails', 'sequence_order')->where('marketing_campaign_id', $campaign->id),
            ],
            'delay_minutes' => ['required', 'integer', 'min:0', 'max:525600'],
            'scheduled_at' => ['nullable', 'date'],
            'subject_override' => ['nullable', 'string', 'max:255'],
        ]);

        $template = EmailTemplate::query()
            ->whereKey($data['email_template_id'])
            ->where('scope', 'marketing')
            ->where('is_active', true)
            ->firstOrFail();

        $campaign->emails()->create([
            'email_template_id' => $template->id,
            'sequence_order' => $data['sequence_order'],
            'status' => 'active',
            'scheduled_at' => $data['scheduled_at'] ?? null,
            'delay_minutes' => $data['delay_minutes'],
            'subject_override' => $data['subject_override'] ?? null,
        ]);

        $created = in_array($campaign->status, ['approved', 'active'], true)
            ? $syncRecipients->handle($campaign->fresh(['emails', 'list.members']))
            : 0;

        return redirect()
            ->route('tech.marketing.campaigns.show', $campaign)
            ->with('status', $created > 0 ? "Campaign email added and {$created} recipients queued." : 'Campaign email added.');
    }

    public function updateEmail(MarketingCampaign $campaign, MarketingCampaignEmail $email, Request $request, SyncMarketingCampaignRecipients $syncRecipients): RedirectResponse
    {
        abort_if($email->marketing_campaign_id !== $campaign->id, 404);
        abort_if(! in_array($campaign->status, ['draft', 'paused', 'approved', 'active'], true), 422, 'Campaign emails can only be changed before completion.');

        $data = $request->validate([
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
            'scheduled_at' => ['nullable', 'date'],
            'subject_override' => ['nullable', 'string', 'max:255'],
            'status' => ['required', 'string', 'in:active,inactive'],
        ]);

        $email->forceFill($data)->save();

        $dueAt = $syncRecipients->dueAt($campaign, $email->fresh());
        $email->recipients()
            ->where('status', 'pending')
            ->update(['due_at' => $dueAt, 'updated_at' => now()]);

        return redirect()
            ->route('tech.marketing.campaigns.show', $campaign)
            ->with('status', 'Campaign email updated.');
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
}
