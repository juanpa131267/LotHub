<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restringe una ruta a los usuarios que tengan AL MENOS UNO de los permisos
 * indicados. El permiso "sistema.acceso_total" (config lothub.super_permission)
 * da paso a cualquier ruta. La resolución de permisos vive en User::hasPermission().
 *
 *   Route::get(...)->middleware('permission:lotes.ver');
 *   Route::get(...)->middleware('permission:cartera.ver,lotes.ver');
 */
class CheckPermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->guest(route('login'));
        }

        if ($user->hasAnyPermission($permissions)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['ok' => false, 'message' => 'No autorizado.'], 403);
        }

        return redirect()->route('home.redirect')
            ->with('error', 'No tienes permisos para acceder a esa sección.');
    }
}
