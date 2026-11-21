<?php

use App\Http\Controllers\OperationsDashboardController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ShiftCalendarController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', OperationsDashboardController::class)->name('dashboard');
    Route::get('/shifts/calendar', [ShiftCalendarController::class, 'index'])->name('shifts.calendar');
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
});

require __DIR__.'/auth.php';
