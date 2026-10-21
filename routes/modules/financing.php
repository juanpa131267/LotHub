<?php

/*
| Financiación y cartera — CU-13, CU-14, CU-16
| Acuerdos de pago, obligaciones y plan de cuotas.
*/

use App\Http\Controllers\Financing\AgreementController;
use App\Http\Controllers\Financing\ObligationController;
use Illuminate\Support\Facades\Route;

Route::prefix('cartera')->group(function () {
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
