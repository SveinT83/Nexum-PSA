<?php

use App\Modules\Email\Controllers\Admin\AccountsController;
use App\Modules\Email\Controllers\Admin\ConfigController;
use App\Modules\Email\Controllers\Admin\RulesController;
use App\Modules\Email\Controllers\Tech\InboxController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Email Module Routes
|--------------------------------------------------------------------------
|
| These routes are loaded inside the authenticated /tech route group. Public
| route names intentionally remain stable so existing navigation and Blade
| links continue to work while Email owns Inbox and email administration.
|
*/

Route::get('/inbox', [InboxController::class, 'index'])
    ->name('inbox.index');
Route::post('/inbox/poll', [InboxController::class, 'poll'])
    ->name('inbox.poll');
Route::get('/inbox/show/{message}', [InboxController::class, 'show'])
    ->name('inbox.show');
Route::delete('/inbox/{message}', [InboxController::class, 'destroy'])
    ->name('inbox.delete');
Route::get('/inbox/attachments/{attachment}/download', [InboxController::class, 'download'])
    ->name('inbox.download');

Route::middleware('admin')->group(function () {
    Route::get('/admin/settings/email/accounts', [AccountsController::class, 'index'])
        ->name('admin.settings.email.accounts');
    Route::get('/admin/settings/email/accounts/create', [AccountsController::class, 'create'])
        ->name('admin.settings.email.accounts.create');
    Route::post('/admin/settings/email/accounts', [AccountsController::class, 'store'])
        ->name('admin.settings.email.accounts.store');
    Route::get('/admin/settings/email/accounts/{account}/edit', [AccountsController::class, 'edit'])
        ->name('admin.settings.email.accounts.edit');
    Route::put('/admin/settings/email/accounts/{account}', [AccountsController::class, 'update'])
        ->name('admin.settings.email.accounts.update');
    Route::post('/admin/settings/email/accounts/{account}/toggle', [AccountsController::class, 'toggleActive'])
        ->name('admin.settings.email.accounts.toggle');
    Route::post('/admin/settings/email/accounts/{account}/test', [AccountsController::class, 'test'])
        ->name('admin.settings.email.accounts.test');

    Route::get('/admin/settings/email/config', [ConfigController::class, 'index'])
        ->name('admin.settings.email.config');
    Route::post('/admin/settings/email/config', [ConfigController::class, 'update'])
        ->name('admin.settings.email.config.update');

    Route::get('/admin/settings/email/rules', [RulesController::class, 'index'])
        ->name('admin.settings.email.rules');
});
