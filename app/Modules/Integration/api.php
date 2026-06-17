<?php

use App\Modules\Integration\Controllers\Api\V1\BookStackSyncController;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

Route::get('integrations/book-stack/status', [BookStackSyncController::class, 'status'])
    ->name('integrations.book-stack.status')
    ->middleware(CheckAbilities::class.':integration.bookstack.read');

Route::post('integrations/book-stack/test', [BookStackSyncController::class, 'test'])
    ->name('integrations.book-stack.test')
    ->middleware(CheckAbilities::class.':integration.bookstack.run');

Route::post('integrations/book-stack/pull', [BookStackSyncController::class, 'pull'])
    ->name('integrations.book-stack.pull')
    ->middleware(CheckAbilities::class.':integration.bookstack.run');

Route::post('integrations/book-stack/push', [BookStackSyncController::class, 'push'])
    ->name('integrations.book-stack.push')
    ->middleware(CheckAbilities::class.':integration.bookstack.run');
