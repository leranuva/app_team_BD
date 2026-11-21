<?php

use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\ShiftController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::prefix('attendances')->middleware('throttle:attendance-punch')->group(function (): void {
        Route::post('/clock-in', [AttendanceController::class, 'clockIn'])->name('api.attendances.clock-in');
        Route::post('/clock-out', [AttendanceController::class, 'clockOut'])->name('api.attendances.clock-out');
    });
    Route::get('/shifts', [ShiftController::class, 'index'])->middleware('throttle:60,1')->name('api.shifts.index');
    Route::get('/shifts/options', [ShiftController::class, 'options'])->middleware('throttle:shift-options')->name('api.shifts.options');
    Route::patch('/shifts/{shift}/reassign', [ShiftController::class, 'reassign'])
        ->middleware('throttle:shift-reassign')->whereNumber('shift')->name('api.shifts.reassign');
});
