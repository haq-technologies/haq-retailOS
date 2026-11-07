<?php

declare(strict_types=1);

use App\BackOffice\Http\BackOfficeController;
use App\BackOffice\Http\CatalogueController;
use App\BackOffice\Http\CostController;
use App\BackOffice\Http\FlagsController;
use App\BackOffice\Http\PricesController;
use App\BackOffice\Http\ReportsController;
use App\BackOffice\Http\ReviewController;
use App\BackOffice\Http\SalesController;
use App\BackOffice\Http\SettingsController;
use App\Identity\Http\ActingUser;
use App\Identity\Http\EnrolledTerminal;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', EnrolledTerminal::class])->get('/back-office', [BackOfficeController::class, 'page'])->name('back-office');

Route::middleware(['web', EnrolledTerminal::class, ActingUser::class])->prefix('/api/back-office')->group(function (): void {
    Route::get('/me', [BackOfficeController::class, 'me']);
    Route::get('/badges', [BackOfficeController::class, 'badges']);
    Route::get('/dashboard', [BackOfficeController::class, 'dashboard']);
    Route::get('/costs', [CostController::class, 'queue']);
    Route::get('/costs/items/{item}', [CostController::class, 'item']);
    Route::post('/costs/preview', [CostController::class, 'preview']);
    Route::post('/costs', [CostController::class, 'save']);
    Route::get('/flags', [FlagsController::class, 'index']);
    Route::get('/flags.csv', [FlagsController::class, 'csv']);
    Route::post('/flags/seen', [FlagsController::class, 'seen']);
    Route::get('/sales/{sale}', [SalesController::class, 'show']);
    Route::get('/review', [ReviewController::class, 'index']);
    Route::post('/review/brands/{brand}/approve', [ReviewController::class, 'approveBrand']);
    Route::post('/review/brands/{brand}/merge', [ReviewController::class, 'mergeBrand']);
    Route::post('/review/models/{value}/approve', [ReviewController::class, 'approveModel']);
    Route::post('/review/models/{value}/merge', [ReviewController::class, 'mergeModel']);
    Route::get('/review/groups', [ReviewController::class, 'groups']);
    Route::post('/review/categories', [ReviewController::class, 'createCategory']);
    Route::post('/review/items/{item}/approve', [ReviewController::class, 'approveItem']);
    Route::post('/review/items/{item}/merge', [ReviewController::class, 'mergeItem']);
    Route::post('/review/merges/{merge}/undo', [ReviewController::class, 'undoMerge']);
    Route::post('/review/duplicates/not-same', [ReviewController::class, 'notSame']);
    Route::post('/review/barcodes/{barcode}/undo', [ReviewController::class, 'undoBarcode']);
    Route::post('/review/barcodes/settle', [ReviewController::class, 'settleConflict']);
    Route::post('/review/aliases/{alias}/remove', [ReviewController::class, 'removeAlias']);
    Route::get('/prices/first-sales', [PricesController::class, 'firstSales']);
    Route::get('/prices/items/{item}', [PricesController::class, 'item']);
    Route::post('/prices', [PricesController::class, 'set']);
    Route::post('/prices/undo', [PricesController::class, 'undo']);
    Route::get('/reports/health', [ReportsController::class, 'health']);
    Route::get('/reports/summary', [ReportsController::class, 'summary']);
    Route::post('/reports/summary/print', [ReportsController::class, 'print']);
    Route::get('/exports/items.csv', [ReportsController::class, 'items']);
    Route::get('/exports/sales.csv', [ReportsController::class, 'sales']);
    Route::get('/catalogue/categories', [CatalogueController::class, 'categories']);
    Route::get('/catalogue/categories/{category}/form', [CatalogueController::class, 'form']);
    Route::get('/catalogue/items/{item}', [CatalogueController::class, 'show']);
    Route::post('/catalogue/items/{item}', [CatalogueController::class, 'update']);
    Route::get('/catalogue/search', [CatalogueController::class, 'search']);
    Route::get('/catalogue/names/{kind}', [CatalogueController::class, 'names'])->whereIn('kind', ['brands', 'values']);
    Route::get('/settings', [SettingsController::class, 'index']);
    Route::post('/settings', [SettingsController::class, 'update']);
});
