<?php

namespace App\Services\Communications;

use App\Models\Commercial\Client;
use App\Models\Commercial\ContactChannel;
use App\Models\Security\User;
use App\Services\AuditLogger;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Canales de contacto autorizados por el titular (CU-10 include, Ley 2300 de 2023).
 * RN-22: sin autorización de tratamiento de datos vigente no se habilita ningún canal.
 * Revocar un canal conserva el registro con revoked_at (evidencia de la autorización previa).
 */
class ContactChannelService
{
    /** @return Collection<string, ContactChannel> canales registrados, por código */
    public function channels(Client $client): Collection
    {
        return ContactChannel::where('client_id', $client->id)->get()->keyBy('channel');
    }

    /** @return Collection<string, ContactChannel> solo los vigentes */
    public function active(Client $client): Collection
    {
        return $this->channels($client)->filter->isActive();
    }

    /**
     * @param array<string, array{enabled:bool, value:?string}> $wanted canal => estado deseado
     */
    public function sync(Client $client, array $wanted): void
    {
        $current = $this->channels($client);
        $enabling = collect($wanted)->filter(fn ($w) => ! empty($w['enabled']));

        if ($enabling->isNotEmpty() && ! $client->hasActiveConsent()) {
            throw new RuntimeException('El cliente no tiene autorización de tratamiento de datos vigente (RN-22).');
        }

        foreach (ContactChannel::CHANNELS as $code => $label) {
            $want = $wanted[$code] ?? ['enabled' => false];
            $row = $current[$code] ?? null;
            $value = trim((string) ($want['value'] ?? '')) ?: $this->defaultValue($client, $code);

            if (! empty($want['enabled'])) {
                if (! $value) {
                    throw new RuntimeException($code === 'PORTAL'
                        ? 'El cliente aún no tiene acceso al portal: habilítalo antes de autorizar este canal.'
                        : "Indica el dato de contacto para «{$label}».");
                }
                $renewed = ! $row || ! $row->isActive();
                ContactChannel::upsert([[
                    'client_id'     => $client->id,
                    'channel'       => $code,
                    'contact_value' => mb_substr($value, 0, 191),
                    'authorized_at' => $renewed ? now() : $row->authorized_at,
                    'revoked_at'    => null,
                ]], ['client_id', 'channel'], ['contact_value', 'authorized_at', 'revoked_at']);
                if ($renewed) {
                    AuditLogger::log('AUTORIZAR_CANAL', 'client_contact_channels', $client->id, [], ['canal' => $code]);
                }
            } elseif ($row && $row->isActive()) {
                ContactChannel::where('client_id', $client->id)->where('channel', $code)->update(['revoked_at' => now()]);
                AuditLogger::log('REVOCAR_CANAL', 'client_contact_channels', $client->id, [], ['canal' => $code]);
            }
        }
    }

    public function defaultValue(Client $client, string $channel): ?string
    {
        $person = $client->person;

        return match ($channel) {
            'EMAIL'                       => $person?->email,
            'SMS', 'WHATSAPP', 'LLAMADA'  => $person?->phone,
            'PORTAL'                      => User::where('person_id', $client->person_id)->value('username'),
            default                       => null,
        };
    }
}
