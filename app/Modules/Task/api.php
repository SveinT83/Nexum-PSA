<?php

use App\Modules\Task\Controllers\Api\V1\TaskController;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

Route::get('tasks', [TaskController::class, 'index'])
    ->name('tasks.index')
    ->middleware(CheckAbilities::class.':tasks.read');

Route::post('tasks', [TaskController::class, 'store'])
    ->name('tasks.store')
    ->middleware(CheckAbilities::class.':tasks.create');

Route::get('tasks/{task}', [TaskController::class, 'show'])
    ->name('tasks.show')
    ->middleware(CheckAbilities::class.':tasks.read');

Route::match(['put', 'patch'], 'tasks/{task}', [TaskController::class, 'update'])
    ->name('tasks.update')
    ->middleware(CheckAbilities::class.':tasks.update');
