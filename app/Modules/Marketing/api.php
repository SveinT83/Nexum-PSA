<?php

use App\Modules\Marketing\Controllers\Api\V1\MarketingCampaignController;
use App\Modules\Marketing\Controllers\Api\V1\MarketingListController;
use App\Modules\Marketing\Controllers\Api\V1\MarketingSettingsController;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

Route::get('marketing/lists', [MarketingListController::class, 'index'])
    ->name('marketing.lists.index')
    ->middleware(CheckAbilities::class.':marketing.read');

Route::post('marketing/lists', [MarketingListController::class, 'store'])
    ->name('marketing.lists.store')
    ->middleware(CheckAbilities::class.':marketing.lists.manage');

Route::get('marketing/lists/{list}', [MarketingListController::class, 'show'])
    ->name('marketing.lists.show')
    ->middleware(CheckAbilities::class.':marketing.read');

Route::match(['put', 'patch'], 'marketing/lists/{list}', [MarketingListController::class, 'update'])
    ->name('marketing.lists.update')
    ->middleware(CheckAbilities::class.':marketing.lists.manage');

Route::delete('marketing/lists/{list}', [MarketingListController::class, 'destroy'])
    ->name('marketing.lists.destroy')
    ->middleware(CheckAbilities::class.':marketing.lists.manage');

Route::get('marketing/lists/{list}/members', [MarketingListController::class, 'members'])
    ->name('marketing.lists.members.index')
    ->middleware(CheckAbilities::class.':marketing.read');

Route::post('marketing/lists/{list}/refresh', [MarketingListController::class, 'refresh'])
    ->name('marketing.lists.refresh')
    ->middleware(CheckAbilities::class.':marketing.lists.manage');

Route::post('marketing/lists/{list}/contacts', [MarketingListController::class, 'addContacts'])
    ->name('marketing.lists.contacts.add')
    ->middleware(CheckAbilities::class.':marketing.lists.manage');

Route::delete('marketing/lists/{list}/contacts/{contact}', [MarketingListController::class, 'removeContact'])
    ->name('marketing.lists.contacts.remove')
    ->middleware(CheckAbilities::class.':marketing.lists.manage');

Route::get('marketing/campaigns', [MarketingCampaignController::class, 'index'])
    ->name('marketing.campaigns.index')
    ->middleware(CheckAbilities::class.':marketing.read');

Route::post('marketing/campaigns', [MarketingCampaignController::class, 'store'])
    ->name('marketing.campaigns.store')
    ->middleware(CheckAbilities::class.':marketing.campaigns.create');

Route::get('marketing/campaigns/{campaign}', [MarketingCampaignController::class, 'show'])
    ->name('marketing.campaigns.show')
    ->middleware(CheckAbilities::class.':marketing.read');

Route::match(['put', 'patch'], 'marketing/campaigns/{campaign}', [MarketingCampaignController::class, 'update'])
    ->name('marketing.campaigns.update')
    ->middleware(CheckAbilities::class.':marketing.campaigns.update');

Route::post('marketing/campaigns/{campaign}/ai-plan', [MarketingCampaignController::class, 'draftPlanWithAi'])
    ->name('marketing.campaigns.ai-plan')
    ->middleware(CheckAbilities::class.':marketing.campaigns.update');

Route::match(['put', 'patch'], 'marketing/campaigns/{campaign}/schedule', [MarketingCampaignController::class, 'updateSchedule'])
    ->name('marketing.campaigns.schedule.update')
    ->middleware(CheckAbilities::class.':marketing.campaigns.update');

Route::post('marketing/campaigns/{campaign}/emails', [MarketingCampaignController::class, 'storeEmail'])
    ->name('marketing.campaigns.emails.store')
    ->middleware(CheckAbilities::class.':marketing.campaigns.update');

Route::post('marketing/campaigns/{campaign}/emails/ai-draft', [MarketingCampaignController::class, 'draftEmailWithAi'])
    ->name('marketing.campaigns.emails.ai-draft')
    ->middleware(CheckAbilities::class.':marketing.campaigns.update');

Route::match(['put', 'patch'], 'marketing/campaigns/{campaign}/emails/{email}', [MarketingCampaignController::class, 'updateEmail'])
    ->name('marketing.campaigns.emails.update')
    ->middleware(CheckAbilities::class.':marketing.campaigns.update');

Route::post('marketing/campaigns/{campaign}/emails/{email}/test-send', [MarketingCampaignController::class, 'testSendEmail'])
    ->name('marketing.campaigns.emails.test-send')
    ->middleware(CheckAbilities::class.':marketing.campaigns.update');

Route::delete('marketing/campaigns/{campaign}/emails/{email}', [MarketingCampaignController::class, 'destroyEmail'])
    ->name('marketing.campaigns.emails.destroy')
    ->middleware(CheckAbilities::class.':marketing.campaigns.update');

Route::post('marketing/campaigns/{campaign}/approve', [MarketingCampaignController::class, 'approve'])
    ->name('marketing.campaigns.approve')
    ->middleware(CheckAbilities::class.':marketing.campaigns.approve');

Route::post('marketing/campaigns/{campaign}/send-due', [MarketingCampaignController::class, 'sendDue'])
    ->name('marketing.campaigns.send-due')
    ->middleware(CheckAbilities::class.':marketing.campaigns.send');

Route::get('marketing/settings', [MarketingSettingsController::class, 'show'])
    ->name('marketing.settings.show')
    ->middleware(CheckAbilities::class.':marketing.read');

Route::match(['put', 'patch'], 'marketing/settings', [MarketingSettingsController::class, 'update'])
    ->name('marketing.settings.update')
    ->middleware(CheckAbilities::class.':marketing.settings.update');
