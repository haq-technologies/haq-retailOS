<?php

declare(strict_types=1);

use App\Counter\Http\CounterController;
use App\Identity\Http\ActingUser;
use App\Identity\Http\EnrolledTerminal;
use Illuminate\Support\Facades\Route;

// The counter page and its copy of the catalogue need only an enrolled device: the catalogue carries no
// cost, and a locked counter keeps its copy fresh (spec section 4) [R4, R5].
Route::middleware(['web', EnrolledTerminal::class])->group(function (): void {
    Route::get('/counter', [CounterController::class, 'page'])->name('counter');
    Route::get('/api/counter/catalogue/snapshot', [CounterController::class, 'snapshot']);
    Route::get('/api/counter/catalogue/changes', [CounterController::class, 'changes']);
    Route::get('/api/counter/catalogue/shapes', [CounterController::class, 'shapes']);
    Route::post('/api/counter/prices', [CounterController::class, 'prices']);
});

Route::middleware(['web', EnrolledTerminal::class, ActingUser::class])->prefix('/api/counter')->group(function (): void {
    Route::get('/barcodes/{code}', [CounterController::class, 'barcode'])->where('code', '.{1,120}');
    Route::post('/barcodes', [CounterController::class, 'learn']);
    Route::post('/items', [CounterController::class, 'createItem']);
    Route::post('/shapes', [CounterController::class, 'sellShape']);
    Route::get('/shift', [CounterController::class, 'shift']);
    Route::post('/shift', [CounterController::class, 'openShift']);
});
