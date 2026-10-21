<?php

use App\Http\Controllers\Account\ProfileController;
use App\Http\Controllers\Auth\AccountActivationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Commercial\ProjectPhotoController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Dashboard\DashboardController;
use App\Http\Controllers\Financing\AccountStatementController;
use App\Http\Controllers\Payments\WompiWebhookController;
use App\Http\Controllers\Portal\OnlinePaymentController;
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
| Pasarela de pagos — CU-21 (sin sesión ni CSRF; se valida la firma del evento, RN-17)
|--------------------------------------------------------------------------
*/
Route::post('/pasarela/wompi/eventos', WompiWebhookController::class)->middleware('throttle:gateway')->name('gateway.wompi.events');

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

    // US-AUTH-008: el cliente crea su contraseña con el enlace que recibe al habilitarle el portal.
    Route::get('/activar-cuenta/{token}', [AccountActivationController::class, 'show'])->name('account.activate');
    Route::post('/activar-cuenta', [AccountActivationController::class, 'activate'])->middleware('throttle:password')->name('account.activate.store');
});

Route::middleware(['auth', 'password.fresh'])->group(function () {
    Route::post('/salir', [LoginController::class, 'logout'])->name('logout');
    Route::get('/inicio', [LoginController::class, 'redirect'])->name('home.redirect');

    // Cuenta propia: perfil (US-AUTH-007) y cambio de contraseña, obligatorio si es temporal (US-AUTH-004).
    Route::get('/mi-perfil', [ProfileController::class, 'show'])->name('profile.show');
    Route::put('/mi-perfil', [ProfileController::class, 'update'])->name('profile.update');
    Route::get('/cambiar-clave', [ProfileController::class, 'editPassword'])->name('password.change');
    Route::put('/cambiar-clave', [ProfileController::class, 'updatePassword'])->middleware('throttle:password')->name('password.change.update');

    Route::get('/panel', DashboardController::class)->middleware('permission:panel.acceder')->name('dashboard');
    Route::get('/mi-cuenta', [PortalController::class, 'index'])->middleware('permission:portal.acceder')->name('portal.index');
    Route::get('/mi-cuenta/obligaciones/{obligationId}/estado-cuenta', [AccountStatementController::class, 'portal'])
        ->middleware(['permission:portal.acceder', 'throttle:public-form'])->whereNumber('obligationId')->name('portal.statement');
    // Pago en línea (CU-20)
    Route::post('/mi-cuenta/obligaciones/{obligationId}/pagar', [OnlinePaymentController::class, 'pay'])
        ->middleware(['permission:portal.pagar', 'throttle:public-form'])->whereNumber('obligationId')->name('portal.pay');
    Route::get('/mi-cuenta/pagos/{reference}', [OnlinePaymentController::class, 'result'])
        ->middleware('permission:portal.pagar')->where('reference', '[A-Za-z0-9\-]+')->name('portal.payments.result');

    // Módulos internos: cada archivo agrupa las rutas de un área funcional.
    require __DIR__.'/modules/security.php';
    require __DIR__.'/modules/catalogs.php';
    require __DIR__.'/modules/commercial.php';
    require __DIR__.'/modules/financing.php';
    require __DIR__.'/modules/payments.php';
});
