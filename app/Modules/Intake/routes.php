<?php

use App\Modules\Intake\Controllers\Admin\IntakeController as AdminIntakeController;
use App\Modules\Intake\Controllers\Admin\IntakeFormController as AdminIntakeFormController;
use App\Modules\Intake\Controllers\Admin\IntakeSubmissionController as AdminIntakeSubmissionController;
use App\Modules\Intake\Controllers\Public\IntakeFormController as PublicIntakeFormController;
use Illuminate\Support\Facades\Route;

if (($intakePublicRoutes ?? false) === true) {
    Route::get('/intake/forms/{form:slug}', [PublicIntakeFormController::class, 'show'])
        ->middleware('throttle:60,1')
        ->name('intake.forms.show');

    Route::post('/intake/forms/{form:slug}', [PublicIntakeFormController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('intake.forms.submit');

    Route::get('/intake/forms/{form:slug}/thanks', [PublicIntakeFormController::class, 'thanks'])
        ->middleware('throttle:60,1')
        ->name('intake.forms.thanks');

    return;
}

Route::middleware('admin')
    ->prefix('/admin/system/intake')
    ->name('admin.system.intake.')
    ->group(function (): void {
        Route::get('/', [AdminIntakeController::class, 'index'])->name('index');
        Route::get('/forms/create', [AdminIntakeFormController::class, 'create'])->name('forms.create');
        Route::post('/forms', [AdminIntakeFormController::class, 'store'])->name('forms.store');
        Route::get('/forms/{form:slug}/edit', [AdminIntakeFormController::class, 'edit'])->name('forms.edit');
        Route::put('/forms/{form:slug}', [AdminIntakeFormController::class, 'update'])->name('forms.update');
        Route::post('/forms/{form:slug}/toggle', [AdminIntakeFormController::class, 'toggle'])->name('forms.toggle');
        Route::get('/submissions/{submission}', [AdminIntakeSubmissionController::class, 'show'])->name('submissions.show');
        Route::post('/submissions/{submission}/reviewed', [AdminIntakeSubmissionController::class, 'markReviewed'])->name('submissions.reviewed');
        Route::post('/submissions/{submission}/route-sales', [AdminIntakeSubmissionController::class, 'routeSales'])->name('submissions.route-sales');
        Route::get('/submissions/{submission}/attachments/{attachment}', [AdminIntakeSubmissionController::class, 'downloadAttachment'])->name('attachments.download');
    });
