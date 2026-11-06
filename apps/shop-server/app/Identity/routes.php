<?php

declare(strict_types=1);

use App\Identity\Http\ActingUser;
use App\Identity\Http\EnrolledTerminal;
use App\Identity\Http\IdentityController;
use Illuminate\Support\Facades\Route;

Route::middleware('web')->group(function (): void {
    Route::get('/enrol', [IdentityController::class, 'enrolForm'])->name('identity.enrol');
    Route::post('/enrol', [IdentityController::class, 'enrol'])->middleware('throttle:10,1');

    Route::middleware(EnrolledTerminal::class)->group(function (): void {
        Route::get('/sign-in', [IdentityController::class, 'tiles'])->name('identity.tiles');
        Route::post('/api/identity/pin', [IdentityController::class, 'pin']);

        Route::middleware(ActingUser::class)->group(function (): void {
            Route::get('/api/identity/me', [IdentityController::class, 'me']);
            Route::post('/api/identity/approvals', [IdentityController::class, 'approve']);
        });
    });
});
