<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Services\Payments\OnlinePaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CU-21 Recibe los eventos de Wompi (URL de eventos configurada en el panel del comercio).
 * No usa sesión ni CSRF: la autenticidad se comprueba con la firma del evento (RN-17).
 * Wompi reintenta mientras no reciba 200, así que una firma inválida responde 401 y una
 * notificación repetida responde 200 sin repetir efectos.
 */
class WompiWebhookController extends Controller
{
    public function __invoke(Request $request, OnlinePaymentService $online): JsonResponse
    {
        $event = $request->json()->all();
        if (! is_array($event) || ! isset($event['event'])) {
            return response()->json(['ok' => false, 'message' => 'Evento no válido.'], 400);
        }

        $notification = $online->handleEvent($event);

        return $notification->signature_valid
            ? response()->json(['ok' => true])
            : response()->json(['ok' => false, 'message' => 'Firma no válida.'], 401);
    }
}
