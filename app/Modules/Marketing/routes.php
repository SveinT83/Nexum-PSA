<?php

use App\Modules\Marketing\Controllers\PublicTrackingController;
use App\Modules\Marketing\Controllers\Admin\MarketingSettingsController;
use App\Modules\Marketing\Controllers\Tech\MarketingCampaignController;
use App\Modules\Marketing\Controllers\Tech\MarketingController;
use App\Modules\Marketing\Controllers\Tech\MarketingListController;
use Illuminate\Support\Facades\Route;

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
Route::post('/marketing/campaigns/{campaign}/emails', [MarketingCampaignController::class, 'storeEmail'])
    ->name('marketing.campaigns.emails.store');
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
Route::post('/marketing/lists/{list}/refresh', [MarketingListController::class, 'refresh'])
    ->name('marketing.lists.refresh');
