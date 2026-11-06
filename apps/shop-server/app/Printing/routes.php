<?php

declare(strict_types=1);

use App\Identity\Http\ActingUser;
use App\Identity\Http\EnrolledTerminal;
use App\Printing\Http\PrintingController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', EnrolledTerminal::class, ActingUser::class])->prefix('/api/counter')->group(function (): void {
    Route::get('/printer', [PrintingController::class, 'status']);
    Route::get('/print-jobs', [PrintingController::class, 'pending']);
    Route::post('/print-jobs/{printJob}/retry', [PrintingController::class, 'retry']);
    Route::post('/sales/{sale}/reprint', [PrintingController::class, 'reprint']);
    Route::post('/drawer/no-sale', [PrintingController::class, 'noSale']);
    Route::post('/drawer/key-open', [PrintingController::class, 'keyOpen']);
});
