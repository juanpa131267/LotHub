<?php

namespace App\Mail;

use App\Models\Security\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class AccountActivationMail extends Mailable
{
    public function __construct(public User $user, public string $url) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Activa tu acceso al portal · '.config('app.name'));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.account-activation', with: [
            'hours' => config('lothub.auth.activation_hours'),
        ]);
    }
}
