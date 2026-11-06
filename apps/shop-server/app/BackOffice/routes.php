<?php

declare(strict_types=1);

use App\BackOffice\Http\SettingsController;
use App\Identity\Http\ActingUser;
use App\Identity\Http\EnrolledTerminal;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', EnrolledTerminal::class, ActingUser::class])->prefix('/api/back-office')->group(function (): void {
    Route::get('/settings', [SettingsController::class, 'index']);
    Route::post('/settings', [SettingsController::class, 'update']);
});
