<?php

/*
| Gestión comercial — CU-07, CU-08, CU-10, CU-31
| Próximos sprints: reservas (CU-11), negociaciones (CU-12), acuerdos y cartera.
*/

use App\Http\Controllers\Commercial;
use Illuminate\Support\Facades\Route;

Route::prefix('comercial')->group(function () {
    Route::crud('/proyectos', Commercial\ProjectController::class);
    Route::crud('/etapas', Commercial\ProjectStageController::class);
    Route::crud('/lotes', Commercial\LotController::class);
    Route::crud('/clientes', Commercial\ClientController::class);


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
});
