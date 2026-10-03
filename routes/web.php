<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DoctorMonthlyExclusionController;
use App\Http\Controllers\DoctorRequestController;
use App\Http\Controllers\MonthlySetupController;
use App\Http\Controllers\RosterAssignmentGenerationController;
use App\Http\Controllers\RosterAssignmentRegenerationController;
use App\Http\Controllers\RosterController;
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
            Route::post('/', [RosterController::class, 'store'])->name('rosters.store');
            Route::post('/generate', RosterAssignmentGenerationController::class)->name('rosters.generate');
            Route::post('/regenerate', RosterAssignmentRegenerationController::class)->name('rosters.regenerate');
        });

    Route::prefix('/monthly-setup/{year}/{month}')
        ->where(['year' => '[1-9][0-9]{3}', 'month' => '(?:[1-9]|1[0-2])'])
        ->group(function (): void {
            Route::get('/', MonthlySetupController::class)->name('monthly-setup.show');
            Route::post('/requests', [DoctorRequestController::class, 'store'])->name('doctor-requests.store');
            Route::put('/requests/{doctorRequest}', [DoctorRequestController::class, 'update'])->name('doctor-requests.update');
            Route::delete('/requests/{doctorRequest}', [DoctorRequestController::class, 'destroy'])->name('doctor-requests.destroy');
            Route::post('/exclusions', [DoctorMonthlyExclusionController::class, 'store'])->name('monthly-exclusions.store');
            Route::delete('/exclusions/{doctorMonthlyExclusion}', [DoctorMonthlyExclusionController::class, 'destroy'])->name('monthly-exclusions.destroy');
        });
});
