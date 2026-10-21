<?php

/*
| Operación en paralelo — Sprint 10
| Migración de la cartera vigente desde las hojas de cálculo y cortes de comparación.
*/

use App\Http\Controllers\Migration\ParallelOperationController;
use Illuminate\Support\Facades\Route;

Route::controller(ParallelOperationController::class)->prefix('operacion-paralelo')->name('parallel.')
    ->middleware('permission:migracion.gestionar')->whereNumber('id')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/plantilla', 'template')->name('template');
        Route::post('/migracion', 'import')->name('import');
        Route::get('/corte/plantilla', 'cutTemplate')->name('cut-template');
        Route::post('/corte', 'compare')->name('compare');
        Route::get('/corte/{id}', 'show')->name('show');
        Route::get('/corte/{id}/exportar', 'export')->name('export');
    });
