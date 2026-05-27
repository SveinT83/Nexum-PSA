<?php

use App\Modules\Warroom\Controllers\Tech\WarroomController;
use Illuminate\Support\Facades\Route;

Route::get('/dashboard', WarroomController::class)
    ->name('dashboard');
