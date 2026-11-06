<?php

declare(strict_types=1);

use App\Platform\Http\HomeController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');
