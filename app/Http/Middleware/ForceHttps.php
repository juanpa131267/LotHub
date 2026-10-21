<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * US-TEC-001: con FORCE_HTTPS=true toda petición HTTP se redirige (301) a HTTPS y las
 * respuestas seguras llevan Strict-Transport-Security. La notificación de la pasarela
 * también llega por HTTPS, así que no hay excepciones.
 */
class ForceHttps
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('lothub.force_https')) {
            return $next($request);
        }

        if (! $request->secure()) {
            return redirect()->secure($request->getRequestUri(), 301);
        }

        $response = $next($request);
        $response->headers->set('Strict-Transport-Security', 'max-age='.config('lothub.hsts_max_age').'; includeSubDomains');

        return $response;
    }
}
