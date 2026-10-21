<?php

/*
| Pagos — CU-17 a CU-19 y motor de asignación automática (Sprint 6)
| Abonos manuales, aplicación a cuotas, reversión, extractos bancarios y cola de revisión.
*/

use App\Http\Controllers\Payments\BankStatementController;
use App\Http\Controllers\Payments\PaymentController;
use Illuminate\Support\Facades\Route;

Route::prefix('cartera')->group(function () {
    Route::crud('/abonos', PaymentController::class);
    Route::controller(PaymentController::class)->whereNumber(['id', 'obligationId'])->group(function () {
        Route::get('/abonos/{id}', 'show')->name('payments.show')->middleware('permission:abonos.ver');
        Route::post('/abonos/{id}/reversar', 'reverse')->name('payments.reverse')->middleware('permission:abonos.reversar');
        Route::get('/obligaciones/{obligationId}/abonos/crear', 'createFor')->name('payments.create-for')->middleware('permission:abonos.crear');
        Route::post('/obligaciones/{obligationId}/abonos', 'storeFor')->name('payments.store-for')->middleware('permission:abonos.crear');
    });

    Route::controller(BankStatementController::class)->middleware('permission:asignacion.gestionar')->whereNumber(['id', 'lineId'])->group(function () {
        Route::get('/extractos', 'index')->name('statements.index');
        Route::post('/extractos', 'store')->name('statements.store');
        Route::get('/extractos/plantilla', 'template')->name('statements.template');
        Route::get('/extractos/{id}', 'show')->name('statements.show');

        Route::get('/asignacion/revision', 'queue')->name('allocation.queue');
        Route::get('/asignacion/revision/{lineId}', 'review')->name('allocation.review');
        Route::post('/asignacion/revision/{lineId}/asignar', 'assign')->name('allocation.assign');
        Route::post('/asignacion/revision/{lineId}/descartar', 'discard')->name('allocation.discard');
    });

});
