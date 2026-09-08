<?php

use Illuminate\Support\Facades\Route;
use Modules\Arca\Http\Controllers\ArcaController;

Route::prefix((string) config('arca.route_prefix', 'api/arca'))
    ->middleware(config('arca.route_middleware', ['web', 'auth', 'verified']))
    ->group(function (): void {
        Route::get('point-of-sales', [ArcaController::class, 'pointOfSales'])->name('arca.point-of-sales.index');
        Route::get('point-of-sales/arca', [ArcaController::class, 'pointOfSalesFromArca'])->name('arca.point-of-sales.arca');
        Route::post('point-of-sales/sync', [ArcaController::class, 'syncPointOfSales'])->name('arca.point-of-sales.sync');
        Route::post('point-of-sales', [ArcaController::class, 'storePointOfSale'])->name('arca.point-of-sales.store');
        Route::post('vouchers/authorize', [ArcaController::class, 'authorizeVoucher'])->name('arca.vouchers.authorize');
    });
