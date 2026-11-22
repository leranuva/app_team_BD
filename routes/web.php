<?php

use App\Http\Controllers\ChangeOrderPageController;
use App\Http\Controllers\JobScopeController;
use App\Http\Controllers\MaterialListPageController;
use App\Http\Controllers\OperationsDashboardController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ShiftCalendarController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', OperationsDashboardController::class)->name('dashboard');
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
