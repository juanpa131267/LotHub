<?php

use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\EnsurePasswordIsFresh;
use App\Http\Middleware\ForceHttps;
use App\Services\Plans\PlanService;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Request;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Uso en rutas: ->middleware('permission:lotes.ver,lotes.editar')  (basta con uno)
        $middleware->alias([
            'permission'     => CheckPermission::class,
            'password.fresh' => EnsurePasswordIsFresh::class,
        ]);
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('home.redirect'));

        // US-TEC-001: HTTPS obligatorio en producción. Detrás de un proxy (Nginx, balanceador,
        // Cloudflare) TRUSTED_PROXIES permite reconocer el esquema original (X-Forwarded-Proto).
        $middleware->prepend(ForceHttps::class);
        if ($proxies = env('TRUSTED_PROXIES')) {
            $middleware->trustProxies(at: $proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Archivo más grande de lo que acepta PHP: PHP descarta la petición completa antes de que
        // Laravel inicie la sesión, así que no sirve redirigir "con mensaje" (se perdería).
        // Se responde una página propia que explica el límite y ofrece volver.
        $exceptions->render(function (PostTooLargeException $e, Request $request) {
            $limitMb = PlanService::maxUploadMb();
            $sentMb = round((int) $request->server('CONTENT_LENGTH') / 1048576, 1);
            $message = "El archivo pesa {$sentMb} MB y el servidor acepta como máximo {$limitMb} MB.";

            if ($request->expectsJson()) {
                return response()->json(['ok' => false, 'message' => $message], 413);
            }

            $referer = (string) $request->headers->get('referer');
            $back = str_starts_with($referer, url('/')) ? $referer : url('/');

            return response()->view('errors.upload-too-large', compact('limitMb', 'sentMb', 'back'), 413);
        });
    })->create();
