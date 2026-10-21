<?php

namespace App\Services\Portal;

use App\Mail\AccountActivationMail;
use App\Models\Commercial\Client;
use App\Models\Security\PasswordReset;
use App\Models\Security\Role;
use App\Models\Security\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * US-CLI-003 Habilitar el acceso del cliente al portal (CU-10, extend).
 * RN-22: sin autorización de tratamiento de datos vigente no se crea el acceso.
 * La cuenta nace con una contraseña aleatoria que nadie conoce; el cliente define la suya
 * con el enlace de activación (US-AUTH-008), que vence en lothub.auth.activation_hours.
 */
class PortalAccessService
{
    public const NONE = 'Sin acceso';
    public const INVITED = 'Invitado';
    public const ACTIVE = 'Activo';
    public const DISABLED = 'Inactivo';

    /** Estado del acceso al portal de un cliente. */
    public function status(Client $client): string
    {
        $user = $this->userOf($client);

        return match (true) {
            ! $user || ! $user->roles->contains('name', 'CLIENTE') => self::NONE,
            ! $user->is_active                                   => self::DISABLED,
            $user->password_changed_at === null                  => self::INVITED,
            default                                              => self::ACTIVE,
        };
    }

    public function userOf(Client $client): ?User
    {
        return User::with('roles')->where('person_id', $client->person_id)->first();
    }

    /** Crea (o reutiliza) la cuenta con el rol CLIENTE y envía el enlace de activación. */
    public function invite(Client $client): User
    {
        $person = $client->person;

        if (! $client->hasActiveConsent()) {
            throw new RuntimeException('El cliente no tiene autorización de tratamiento de datos vigente (RN-22).');
        }
        if (! $person->email) {
            throw new RuntimeException('Registra el correo electrónico del cliente para enviarle el enlace de activación.');
        }

        $user = DB::transaction(function () use ($client, $person) {
            $user = $this->userOf($client);

            if (! $user) {
                $user = User::create([
                    'person_id'      => $person->id,
                    'username'       => $this->availableUsername(Str::lower($person->email)),
                    'password_hash'  => Hash::make(Str::random(40)),
                    'hash_algorithm' => 'bcrypt',
                    'is_active'      => true,
                ]);
            } elseif (! $user->is_active) {
                $user->forceFill(['is_active' => true])->save();
            }

            $role = Role::where('name', 'CLIENTE')->value('id');
            $user->roles()->syncWithoutDetaching([$role]);

            // Un enlace nuevo deja sin efecto los anteriores.
            PasswordReset::where('user_id', $user->id)->where('purpose', PasswordReset::ACTIVATION)
                ->whereNull('used_at')->update(['used_at' => now()]);

            return $user;
        });

        $token = PasswordReset::issue($user, PasswordReset::ACTIVATION, (int) config('lothub.auth.activation_hours') * 60);
        Mail::to($person->email)->send(new AccountActivationMail($user, route('account.activate', $token)));
        AuditLogger::log('INVITAR_PORTAL', 'users', $user->id, [], ['client_id' => $client->id]);

        return $user;
    }

    /** Desactiva la cuenta del portal (la persona y su historial se conservan). */
    public function revoke(Client $client): void
    {
        $user = $this->userOf($client) ?? throw new RuntimeException('El cliente no tiene acceso al portal.');

        if ($user->roles->contains(fn ($r) => $r->name !== 'CLIENTE')) {
            throw new RuntimeException('La cuenta también la usa un empleado; desactívala desde Seguridad → Usuarios.');
        }

        $user->forceFill(['is_active' => false])->save();
        AuditLogger::log('REVOCAR_PORTAL', 'users', $user->id, [], ['client_id' => $client->id]);
    }

    private function availableUsername(string $base): string
    {
        $username = $base;
        for ($i = 2; User::withTrashed()->where('username', $username)->exists(); $i++) {
            $username = "{$base}{$i}";
        }

        return $username;
    }
}
