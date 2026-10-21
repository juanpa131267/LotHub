<?php

namespace App\Mail;

use App\Models\Commercial\ClientRequest;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Respuesta a una consulta o reclamo del titular (CU-29). */
class ClientRequestAnsweredMail extends Mailable
{
    public function __construct(public ClientRequest $request) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Respuesta a tu '.mb_strtolower($this->request->type_label).' · '.config('lothub.company.name'));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.client-request-answered');
    }
}
