<?php

declare(strict_types=1);

use App\BackOffice\Http\BackOfficeController;
use App\BackOffice\Http\CostController;
use App\BackOffice\Http\SettingsController;
use App\Identity\Http\ActingUser;
use App\Identity\Http\EnrolledTerminal;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', EnrolledTerminal::class])->get('/back-office', [BackOfficeController::class, 'page'])->name('back-office');

Route::middleware(['web', EnrolledTerminal::class, ActingUser::class])->prefix('/api/back-office')->group(function (): void {
    Route::get('/me', [BackOfficeController::class, 'me']);
    Route::get('/dashboard', [BackOfficeController::class, 'dashboard']);
    Route::get('/costs', [CostController::class, 'queue']);
    Route::get('/costs/items/{item}', [CostController::class, 'item']);
    Route::post('/costs/preview', [CostController::class, 'preview']);
    Route::post('/costs', [CostController::class, 'save']);
    Route::get('/settings', [SettingsController::class, 'index']);
    Route::post('/settings', [SettingsController::class, 'update']);
});
