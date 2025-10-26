<?php

use App\Http\Controllers\PlanImportController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::post('/admin/plans/import', [PlanImportController::class, 'store'])->name('admin.plans.import');
