<?php

use App\Http\Controllers\ActualWorkReviewController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DoctorMonthlyExclusionController;
use App\Http\Controllers\DoctorMonthlyShiftRestrictionController;
use App\Http\Controllers\DoctorRequestController;
use App\Http\Controllers\InitialWorkloadSetupController;
use App\Http\Controllers\MonthlySetupController;
use App\Http\Controllers\MonthlySetupRosterGenerationController;
use App\Http\Controllers\RosterAssignmentGenerationController;
use App\Http\Controllers\RosterAssignmentOptionsController;
use App\Http\Controllers\RosterAssignmentRegenerationController;
use App\Http\Controllers\RosterController;
use App\Http\Controllers\RosterFinalizationController;
use App\Http\Controllers\RosterManualEditController;
use App\Http\Controllers\RosterPdfController;
use App\Http\Controllers\RosterPrintController;
use App\Http\Controllers\RosterReopenController;
use App\Http\Middleware\EnsureMonthlySetupEditable;
use App\Http\Middleware\EnsureMonthlySetupPlanningPeriod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;

Route::get('/', fn (): RedirectResponse => to_route(auth()->check() ? 'dashboard' : 'login'))
    ->name('home');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:6,1')->name('login.store');
});

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', LogoutController::class)->name('logout');
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::prefix('/rosters/{year}/{month}')
        ->where(['year' => '[1-9][0-9]{3}', 'month' => '(?:[1-9]|1[0-2])'])
        ->group(function (): void {
            Route::get('/', [RosterController::class, 'show'])->name('rosters.show');
            Route::post('/', [RosterController::class, 'store'])->middleware(EnsureMonthlySetupPlanningPeriod::class)->name('rosters.store');
            Route::post('/generate', RosterAssignmentGenerationController::class)->name('rosters.generate');
            Route::post('/regenerate', RosterAssignmentRegenerationController::class)->name('rosters.regenerate');
            Route::post('/finalize', RosterFinalizationController::class)->name('rosters.finalize');
            Route::post('/reopen', RosterReopenController::class)->name('rosters.reopen');
            Route::get('/print', RosterPrintController::class)->name('rosters.print');
            Route::get('/pdf', RosterPdfController::class)->name('rosters.pdf');
            Route::get('/actual-work', [ActualWorkReviewController::class, 'show'])->name('rosters.actual-work.show');
            Route::put('/actual-work/assignments/{assignment}', [ActualWorkReviewController::class, 'save'])->name('rosters.actual-work.save');
            Route::delete('/actual-work/exceptions/{exception}', [ActualWorkReviewController::class, 'remove'])->name('rosters.actual-work.remove');
            Route::post('/actual-work/confirm', [ActualWorkReviewController::class, 'confirm'])->name('rosters.actual-work.confirm');
            Route::get('/assignment-options', RosterAssignmentOptionsController::class)->name('rosters.assignment-options');
            Route::post('/assignments/edit', [RosterManualEditController::class, 'edit'])->name('rosters.assignments.edit');
            Route::post('/assignments/undo', [RosterManualEditController::class, 'undo'])->name('rosters.assignments.undo');
        });

    Route::prefix('/initial-workload/{year}/{month}')
        ->where(['year' => '[1-9][0-9]{3}', 'month' => '(?:[1-9]|1[0-2])'])
        ->group(function (): void {
            Route::get('/', [InitialWorkloadSetupController::class, 'show'])->name('initial-workload.show');
            Route::post('/', [InitialWorkloadSetupController::class, 'save'])->name('initial-workload.save');
        });

    Route::prefix('/monthly-setup/{year}/{month}')
        ->where(['year' => '[1-9][0-9]{3}', 'month' => '(?:[1-9]|1[0-2])'])
        ->middleware(EnsureMonthlySetupPlanningPeriod::class)
        ->group(function (): void {
            Route::get('/', MonthlySetupController::class)->name('monthly-setup.show');
            Route::post('/generate-roster', MonthlySetupRosterGenerationController::class)->name('monthly-setup.generate-roster');
            Route::post('/requests', [DoctorRequestController::class, 'store'])->middleware(EnsureMonthlySetupEditable::class)->name('doctor-requests.store');
            Route::put('/requests/{doctorRequest}', [DoctorRequestController::class, 'update'])->middleware(EnsureMonthlySetupEditable::class)->name('doctor-requests.update');
            Route::delete('/requests/{doctorRequest}', [DoctorRequestController::class, 'destroy'])->middleware(EnsureMonthlySetupEditable::class)->name('doctor-requests.destroy');
            Route::post('/exclusions', [DoctorMonthlyExclusionController::class, 'store'])->middleware(EnsureMonthlySetupEditable::class)->name('monthly-exclusions.store');
            Route::delete('/exclusions/{doctorMonthlyExclusion}', [DoctorMonthlyExclusionController::class, 'destroy'])->middleware(EnsureMonthlySetupEditable::class)->name('monthly-exclusions.destroy');
            Route::post('/shift-restrictions', [DoctorMonthlyShiftRestrictionController::class, 'store'])->middleware(EnsureMonthlySetupEditable::class)->name('monthly-shift-restrictions.store');
            Route::put('/shift-restrictions/{doctorMonthlyShiftRestriction}', [DoctorMonthlyShiftRestrictionController::class, 'update'])->middleware(EnsureMonthlySetupEditable::class)->name('monthly-shift-restrictions.update');
            Route::delete('/shift-restrictions/{doctorMonthlyShiftRestriction}', [DoctorMonthlyShiftRestrictionController::class, 'destroy'])->middleware(EnsureMonthlySetupEditable::class)->name('monthly-shift-restrictions.destroy');
        });
});
