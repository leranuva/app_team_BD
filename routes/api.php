<?php

use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\ChangeOrderController;
use App\Http\Controllers\Api\JobScopeController as JobScopeApiController;
use App\Http\Controllers\Api\JobSiteController;
use App\Http\Controllers\Api\MaterialController;
use App\Http\Controllers\Api\MaterialListController;
use App\Http\Controllers\Api\ScopePhotoController;
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

    Route::get('/job-sites', [JobSiteController::class, 'index'])
        ->middleware('throttle:logistics-read')->name('api.job-sites.index');
    Route::get('/job-sites/{site}', [JobSiteController::class, 'show'])
        ->middleware('throttle:logistics-read')->whereNumber('site')->name('api.job-sites.show');

    Route::get('/job-scopes', [JobScopeApiController::class, 'index'])
        ->middleware('throttle:logistics-read')->name('api.job-scopes.index');
    Route::get('/job-scopes/{scope}', [JobScopeApiController::class, 'show'])
        ->middleware('throttle:logistics-read')->whereNumber('scope')->name('api.job-scopes.show');

    Route::get('/job-scopes/{scope}/photos', [ScopePhotoController::class, 'index'])
        ->middleware('throttle:scope-photo-read')->whereNumber('scope')->name('api.job-scopes.photos.index');
    Route::post('/job-scopes/{scope}/photos', [ScopePhotoController::class, 'store'])
        ->middleware('throttle:scope-photo-write')->whereNumber('scope')->name('api.job-scopes.photos.store');
    Route::patch('/scope-photos/{photo}/archive', [ScopePhotoController::class, 'archive'])
        ->middleware('throttle:scope-photo-write')->whereNumber('photo')->name('api.scope-photos.archive');
    Route::patch('/scope-photos/{photo}/restore', [ScopePhotoController::class, 'restore'])
        ->middleware('throttle:scope-photo-write')->whereNumber('photo')->name('api.scope-photos.restore');

    Route::get('/materials/categories', [MaterialController::class, 'categories'])
        ->middleware('throttle:materials-read')->name('api.materials.categories');
    Route::get('/materials/units', [MaterialController::class, 'units'])
        ->middleware('throttle:materials-read')->name('api.materials.units');
    Route::get('/materials', [MaterialController::class, 'index'])
        ->middleware('throttle:materials-read')->name('api.materials.index');
    Route::get('/materials/{material}', [MaterialController::class, 'show'])
        ->middleware('throttle:materials-read')->whereNumber('material')->name('api.materials.show');

    Route::get('/material-lists', [MaterialListController::class, 'index'])
        ->middleware('throttle:material-list-read')->name('api.material-lists.index');
    Route::post('/material-lists', [MaterialListController::class, 'store'])
        ->middleware('throttle:material-list-write')->name('api.material-lists.store');
    Route::get('/material-lists/{list}', [MaterialListController::class, 'show'])
        ->middleware('throttle:material-list-read')->whereNumber('list')->name('api.material-lists.show');
    Route::patch('/material-lists/{list}', [MaterialListController::class, 'update'])
        ->middleware('throttle:material-list-write')->whereNumber('list')->name('api.material-lists.update');
    Route::post('/material-lists/{list}/submit', [MaterialListController::class, 'submit'])
        ->middleware('throttle:material-list-write')->whereNumber('list')->name('api.material-lists.submit');
    Route::post('/material-lists/{list}/approve', [MaterialListController::class, 'approve'])
        ->middleware('throttle:material-list-write')->whereNumber('list')->name('api.material-lists.approve');
    Route::post('/material-lists/{list}/mark-ordered', [MaterialListController::class, 'markOrdered'])
        ->middleware('throttle:material-list-write')->whereNumber('list')->name('api.material-lists.mark-ordered');
    Route::post('/material-lists/{list}/register-delivery', [MaterialListController::class, 'registerDelivery'])
        ->middleware('throttle:material-list-write')->whereNumber('list')->name('api.material-lists.register-delivery');
    Route::post('/material-lists/{list}/register-consumption', [MaterialListController::class, 'registerConsumption'])
        ->middleware('throttle:material-list-write')->whereNumber('list')->name('api.material-lists.register-consumption');
    Route::post('/material-lists/{list}/cancel', [MaterialListController::class, 'cancel'])
        ->middleware('throttle:material-list-write')->whereNumber('list')->name('api.material-lists.cancel');

    Route::get('/change-orders', [ChangeOrderController::class, 'index'])
        ->middleware('throttle:change-order-read')->name('api.change-orders.index');
    Route::get('/change-orders/{order}', [ChangeOrderController::class, 'show'])
        ->middleware('throttle:change-order-read')->whereNumber('order')->name('api.change-orders.show');
    Route::post('/change-orders/{order}/submit', [ChangeOrderController::class, 'submit'])
        ->middleware('throttle:change-order-write')->whereNumber('order')->name('api.change-orders.submit');
    Route::post('/change-orders/{order}/start-review', [ChangeOrderController::class, 'startReview'])
        ->middleware('throttle:change-order-write')->whereNumber('order')->name('api.change-orders.start-review');
    Route::post('/change-orders/{order}/approve', [ChangeOrderController::class, 'approve'])
        ->middleware('throttle:change-order-write')->whereNumber('order')->name('api.change-orders.approve');
    Route::post('/change-orders/{order}/reject', [ChangeOrderController::class, 'reject'])
        ->middleware('throttle:change-order-write')->whereNumber('order')->name('api.change-orders.reject');
    Route::post('/change-orders/{order}/cancel', [ChangeOrderController::class, 'cancel'])
        ->middleware('throttle:change-order-write')->whereNumber('order')->name('api.change-orders.cancel');
});
