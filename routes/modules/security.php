<?php

/*
| Seguridad y administración — CU-04, CU-05, CU-26
| Route::crud registra index, datos, crear, editar, eliminar (y papelera si aplica)
| protegiendo cada acción con {PERMISSION}.ver / .crear / .editar / .eliminar.
*/

use App\Http\Controllers\Security\AuditLogController;
use App\Http\Controllers\Security\PermissionController;
use App\Http\Controllers\Security\RoleController;
use App\Http\Controllers\Security\RolePermissionController;
use App\Http\Controllers\Security\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('seguridad')->group(function () {
    Route::crud('/usuarios', UserController::class);
    Route::crud('/roles', RoleController::class);
    Route::crud('/permisos', PermissionController::class);
    Route::crud('/auditoria', AuditLogController::class);

    Route::middleware('permission:roles.asignar_permisos')->group(function () {
        Route::get('/permisos-por-rol', [RolePermissionController::class, 'index'])->name('role-permissions.index');
        Route::post('/permisos-por-rol/{role}/{permission}', [RolePermissionController::class, 'toggle'])->name('role-permissions.toggle');
    });
});
