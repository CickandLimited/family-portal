<?php

use App\Http\Controllers\BoardController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\PlanImportController;
use Illuminate\Support\Facades\Route;

Route::get('/', [BoardController::class, 'index'])->name('board');

Route::get('/plan/{plan}', [PlanController::class, 'show'])->name('plan.show');
Route::get('/plan/{plan}/partials/progress', [PlanController::class, 'progressPartial'])->name('plan.partials.progress');
Route::get('/plan/{plan}/partials/days', [PlanController::class, 'daysPartial'])->name('plan.partials.days');
Route::post('/plan/{plan}/submit', [PlanController::class, 'submit'])->name('plan.submit');

Route::post('/admin/plans/import', [PlanImportController::class, 'store'])->name('admin.plans.import');
