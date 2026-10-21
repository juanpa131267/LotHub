<?php

namespace App\Services\Communications;

use App\Mail\ClientRequestAnsweredMail;
use App\Models\Commercial\Client;
use App\Models\Commercial\ClientRequest;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * CU-28 Radicar y CU-29 Atender consultas y reclamos (Ley 1581 de 2012, arts. 14 y 15).
 * RN-24: la fecha límite se calcula en días hábiles (10 para consultas, 15 para reclamos).
 */
class ClientRequestService
{
    public function file(Client $client, string $type, string $subject, string $description): ClientRequest
    {
        $request = ClientRequest::create([
            'client_id'    => $client->id,
            'request_type' => $type,
            'subject'      => mb_substr($subject, 0, 200),
            'description'  => $description,
            'status'       => 'RADICADA',
            'filed_at'     => now(),
            'due_date'     => ClientRequest::dueDateFor($type),
        ]);
        AuditLogger::log('RADICAR_SOLICITUD', 'client_requests', $request->id, [], ['tipo' => $type, 'vence' => $request->due_date->toDateString()]);

        return $request;
    }

    public function startProcessing(ClientRequest $request): void
    {
        if ($request->status !== 'RADICADA') {
            throw new RuntimeException('Solo una solicitud radicada pasa a trámite.');
        }
        $request->update(['status' => 'EN_TRAMITE']);
        AuditLogger::log('TRAMITAR_SOLICITUD', 'client_requests', $request->id);
    }

    /** Respuesta al titular: queda registrada y se le envía por correo si lo tiene. */
    public function answer(ClientRequest $request, string $response): void
    {
        if (! $request->isOpen()) {
            throw new RuntimeException('La solicitud ya fue respondida.');
        }

        $request->update(['status' => 'RESPONDIDA', 'response' => $response, 'answered_by' => auth()->id(), 'answered_at' => now()]);
        AuditLogger::log('RESPONDER_SOLICITUD', 'client_requests', $request->id, [], ['a_tiempo' => ! $request->due_date->endOfDay()->isPast()]);

        if ($email = $request->client->person?->email) {
            Mail::to($email)->send(new ClientRequestAnsweredMail($request));
        }
    }

    public function close(ClientRequest $request): void
    {
        if ($request->status !== 'RESPONDIDA') {
            throw new RuntimeException('Solo se cierra una solicitud respondida.');
        }
        $request->update(['status' => 'CERRADA']);
        AuditLogger::log('CERRAR_SOLICITUD', 'client_requests', $request->id);
    }
}
