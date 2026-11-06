<?php

declare(strict_types=1);

use App\Platform\Http\HomeController;
use Illuminate\Support\Facades\Route;

// A counter opens the shop server's root and lands on the name tiles (or enrolment, if it is new).
Route::redirect('/', '/counter');

// The server's status page: version and database.
Route::get('/server', HomeController::class)->name('server');
