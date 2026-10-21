<?php

/*
| Gestión comercial — CU-07, CU-08, CU-10, CU-11, CU-12, CU-15, CU-25, CU-31
*/

use App\Http\Controllers\Commercial;
use Illuminate\Support\Facades\Route;

Route::prefix('comercial')->group(function () {
    Route::crud('/proyectos', Commercial\ProjectController::class);
    Route::crud('/etapas', Commercial\ProjectStageController::class);
    Route::crud('/lotes', Commercial\LotController::class);
    Route::crud('/clientes', Commercial\ClientController::class);

    // Inventario de lotes: carga masiva (US-LOT-004), exportación (US-LOT-008) e historial de precios (US-LOT-005)
    Route::get('/lotes/carga-masiva', [Commercial\LotImportController::class, 'index'])->name('lots.import')->middleware('permission:lotes.importar');
    Route::get('/lotes/carga-masiva/plantilla', [Commercial\LotImportController::class, 'template'])->name('lots.import.template')->middleware('permission:lotes.importar');
    Route::post('/lotes/carga-masiva', [Commercial\LotImportController::class, 'store'])->name('lots.import.store')->middleware('permission:lotes.importar');
    Route::get('/lotes/exportar', [Commercial\LotController::class, 'export'])->name('lots.export')->middleware('permission:lotes.exportar');
    Route::get('/lotes/{id}/historial-precios', [Commercial\LotController::class, 'history'])->name('lots.history')->middleware('permission:lotes.ver')->whereNumber('id');

    // Acceso del cliente al portal (US-CLI-003)
    Route::controller(Commercial\ClientPortalController::class)->prefix('/clientes/{id}/portal')->name('clients.portal')
        ->whereNumber('id')->middleware('permission:clientes.portal')->group(function () {
            Route::get('/', 'show')->name('');
            Route::post('/invitar', 'invite')->name('.invite');
            Route::post('/revocar', 'revoke')->name('.revoke');
        });

    // Plano interactivo del proyecto (docs/PLANOS.md)
    Route::controller(Commercial\ProjectPlanController::class)->prefix('/proyectos/{id}/plano')->name('projects.plan')
        ->whereNumber(['id', 'planId'])->group(function () {
            Route::get('/', 'show')->name('')->middleware('permission:proyectos.ver');
            Route::get('/{planId}/estado', 'status')->name('.status')->middleware('permission:proyectos.ver');
            Route::middleware('permission:proyectos.editar')->group(function () {
                Route::post('/', 'store')->name('.store');
                Route::post('/{planId}/sincronizar', 'sync')->name('.sync');
                Route::post('/{planId}/publicar', 'publish')->name('.publish');
                Route::post('/{planId}/despublicar', 'unpublish')->name('.unpublish');
                Route::post('/{planId}/reprocesar', 'reprocess')->name('.reprocess');
            });
        });

    // Fotos de la ficha de venta (carga masiva o una por una con anotación)
    Route::controller(Commercial\ProjectPhotoController::class)->prefix('/proyectos/{id}/fotos')->name('projects.photos')
        ->whereNumber(['id', 'photoId'])->group(function () {
            Route::get('/', 'index')->name('')->middleware('permission:proyectos.ver');
            Route::middleware('permission:proyectos.editar')->group(function () {
                Route::post('/', 'store')->name('.store');
                Route::post('/orden', 'reorder')->name('.reorder');
                Route::put('/{photoId}', 'update')->name('.update');
                Route::post('/{photoId}/portada', 'cover')->name('.cover');
                Route::delete('/{photoId}', 'destroy')->name('.destroy');
            });
        });

    // Precios de los lotes (valor por m² o planilla de Excel)
    Route::controller(Commercial\ProjectPriceController::class)->prefix('/proyectos/{id}/precios')->name('projects.prices')
        ->whereNumber('id')->group(function () {
            Route::get('/', 'index')->name('')->middleware('permission:proyectos.ver');
            Route::get('/planilla', 'template')->name('.template')->middleware('permission:proyectos.ver');
            Route::middleware('permission:lotes.editar')->group(function () {
                Route::post('/por-m2', 'applyPerM2')->name('.perM2');
                Route::post('/planilla', 'import')->name('.import');
            });
        });
    Route::crud('/solicitudes', Commercial\InformationRequestController::class);

    // Buscadores de los formularios de reservas y negociaciones (public/js/pickers.js)
    Route::get('/buscar/lotes', [Commercial\LookupController::class, 'lots'])->name('lookup.lots')->middleware('permission:lotes.ver');
    Route::get('/buscar/clientes', [Commercial\LookupController::class, 'clients'])->name('lookup.clients')->middleware('permission:clientes.ver');

    // Reservas (CU-11)
    Route::crud('/reservas', Commercial\ReservationController::class);
    Route::get('/reservas/{id}', [Commercial\ReservationController::class, 'show'])->name('reservations.show')->middleware('permission:reservas.ver')->whereNumber('id');
    Route::post('/reservas/{id}/anular', [Commercial\ReservationController::class, 'cancel'])->name('reservations.cancel')->middleware('permission:reservas.anular')->whereNumber('id');


});
