<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * US-AUTH-004: mientras la cuenta tenga una contraseña temporal (asignada por el
 * administrador) solo se permite cambiarla o cerrar la sesión.
 */
class EnsurePasswordIsFresh
{
    private const ALLOWED = ['password.change', 'password.change.update', 'logout'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->must_change_password && ! $request->routeIs(self::ALLOWED)) {
            if ($request->expectsJson()) {
                return response()->json(['ok' => false, 'message' => 'Debes cambiar tu contraseña antes de continuar.'], 403);
            }

            return redirect()->route('password.change');
        }

        return $next($request);
    }
}
