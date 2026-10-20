<?php

/*
| Catálogos parametrizables — CU-06 (permiso catalogos.*)
| Los catálogos de estados son cerrados (los usan los triggers) y no se editan aquí.
*/

use App\Http\Controllers\Catalogs;
use Illuminate\Support\Facades\Route;

Route::prefix('catalogos')->group(function () {
    Route::crud('/departamentos', Catalogs\StateController::class);
    Route::crud('/municipios', Catalogs\CityController::class);
    Route::crud('/tipos-identificacion', Catalogs\IdentificationTypeController::class);
    Route::crud('/modalidades-pago', Catalogs\FinancingModalityController::class);
    Route::crud('/periodicidades', Catalogs\PaymentFrequencyController::class);
    Route::crud('/medios-pago', Catalogs\PaymentMethodController::class);
    Route::crud('/tipos-documento', Catalogs\DocumentTypeController::class);
});
