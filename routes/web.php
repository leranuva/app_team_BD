<?php

use App\Http\Controllers\ChangeOrderPageController;
use App\Http\Controllers\JobScopeController;
use App\Http\Controllers\MaterialListPageController;
use App\Http\Controllers\OperationsDashboardController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ShiftCalendarController;
use App\Http\Controllers\SupervisorHoursController;
use App\Http\Controllers\SupervisorScopeProgressController;
use App\Http\Controllers\SupervisorTeamController;
use App\Http\Controllers\WorkerHistoryController;
use App\Http\Controllers\WorkerScheduleController;
use App\Http\Controllers\WorkerTodayController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('/', OperationsDashboardController::class)->name('home');
    Route::get('/dashboard', OperationsDashboardController::class)->name('dashboard');
    Route::get('/worker/today', WorkerTodayController::class)->name('worker.today');
    Route::get('/worker/schedule', WorkerScheduleController::class)->name('worker.schedule');
    Route::get('/worker/history', WorkerHistoryController::class)->name('worker.history');
    Route::get('/supervisor/team', SupervisorTeamController::class)->name('supervisor.team');
    Route::get('/supervisor/scopes', SupervisorScopeProgressController::class)->name('supervisor.scopes');
    Route::get('/supervisor/hours', SupervisorHoursController::class)->name('supervisor.hours');
    Route::get('/shifts/calendar', [ShiftCalendarController::class, 'index'])->name('shifts.calendar');
    Route::get('/job-scopes/{scope}', [JobScopeController::class, 'show'])->whereNumber('scope')->name('job-scopes.show');
    Route::get('/material-lists', [MaterialListPageController::class, 'index'])->name('material-lists.index');
    Route::get('/material-lists/{list}', [MaterialListPageController::class, 'show'])->whereNumber('list')->name('material-lists.show');
    Route::get('/change-orders', [ChangeOrderPageController::class, 'index'])->name('change-orders.index');
    Route::get('/change-orders/{order}', [ChangeOrderPageController::class, 'show'])->whereNumber('order')->name('change-orders.show');
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
});

require __DIR__.'/auth.php';
