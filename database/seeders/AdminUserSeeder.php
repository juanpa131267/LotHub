<?php

namespace Database\Seeders;

use App\Models\Catalogs\IdentificationType;
use App\Models\Security\Person;
use App\Models\Security\Role;
use App\Models\Security\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Crea el usuario administrador inicial con los datos ADMIN_* del .env.
 * Si ADMIN_PASSWORD está vacío genera una contraseña aleatoria y la muestra
 * UNA sola vez en consola. No modifica un administrador ya existente.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $username = env('ADMIN_USERNAME', 'admin');

        if (User::withTrashed()->where('username', $username)->exists()) {
            $this->command?->info("El usuario «{$username}» ya existe; no se modifica.");

            return;
        }

        $generated = ! env('ADMIN_PASSWORD');
        $password = env('ADMIN_PASSWORD') ?: Str::password(14, symbols: false);

        $person = Person::firstOrCreate(
            ['identification_type_id' => IdentificationType::where('code', 'CC')->value('id'), 'identification_number' => '0000000000'],
            ['first_name' => 'Administrador', 'last_name' => 'del Sistema', 'email' => env('ADMIN_EMAIL', 'admin@lothub.local')],
        );

        $user = User::create([
            'person_id'      => $person->id,
            'username'       => $username,
            'password_hash'  => Hash::make($password),
            'hash_algorithm' => 'bcrypt',
            'is_active'      => true,
            // Una contraseña generada es temporal: se exige cambiarla en el primer ingreso (US-AUTH-004).
            'must_change_password' => $generated,
        ]);

        $user->roles()->attach(Role::where('name', 'ADMINISTRADOR')->value('id'));

        $this->command?->warn("Administrador creado → usuario: {$username}  contraseña: {$password}");
        $this->command?->warn($generated ? 'El sistema pedirá cambiarla en el primer ingreso.' : 'Cámbiala después del primer ingreso.');
    }
}
