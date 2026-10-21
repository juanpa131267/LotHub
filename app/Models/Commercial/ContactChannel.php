<?php

namespace App\Models\Commercial;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Canal por el que el titular autorizó recibir comunicaciones de cobro (Ley 2300 de 2023, RN-23).
 * Clave primaria compuesta (client_id, channel); la FK fk_notif_channel impide notificar por
 * un canal no registrado.
 */
class ContactChannel extends Model
{
    const CHANNELS = [
        'EMAIL'    => 'Correo electrónico',
        'WHATSAPP' => 'WhatsApp',
        'SMS'      => 'Mensaje de texto (SMS)',
        'PORTAL'   => 'Portal de clientes',
        'LLAMADA'  => 'Llamada telefónica',
    ];

    protected $table = 'client_contact_channels';

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = null;

    protected $fillable = ['client_id', 'channel', 'contact_value', 'authorized_at', 'revoked_at'];

    protected function casts(): array
    {
        return ['authorized_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }
}
