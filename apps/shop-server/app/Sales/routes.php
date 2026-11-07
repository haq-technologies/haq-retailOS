<?php

declare(strict_types=1);

use App\Identity\Http\ActingUser;
use App\Identity\Http\EnrolledTerminal;
use App\Sales\Http\JournalController;
use App\Sales\Http\ParkedController;
use App\Sales\Http\PriceController;
use App\Sales\Http\SaleController;
use Illuminate\Support\Facades\Route;

// The counter's journal after a restore needs only the enrolled device: it reports before anyone signs in.
Route::middleware(['web', EnrolledTerminal::class])->post('/api/counter/journal', [JournalController::class, 'report']);

Route::middleware(['web', EnrolledTerminal::class, ActingUser::class])->group(function (): void {
    Route::post('/api/counter/sales', [SaleController::class, 'finish']);
    Route::get('/api/counter/items/{item}/price', [PriceController::class, 'show']);
    Route::post('/api/counter/items/{item}/prices', [PriceController::class, 'store']);
    Route::get('/api/counter/parked', [ParkedController::class, 'index']);
    Route::post('/api/counter/parked', [ParkedController::class, 'store']);
    Route::post('/api/counter/parked/{parkedSale}/resume', [ParkedController::class, 'resume']);
    Route::post('/api/counter/parked/{parkedSale}/discard', [ParkedController::class, 'discard']);
});
