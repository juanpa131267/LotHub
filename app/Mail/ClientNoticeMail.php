<?php

namespace App\Mail;

use App\Models\Commercial\ClientNotification;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Comunicación al cliente por correo (recordatorio, aviso de mora o confirmación de pago). */
class ClientNoticeMail extends Mailable
{
    public function __construct(public ClientNotification $notification) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->notification->type_label.' · '.config('lothub.company.name'));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.client-notice');
    }
}
