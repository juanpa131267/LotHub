<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Commercial\ProjectPhotoController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Dashboard\DashboardController;
use App\Http\Controllers\Portal\PortalController;
use App\Http\Controllers\Public\SiteController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Sitio público (sin autenticación) — CU-30, RF-21
|--------------------------------------------------------------------------
*/
Route::get('/', [SiteController::class, 'home'])->name('public.home');
Route::get('/proyectos/{id}', [SiteController::class, 'project'])->whereNumber('id')->name('public.project');
Route::get('/media/fotos/{photo}/{size}', [ProjectPhotoController::class, 'media'])
    ->whereNumber('photo')->whereIn('size', ['full', 'thumb'])->name('media.photo');
Route::post('/solicitudes-informacion', [SiteController::class, 'storeRequest'])
    ->middleware('throttle:public-form')->name('public.requests.store');

/*
|--------------------------------------------------------------------------
| Autenticación — CU-01, CU-02, CU-03
|--------------------------------------------------------------------------
*/
Route::middleware('guest')->group(function () {
    Route::get('/ingresar', [LoginController::class, 'show'])->name('login');
    Route::post('/ingresar', [LoginController::class, 'login'])->middleware('throttle:login')->name('login.submit');

    Route::get('/recuperar-clave', [PasswordResetController::class, 'showRequest'])->name('password.request');
    Route::post('/recuperar-clave', [PasswordResetController::class, 'sendLink'])->middleware('throttle:password')->name('password.email');
    Route::get('/restablecer-clave/{token}', [PasswordResetController::class, 'showReset'])->name('password.reset');
    Route::post('/restablecer-clave', [PasswordResetController::class, 'reset'])->name('password.update');
});

Route::middleware('auth')->group(function () {
    Route::post('/salir', [LoginController::class, 'logout'])->name('logout');
    Route::get('/inicio', [LoginController::class, 'redirect'])->name('home.redirect');

    Route::get('/panel', DashboardController::class)->middleware('permission:panel.acceder')->name('dashboard');
    Route::get('/mi-cuenta', [PortalController::class, 'index'])->middleware('permission:portal.acceder')->name('portal.index');

    // Módulos internos: cada archivo agrupa las rutas de un área funcional.
    require __DIR__.'/modules/security.php';
    require __DIR__.'/modules/catalogs.php';
    require __DIR__.'/modules/commercial.php';
});
