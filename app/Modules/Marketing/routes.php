<?php

use App\Modules\Marketing\Controllers\Admin\MarketingSettingsController;
use App\Modules\Marketing\Controllers\Api\V1\MarketingCampaignController as ApiCampaign;
use App\Modules\Marketing\Controllers\Api\V1\MarketingListController as ApiList;
use App\Modules\Marketing\Controllers\Api\V1\MarketingSettingsController as ApiSettings;
use App\Modules\Marketing\Controllers\PublicTrackingController;
use App\Modules\Marketing\Controllers\Tech\MarketingCampaignController;
use App\Modules\Marketing\Controllers\Tech\MarketingController;
use App\Modules\Marketing\Controllers\Tech\MarketingListController;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

if (($marketingApiRoutes ?? false) === true) {
    Route::middleware([\App\Modules\Marketing\Support\MarketingApiErrors::class, \App\Modules\Marketing\Support\MarketingApiAccess::class, \App\Modules\Marketing\Actions\ExecuteMarketingApiCommand::class])->group(function (): void {
        Route::get('marketing/lists', [ApiList::class, 'index'])
            ->name('marketing.lists.index')
            ->middleware(CheckAbilities::class.':marketing.read')
            ->defaults('marketing_ability', 'marketing.read')
            ->defaults('marketing_permissions', ['marketing.view'])
            ->defaults('marketing_fields', ['q', 'status', 'audience_type', 'consent_category_id', 'per_page', 'page']);

        Route::post('marketing/lists', [ApiList::class, 'store'])
            ->name('marketing.lists.store')
            ->middleware(CheckAbilities::class.':marketing.lists.manage')
            ->defaults('marketing_ability', 'marketing.lists.manage')
            ->defaults('marketing_permissions', ['marketing.list.manage'])
            ->defaults('marketing_fields', ['name', 'description', 'audience_type', 'consent_category_id', 'contact_tag_ids', 'client_tag_ids', 'manual_contact_ids', 'manual_client_user_ids', 'postal_codes', 'counties', 'countries', 'sales_category_ids', 'contract_filter']);

        Route::get('marketing/lists/{list}', [ApiList::class, 'show'])
            ->name('marketing.lists.show')
            ->middleware(CheckAbilities::class.':marketing.read')
            ->defaults('marketing_ability', 'marketing.read')
            ->defaults('marketing_permissions', ['marketing.view'])
            ->defaults('marketing_fields', []);

        Route::match(['put', 'patch'], 'marketing/lists/{list}', [ApiList::class, 'update'])
            ->name('marketing.lists.update')
            ->middleware(CheckAbilities::class.':marketing.lists.manage')
            ->defaults('marketing_ability', 'marketing.lists.manage')
            ->defaults('marketing_permissions', ['marketing.list.manage'])
            ->defaults('marketing_fields', ['name', 'description', 'audience_type', 'consent_category_id', 'contact_tag_ids', 'client_tag_ids', 'manual_contact_ids', 'manual_client_user_ids', 'postal_codes', 'counties', 'countries', 'sales_category_ids', 'contract_filter']);

        Route::delete('marketing/lists/{list}', [ApiList::class, 'destroy'])
            ->name('marketing.lists.destroy')
            ->middleware(CheckAbilities::class.':marketing.lists.manage')
            ->defaults('marketing_ability', 'marketing.lists.manage')
            ->defaults('marketing_permissions', ['marketing.list.manage'])
            ->defaults('marketing_fields', []);

        Route::get('marketing/lists/{list}/members', [ApiList::class, 'members'])
            ->name('marketing.lists.members.index')
            ->middleware(CheckAbilities::class.':marketing.read')
            ->defaults('marketing_ability', 'marketing.read')
            ->defaults('marketing_permissions', ['marketing.view'])
            ->defaults('marketing_fields', ['q', 'status', 'per_page', 'page']);

        Route::post('marketing/lists/{list}/refresh', [ApiList::class, 'refresh'])
            ->name('marketing.lists.refresh')
            ->middleware(CheckAbilities::class.':marketing.lists.manage')
            ->defaults('marketing_ability', 'marketing.lists.manage')
            ->defaults('marketing_permissions', ['marketing.list.manage'])
            ->defaults('marketing_fields', []);

        Route::post('marketing/lists/{list}/contacts', [ApiList::class, 'addContacts'])
            ->name('marketing.lists.contacts.add')
            ->middleware(CheckAbilities::class.':marketing.lists.manage')
            ->defaults('marketing_ability', 'marketing.lists.manage')
            ->defaults('marketing_permissions', ['marketing.list.manage'])
            ->defaults('marketing_fields', ['contact_ids']);

        Route::delete('marketing/lists/{list}/contacts/{contact}', [ApiList::class, 'removeContact'])
            ->name('marketing.lists.contacts.remove')
            ->middleware(CheckAbilities::class.':marketing.lists.manage')
            ->defaults('marketing_ability', 'marketing.lists.manage')
            ->defaults('marketing_permissions', ['marketing.list.manage'])
            ->defaults('marketing_fields', []);

        Route::get('marketing/campaigns', [ApiCampaign::class, 'index'])
            ->name('marketing.campaigns.index')
            ->middleware(CheckAbilities::class.':marketing.read')
            ->defaults('marketing_ability', 'marketing.read')
            ->defaults('marketing_permissions', ['marketing.view'])
            ->defaults('marketing_fields', ['q', 'status', 'email_account_id', 'marketing_list_id', 'per_page', 'page']);

        Route::post('marketing/campaigns', [ApiCampaign::class, 'store'])
            ->name('marketing.campaigns.store')
            ->middleware(CheckAbilities::class.':marketing.campaigns.create')
            ->defaults('marketing_ability', 'marketing.campaigns.create')
            ->defaults('marketing_permissions', ['marketing.campaign.create'])
            ->defaults('marketing_fields', ['name', 'description', 'marketing_list_id', 'marketing_list_ids', 'email_account_id', 'starts_at', 'schedule_frequency', 'first_send_date', 'send_time', 'send_weekday', 'month_day', 'custom_interval_value', 'custom_interval_unit', 'batch_size', 'send_interval_minutes', 'sequence_interval_value', 'sequence_interval_unit', 'new_recipient_policy', 'completion_behavior', 'repeat_interval_value', 'repeat_interval_unit', 'track_opens', 'track_clicks']);

        Route::get('marketing/campaigns/{campaign}', [ApiCampaign::class, 'show'])
            ->name('marketing.campaigns.show')
            ->middleware(CheckAbilities::class.':marketing.read')
            ->defaults('marketing_ability', 'marketing.read')
            ->defaults('marketing_permissions', ['marketing.view'])
            ->defaults('marketing_fields', ['include_recipients']);

        Route::match(['put', 'patch'], 'marketing/campaigns/{campaign}', [ApiCampaign::class, 'update'])
            ->name('marketing.campaigns.update')
            ->middleware(CheckAbilities::class.':marketing.campaigns.update')
            ->defaults('marketing_ability', 'marketing.campaigns.update')
            ->defaults('marketing_permissions', ['marketing.campaign.edit'])
            ->defaults('marketing_fields', ['name', 'description', 'marketing_list_id', 'marketing_list_ids', 'email_account_id', 'starts_at', 'schedule_frequency', 'first_send_date', 'send_time', 'send_weekday', 'month_day', 'custom_interval_value', 'custom_interval_unit', 'batch_size', 'send_interval_minutes', 'sequence_interval_value', 'sequence_interval_unit', 'new_recipient_policy', 'completion_behavior', 'repeat_interval_value', 'repeat_interval_unit', 'track_opens', 'track_clicks']);

        Route::post('marketing/campaigns/{campaign}/ai-plan', [ApiCampaign::class, 'draftPlanWithAi'])
            ->name('marketing.campaigns.ai-plan')
            ->middleware(CheckAbilities::class.':marketing.campaigns.update')
            ->defaults('marketing_ability', 'marketing.campaigns.update')
            ->defaults('marketing_permissions', ['marketing.campaign.edit'])
            ->defaults('marketing_fields', ['prompt'])
            ->middleware(CheckAbilities::class.':marketing.providers.execute')
            ->defaults('marketing_provider', true);

        Route::match(['put', 'patch'], 'marketing/campaigns/{campaign}/schedule', [ApiCampaign::class, 'updateSchedule'])
            ->name('marketing.campaigns.schedule.update')
            ->middleware(CheckAbilities::class.':marketing.campaigns.update')
            ->defaults('marketing_ability', 'marketing.campaigns.update')
            ->defaults('marketing_permissions', ['marketing.campaign.edit'])
            ->defaults('marketing_fields', ['starts_at', 'schedule_frequency', 'first_send_date', 'send_time', 'send_weekday', 'month_day', 'custom_interval_value', 'custom_interval_unit', 'batch_size', 'send_interval_minutes', 'sequence_interval_value', 'sequence_interval_unit', 'new_recipient_policy', 'completion_behavior', 'repeat_interval_value', 'repeat_interval_unit']);

        Route::post('marketing/campaigns/{campaign}/emails', [ApiCampaign::class, 'storeEmail'])
            ->name('marketing.campaigns.emails.store')
            ->middleware(CheckAbilities::class.':marketing.campaigns.update')
            ->defaults('marketing_ability', 'marketing.campaigns.update')
            ->defaults('marketing_permissions', ['marketing.campaign.edit'])
            ->defaults('marketing_fields', ['email_template_id', 'email_name', 'email_subject', 'body_html', 'body_text', 'sequence_order', 'delay_minutes', 'status']);

        Route::post('marketing/campaigns/{campaign}/emails/ai-draft', [ApiCampaign::class, 'draftEmailWithAi'])
            ->name('marketing.campaigns.emails.ai-draft')
            ->middleware(CheckAbilities::class.':marketing.campaigns.update')
            ->defaults('marketing_ability', 'marketing.campaigns.update')
            ->defaults('marketing_permissions', ['marketing.campaign.edit'])
            ->defaults('marketing_fields', ['campaign_email_id', 'prompt', 'email_name', 'email_subject', 'body_html', 'body_text'])
            ->middleware(CheckAbilities::class.':marketing.providers.execute')
            ->defaults('marketing_provider', true);

        Route::match(['put', 'patch'], 'marketing/campaigns/{campaign}/emails/{email}', [ApiCampaign::class, 'updateEmail'])
            ->name('marketing.campaigns.emails.update')
            ->middleware(CheckAbilities::class.':marketing.campaigns.update')
            ->defaults('marketing_ability', 'marketing.campaigns.update')
            ->defaults('marketing_permissions', ['marketing.campaign.edit'])
            ->defaults('marketing_fields', ['email_template_id', 'email_name', 'email_subject', 'body_html', 'body_text', 'sequence_order', 'delay_minutes', 'status']);

        Route::post('marketing/campaigns/{campaign}/emails/{email}/test-send', [ApiCampaign::class, 'testSendEmail'])
            ->name('marketing.campaigns.emails.test-send')
            ->middleware(CheckAbilities::class.':marketing.campaigns.update')
            ->defaults('marketing_ability', 'marketing.campaigns.update')
            ->defaults('marketing_permissions', ['marketing.campaign.edit'])
            ->defaults('marketing_fields', ['test_to_email', 'test_to_name', 'email_name', 'email_subject', 'body_html', 'body_text'])
            ->middleware(CheckAbilities::class.':marketing.providers.execute')
            ->defaults('marketing_provider', true);

        Route::delete('marketing/campaigns/{campaign}/emails/{email}', [ApiCampaign::class, 'destroyEmail'])
            ->name('marketing.campaigns.emails.destroy')
            ->middleware(CheckAbilities::class.':marketing.campaigns.update')
            ->defaults('marketing_ability', 'marketing.campaigns.update')
            ->defaults('marketing_permissions', ['marketing.campaign.edit'])
            ->defaults('marketing_fields', []);

        Route::post('marketing/campaigns/{campaign}/approve', [ApiCampaign::class, 'approve'])
            ->name('marketing.campaigns.approve')
            ->middleware(CheckAbilities::class.':marketing.campaigns.approve')
            ->defaults('marketing_ability', 'marketing.campaigns.approve')
            ->defaults('marketing_permissions', ['marketing.campaign.approve'])
            ->defaults('marketing_fields', []);

        Route::post('marketing/campaigns/{campaign}/send-due', [ApiCampaign::class, 'sendDue'])
            ->name('marketing.campaigns.send-due')
            ->middleware(CheckAbilities::class.':marketing.campaigns.send')
            ->defaults('marketing_ability', 'marketing.campaigns.send')
            ->defaults('marketing_permissions', ['marketing.campaign.send'])
            ->defaults('marketing_fields', [])
            ->middleware(CheckAbilities::class.':marketing.providers.execute')
            ->defaults('marketing_provider', true);

        Route::get('marketing/settings', [ApiSettings::class, 'show'])
            ->name('marketing.settings.show')
            ->middleware(CheckAbilities::class.':marketing.read')
            ->defaults('marketing_ability', 'marketing.read')
            ->defaults('marketing_permissions', ['marketing.settings.manage'])
            ->defaults('marketing_fields', []);

        Route::match(['put', 'patch'], 'marketing/settings', [ApiSettings::class, 'update'])
            ->name('marketing.settings.update')
            ->middleware(CheckAbilities::class.':marketing.settings.update')
            ->defaults('marketing_ability', 'marketing.settings.update')
            ->defaults('marketing_permissions', ['marketing.settings.manage'])
            ->defaults('marketing_fields', ['consent_mode', 'unsubscribe_mode', 'active_contract_clients_eligible', 'open_tracking_enabled', 'click_tracking_enabled', 'default_batch_size', 'default_send_interval_minutes', 'quiet_hours_start', 'quiet_hours_end', 'unsubscribe_footer']);

        Route::get('marketing/dashboard', [\App\Modules\Marketing\Controllers\Api\V1\MarketingWorkspaceController::class, 'dashboard'])
            ->name('marketing.dashboard')
            ->middleware(CheckAbilities::class.':marketing.read')
            ->defaults('marketing_ability', 'marketing.read')
            ->defaults('marketing_permissions', ['marketing.view'])
            ->defaults('marketing_fields', []);

        Route::get('marketing/context/lists', [\App\Modules\Marketing\Controllers\Api\V1\MarketingWorkspaceController::class, 'listContext'])
            ->middleware(CheckAbilities::class.':marketing.lists.manage')->name('marketing.context.lists')
            ->middleware(CheckAbilities::class.':marketing.context.read')
            ->defaults('marketing_ability', 'marketing.context.read')
            ->defaults('marketing_permissions', ['marketing.list.manage'])
            ->defaults('marketing_fields', []);

        Route::get('marketing/lists/{list}/context', [\App\Modules\Marketing\Controllers\Api\V1\MarketingWorkspaceController::class, 'listContext'])
            ->middleware(CheckAbilities::class.':marketing.lists.manage')->name('marketing.lists.context')
            ->middleware(CheckAbilities::class.':marketing.context.read')
            ->defaults('marketing_ability', 'marketing.context.read')
            ->defaults('marketing_permissions', ['marketing.list.manage'])
            ->defaults('marketing_fields', []);

        Route::get('marketing/context/campaigns', [\App\Modules\Marketing\Controllers\Api\V1\MarketingWorkspaceController::class, 'campaignContext'])
            ->middleware(CheckAbilities::class.':marketing.campaigns.create')->name('marketing.context.campaigns')
            ->middleware(CheckAbilities::class.':marketing.context.read')
            ->defaults('marketing_ability', 'marketing.context.read')
            ->defaults('marketing_permissions', ['marketing.campaign.create'])
            ->defaults('marketing_fields', []);

        Route::get('marketing/campaigns/{campaign}/context', [\App\Modules\Marketing\Controllers\Api\V1\MarketingWorkspaceController::class, 'campaignContext'])
            ->middleware(CheckAbilities::class.':marketing.campaigns.update')->name('marketing.campaigns.context')
            ->middleware(CheckAbilities::class.':marketing.context.read')
            ->defaults('marketing_ability', 'marketing.context.read')
            ->defaults('marketing_permissions', ['marketing.campaign.edit'])
            ->defaults('marketing_fields', []);

        Route::get('marketing/lookups/{kind}', [\App\Modules\Marketing\Controllers\Api\V1\MarketingWorkspaceController::class, 'lookup'])
            ->name('marketing.lookups')
            ->middleware(CheckAbilities::class.':marketing.context.read')
            ->defaults('marketing_ability', 'marketing.context.read')
            ->defaults('marketing_permissions', [])
            ->defaults('marketing_fields', ['page', 'per_page', 'q', 'list_id']);

        Route::get('marketing/campaigns/{campaign}/recipients', [\App\Modules\Marketing\Controllers\Api\V1\MarketingWorkspaceController::class, 'recipients'])
            ->name('marketing.campaigns.recipients')
            ->middleware(CheckAbilities::class.':marketing.read')
            ->defaults('marketing_ability', 'marketing.read')
            ->defaults('marketing_permissions', ['marketing.view'])
            ->defaults('marketing_fields', ['page', 'per_page', 'status', 'q']);

        Route::get('marketing/campaigns/{campaign}/content-sources', [\App\Modules\Marketing\Controllers\Api\V1\MarketingWorkspaceController::class, 'contentSources'])
            ->name('marketing.campaigns.content-sources')
            ->middleware(CheckAbilities::class.':marketing.read')
            ->defaults('marketing_ability', 'marketing.read')
            ->defaults('marketing_permissions', ['marketing.view'])
            ->defaults('marketing_fields', ['page', 'per_page']);

        Route::get('marketing/campaigns/{campaign}/events', [\App\Modules\Marketing\Controllers\Api\V1\MarketingWorkspaceController::class, 'events'])
            ->name('marketing.campaigns.events')
            ->middleware(CheckAbilities::class.':marketing.read')
            ->defaults('marketing_ability', 'marketing.read')
            ->defaults('marketing_permissions', ['marketing.view'])
            ->defaults('marketing_fields', ['page', 'per_page', 'type']);

        Route::post('marketing/campaigns/{campaign}/emails/preview', [\App\Modules\Marketing\Controllers\Api\V1\MarketingWorkspaceController::class, 'preview'])
            ->name('marketing.campaigns.emails.preview')
            ->middleware(CheckAbilities::class.':marketing.campaigns.update')
            ->defaults('marketing_ability', 'marketing.campaigns.update')
            ->defaults('marketing_permissions', ['marketing.campaign.edit'])
            ->defaults('marketing_fields', ['campaign_email_id', 'email_template_id', 'email_name', 'email_subject', 'body_html', 'body_text'])
            ->defaults('marketing_preview', true);

        Route::post('marketing/campaigns/{campaign}/content-sources/wordpress', [\App\Modules\Marketing\Controllers\Api\V1\MarketingWorkspaceController::class, 'pullWordPress'])
            ->name('marketing.campaigns.content-sources.wordpress')
            ->middleware(CheckAbilities::class.':marketing.campaigns.update')
            ->defaults('marketing_ability', 'marketing.campaigns.update')
            ->defaults('marketing_permissions', ['marketing.campaign.edit'])
            ->defaults('marketing_fields', ['wordpress_url', 'limit'])
            ->middleware(CheckAbilities::class.':marketing.providers.execute')
            ->defaults('marketing_provider', true)
            ->defaults('marketing_key_required', true);

        Route::post('marketing/campaigns/{campaign}/actions/preview', [\App\Modules\Marketing\Controllers\Api\V1\MarketingWorkspaceController::class, 'actionPreview'])
            ->name('marketing.campaigns.actions.preview')
            ->middleware(CheckAbilities::class.':marketing.campaigns.send')
            ->defaults('marketing_ability', 'marketing.campaigns.send')
            ->defaults('marketing_permissions', ['marketing.campaign.send'])
            ->defaults('marketing_fields', [])
            ->defaults('marketing_preview', true);

        Route::get('marketing/operations/{operation}', [\App\Modules\Marketing\Controllers\Api\V1\MarketingWorkspaceController::class, 'operation'])
            ->name('marketing.operations.show')
            ->middleware(CheckAbilities::class.':marketing.operations.read')
            ->defaults('marketing_ability', 'marketing.operations.read')
            ->defaults('marketing_permissions', [])
            ->defaults('marketing_fields', []);
    });

    return;
}

if (($marketingPublicRoutes ?? false) === true) {
    Route::get('/marketing/o/{token}', [PublicTrackingController::class, 'open'])
        ->name('marketing.track.open');
    Route::get('/marketing/c/{token}/{url}', [PublicTrackingController::class, 'click'])
        ->where('url', '.*')
        ->name('marketing.track.click');
    Route::get('/marketing/unsubscribe/{token}', [PublicTrackingController::class, 'unsubscribe'])
        ->name('marketing.unsubscribe');

    return;
}

Route::middleware(\App\Modules\Marketing\Support\MarketingMutationLock::class)->group(function (): void {
    Route::get('/marketing', [MarketingController::class, 'index'])
        ->name('marketing.index');

    Route::middleware('admin')->group(function (): void {
        Route::get('/admin/settings/marketing', [MarketingSettingsController::class, 'edit'])
            ->name('admin.settings.marketing');
        Route::put('/admin/settings/marketing', [MarketingSettingsController::class, 'update'])
            ->name('admin.settings.marketing.update');
    });

    Route::get('/marketing/campaigns', [MarketingCampaignController::class, 'index'])
        ->name('marketing.campaigns.index');
    Route::get('/marketing/campaigns/create', [MarketingCampaignController::class, 'create'])
        ->name('marketing.campaigns.create');
    Route::post('/marketing/campaigns', [MarketingCampaignController::class, 'store'])
        ->name('marketing.campaigns.store');
    Route::get('/marketing/campaigns/{campaign}', [MarketingCampaignController::class, 'show'])
        ->name('marketing.campaigns.show');
    Route::post('/marketing/campaigns/{campaign}/ai-plan', [MarketingCampaignController::class, 'draftPlanWithAi'])
        ->name('marketing.campaigns.ai-plan');
    Route::post('/marketing/campaigns/{campaign}/content-sources/wordpress', [MarketingCampaignController::class, 'pullWordPressContent'])
        ->name('marketing.campaigns.content-sources.wordpress');
    Route::put('/marketing/campaigns/{campaign}/schedule', [MarketingCampaignController::class, 'updateSchedule'])
        ->name('marketing.campaigns.schedule.update');
    Route::post('/marketing/campaigns/{campaign}/emails', [MarketingCampaignController::class, 'storeEmail'])
        ->name('marketing.campaigns.emails.store');
    Route::post('/marketing/campaigns/{campaign}/emails/preview', [MarketingCampaignController::class, 'previewEmail'])
        ->name('marketing.campaigns.emails.preview');
    Route::post('/marketing/campaigns/{campaign}/emails/ai-draft', [MarketingCampaignController::class, 'draftEmailWithAi'])
        ->name('marketing.campaigns.emails.ai-draft');
    Route::put('/marketing/campaigns/{campaign}/emails/{email}', [MarketingCampaignController::class, 'updateEmail'])
        ->name('marketing.campaigns.emails.update');
    Route::post('/marketing/campaigns/{campaign}/emails/{email}/test-send', [MarketingCampaignController::class, 'testSendEmail'])
        ->name('marketing.campaigns.emails.test-send');
    Route::delete('/marketing/campaigns/{campaign}/emails/{email}', [MarketingCampaignController::class, 'destroyEmail'])
        ->name('marketing.campaigns.emails.destroy');
    Route::post('/marketing/campaigns/{campaign}/approve', [MarketingCampaignController::class, 'approve'])
        ->name('marketing.campaigns.approve');
    Route::post('/marketing/campaigns/{campaign}/send-due', [MarketingCampaignController::class, 'sendDue'])
        ->name('marketing.campaigns.send-due');

    Route::get('/marketing/lists', [MarketingListController::class, 'index'])
        ->name('marketing.lists.index');
    Route::get('/marketing/lists/create', [MarketingListController::class, 'create'])
        ->name('marketing.lists.create');
    Route::post('/marketing/lists', [MarketingListController::class, 'store'])
        ->name('marketing.lists.store');
    Route::get('/marketing/lists/{list}', [MarketingListController::class, 'show'])
        ->name('marketing.lists.show');
    Route::get('/marketing/lists/{list}/edit', [MarketingListController::class, 'edit'])
        ->name('marketing.lists.edit');
    Route::put('/marketing/lists/{list}', [MarketingListController::class, 'update'])
        ->name('marketing.lists.update');
    Route::delete('/marketing/lists/{list}', [MarketingListController::class, 'destroy'])
        ->name('marketing.lists.destroy');
    Route::post('/marketing/lists/{list}/refresh', [MarketingListController::class, 'refresh'])
        ->name('marketing.lists.refresh');
    Route::post('/marketing/lists/{list}/contacts', [MarketingListController::class, 'addContacts'])
        ->name('marketing.lists.contacts.add');
    Route::delete('/marketing/lists/{list}/contacts/{contact}', [MarketingListController::class, 'removeContact'])
        ->name('marketing.lists.contacts.remove');

});
