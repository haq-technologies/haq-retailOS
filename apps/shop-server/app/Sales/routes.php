<?php

declare(strict_types=1);

use App\Identity\Http\ActingUser;
use App\Identity\Http\EnrolledTerminal;
use App\Sales\Http\SaleController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', EnrolledTerminal::class, ActingUser::class])->group(function (): void {
    Route::post('/api/counter/sales', [SaleController::class, 'finish']);
});
