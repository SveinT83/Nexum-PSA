<?php

use App\Modules\Intake\Controllers\Admin\IntakeController as AdminIntakeController;
use App\Modules\Intake\Controllers\Admin\IntakeFormController as AdminIntakeFormController;
use App\Modules\Intake\Controllers\Admin\IntakeSubmissionController as AdminIntakeSubmissionController;
use App\Modules\Intake\Controllers\Public\IntakeFormController as PublicIntakeFormController;
use Illuminate\Support\Facades\Route;

// Public JSON/multipart requests share HTML form validation and per-IP budgets.
if (($intakePublicApiRoutes ?? false) === true) {
    Route::get('public/intake/forms/{form:slug}', [\App\Modules\Intake\Controllers\Api\V1\PublicIntakeController::class, 'show'])
        ->middleware(\App\Modules\Intake\Support\IntakePublicThrottle::class.':60')->name('intake.public.forms.show');
    Route::post('public/intake/forms/{form:slug}/submissions', [\App\Modules\Intake\Controllers\Api\V1\PublicIntakeController::class, 'store'])
        ->middleware(\App\Modules\Intake\Support\IntakePublicThrottle::class.':10')->name('intake.public.submissions.store');

    return;
}

if (($intakeApiRoutes ?? false) === true) {
    Route::middleware(\App\Modules\Intake\Support\IntakeApiAccess::class)->prefix('intake')->name('intake.')->group(function (): void {
        $read = [['intake.read', 'intake.view']];
        $manage = [['intake.forms.write', 'intake.manage']];
        $review = [['intake.submissions.review', 'intake.submission_review']];
        $route = [['intake.submissions.route', 'intake.submission_review']];
        $get = function (string $uri, string $method, string $name, array $branches): void {
            Route::get($uri, [\App\Modules\Intake\Controllers\Api\V1\IntakeWorkspaceController::class, $method])
                ->name($name)->defaults('intake_branches', $branches);
        };
        $get('context', 'context', 'context', $read);
        $get('lookups/{kind}', 'lookups', 'lookups', [...$manage, ...$review]);
        $get('forms/context', 'formContext', 'forms.context', $manage);
        $get('forms', 'forms', 'forms.index', $read);
        $get('forms/{form:slug}', 'form', 'forms.show', [...$read, ...$manage]);
        $get('submissions', 'submissions', 'submissions.index', $read);
        $get('submissions/{submission}', 'submission', 'submissions.show', [...$read, ...$review, ...$route]);
        $get('submissions/{submission}/events', 'events', 'submissions.events', $read);
        $get('submissions/{submission}/attachments', 'attachments', 'submissions.attachments', $read);
        $get('submissions/{submission}/attachments/{attachment}', 'download', 'submissions.attachments.download',
            [['intake.attachments.read', 'intake.view']]);
        $get('commands/{command}', 'command', 'commands.show', []);
        Route::post('forms', [\App\Modules\Intake\Controllers\Api\V1\IntakeCommandController::class, 'store'])
            ->name('forms.store')->defaults('intake_branches', $manage);
        Route::put('forms/{form:slug}', [\App\Modules\Intake\Controllers\Api\V1\IntakeCommandController::class, 'update'])
            ->name('forms.update')->defaults('intake_branches', $manage);
        Route::post('forms/{form:slug}/toggle', [\App\Modules\Intake\Controllers\Api\V1\IntakeCommandController::class, 'toggle'])
            ->name('forms.toggle')->defaults('intake_branches', $manage);
        foreach (['reviewed', 'outcome', 'link-existing', 'route-sales', 'route-ticket', 'route-task'] as $action) {
            Route::post('submissions/{submission}/'.$action, [\App\Modules\Intake\Controllers\Api\V1\IntakeCommandController::class, 'review'])
                ->name('submissions.'.$action)->defaults('intake_action', $action)
                ->defaults('intake_branches', str_starts_with($action, 'route-') ? $route : $review);
        }
        Route::post('previews', [\App\Modules\Intake\Controllers\Api\V1\IntakeCommandController::class, 'preview'])
            ->name('previews')->defaults('intake_branches', [...$manage, ...$review, ...$route]);
    });

    return;
}

if (($intakePublicRoutes ?? false) === true) {
    Route::get('/intake/forms/{form:slug}', [PublicIntakeFormController::class, 'show'])
        ->middleware(\App\Modules\Intake\Support\IntakePublicThrottle::class.':60')
        ->name('intake.forms.show');

    Route::post('/intake/forms/{form:slug}', [PublicIntakeFormController::class, 'store'])
        ->middleware(\App\Modules\Intake\Support\IntakePublicThrottle::class.':10')
        ->name('intake.forms.submit');

    Route::get('/intake/forms/{form:slug}/thanks', [PublicIntakeFormController::class, 'thanks'])
        ->middleware(\App\Modules\Intake\Support\IntakePublicThrottle::class.':60')
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
        Route::post('/submissions/{submission}/outcome', [AdminIntakeSubmissionController::class, 'markOutcome'])->name('submissions.outcome');
        Route::post('/submissions/{submission}/link-existing', [AdminIntakeSubmissionController::class, 'linkExisting'])->name('submissions.link-existing');
        Route::post('/submissions/{submission}/route-sales', [AdminIntakeSubmissionController::class, 'routeSales'])->name('submissions.route-sales');
        Route::post('/submissions/{submission}/route-ticket', [AdminIntakeSubmissionController::class, 'routeTicket'])->name('submissions.route-ticket');
        Route::post('/submissions/{submission}/route-task', [AdminIntakeSubmissionController::class, 'routeTask'])->name('submissions.route-task');
        Route::get('/submissions/{submission}/attachments/{attachment}', [AdminIntakeSubmissionController::class, 'downloadAttachment'])->name('attachments.download');
    });
