<?php

/*
| Financiación y cartera — CU-13, CU-14, CU-16, CU-23, CU-24
| Acuerdos de pago, obligaciones, plan de cuotas, cartera y mora y estados de cuenta.
*/

use App\Http\Controllers\Financing\AccountStatementController;
use App\Http\Controllers\Financing\AgreementController;
use App\Http\Controllers\Financing\ObligationController;
use App\Http\Controllers\Financing\PortfolioController;
use Illuminate\Support\Facades\Route;

Route::prefix('cartera')->group(function () {
    // Cartera y mora (CU-23)
    Route::get('/tablero', [PortfolioController::class, 'index'])->name('portfolio.index')->middleware('permission:cartera.ver');
    Route::get('/tablero/exportar', [PortfolioController::class, 'export'])->name('portfolio.export')->middleware('permission:cartera.exportar');

    // Estados de cuenta (CU-24)
    Route::post('/obligaciones/{obligationId}/estado-cuenta', [AccountStatementController::class, 'generate'])
        ->name('account-statements.generate')->middleware('permission:estados_cuenta.ver')->whereNumber('obligationId');
    Route::get('/estados-cuenta/{id}', [AccountStatementController::class, 'show'])
        ->name('account-statements.show')->middleware('permission:estados_cuenta.ver')->whereNumber('id');

    Route::crud('/obligaciones', ObligationController::class);
    Route::get('/obligaciones/{id}', [ObligationController::class, 'show'])->name('obligations.show')->middleware('permission:acuerdos.ver')->whereNumber('id');

    Route::controller(AgreementController::class)->whereNumber(['saleId', 'agreementId', 'obligationId'])->group(function () {
        Route::get('/acuerdos/pendientes', 'pending')->name('agreements.pending')->middleware('permission:acuerdos.ver');
        Route::middleware('permission:acuerdos.crear')->group(function () {
            Route::get('/negociaciones/{saleId}/acuerdo', 'create')->name('agreements.create');
            Route::post('/negociaciones/{saleId}/acuerdo', 'store')->name('agreements.store');
            Route::post('/acuerdos/{agreementId}/firma', 'sign')->name('agreements.sign');
        });
        Route::post('/negociaciones/{saleId}/acuerdo/simular', 'simulate')->name('agreements.simulate')->middleware('permission:acuerdos.crear,acuerdos.reestructurar');
        Route::middleware('permission:acuerdos.reestructurar')->group(function () {
            Route::get('/obligaciones/{obligationId}/reestructurar', 'restructureForm')->name('obligations.restructure');
            Route::post('/obligaciones/{obligationId}/reestructurar', 'restructure')->name('obligations.restructure.store');
        });
    });
});
