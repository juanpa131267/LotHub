<?php

namespace App\Mail;

use App\Models\Security\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class PasswordResetMail extends Mailable
{
    public function __construct(public User $user, public string $url) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Recupera tu contraseña · '.config('app.name'));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.password-reset', with: [
            'minutes' => config('lothub.auth.reset_token_minutes'),
        ]);
    }
}
