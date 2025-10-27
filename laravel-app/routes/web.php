<?php

use App\Http\Controllers\Admin\ActivityLogController as AdminActivityLogController;
use App\Http\Controllers\Admin\DeviceController as AdminDeviceController;
use App\Http\Controllers\Admin\ImportController as AdminImportController;
use App\Http\Controllers\BoardController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\PlanImportController;
use App\Http\Controllers\ReviewController;
use Illuminate\Support\Facades\Route;

Route::get('/', [BoardController::class, 'index'])->name('board');

Route::get('/plan/{plan}', [PlanController::class, 'show'])->name('plan.show');
Route::get('/plan/{plan}/partials/progress', [PlanController::class, 'progressPartial'])->name('plan.partials.progress');
Route::get('/plan/{plan}/partials/days', [PlanController::class, 'daysPartial'])->name('plan.partials.days');
Route::post('/plan/{plan}/submit', [PlanController::class, 'submit'])->name('plan.submit');

Route::get('/review', [ReviewController::class, 'queue'])->name('review.queue');
Route::get('/review/partials/queue', [ReviewController::class, 'queuePartial'])->name('review.partials.queue');
Route::post('/review/subtask/{subtask}/approve', [ReviewController::class, 'approve'])->name('review.approve');
Route::post('/review/subtask/{subtask}/deny', [ReviewController::class, 'deny'])->name('review.deny');

Route::post('/admin/plans/import', [PlanImportController::class, 'store'])->name('admin.plans.import');

Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('/devices', [AdminDeviceController::class, 'index'])->name('devices.index');
    Route::post('/devices/{device}/rename', [AdminDeviceController::class, 'rename'])->name('devices.rename');
    Route::post('/devices/{device}/link-user', [AdminDeviceController::class, 'linkUser'])->name('devices.link-user');

    Route::get('/import', [AdminImportController::class, 'show'])->name('import.show');
    Route::post('/import', [AdminImportController::class, 'store'])->name('import.store');

    Route::get('/activity', [AdminActivityLogController::class, 'index'])->name('activity.index');
    Route::get('/activity/entries', [AdminActivityLogController::class, 'entries'])->name('activity.entries');
});

