<?php

declare(strict_types=1);

use App\Counter\Http\CounterController;
use App\Identity\Http\ActingUser;
use App\Identity\Http\EnrolledTerminal;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', EnrolledTerminal::class, ActingUser::class])->prefix('/api/counter')->group(function (): void {
    Route::get('/catalogue/snapshot', [CounterController::class, 'snapshot']);
    Route::get('/catalogue/changes', [CounterController::class, 'changes']);
    Route::post('/prices', [CounterController::class, 'prices']);
    Route::get('/barcodes/{code}', [CounterController::class, 'barcode'])->where('code', '.{1,120}');
    Route::post('/barcodes', [CounterController::class, 'learn']);
    Route::post('/items', [CounterController::class, 'createItem']);
    Route::get('/shift', [CounterController::class, 'shift']);
    Route::post('/shift', [CounterController::class, 'openShift']);
});
