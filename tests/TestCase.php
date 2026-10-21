<?php

namespace Tests;

use App\Models\Catalogs\IdentificationType;
use App\Models\Commercial\Client;
use App\Models\Commercial\DataConsent;
use App\Models\Commercial\Lot;
use App\Models\Commercial\Project;
use App\Models\Security\Person;
use App\Models\Security\Role;
use App\Models\Security\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Base de las pruebas. Requiere la base test_lothub (bash tests/setup-test-db.sh), que trae el
 * esquema oficial con sus triggers y los datos base; cada prueba corre en una transacción.
 */
abstract class TestCase extends BaseTestCase
{
    use DatabaseTransactions;

    public function createApplication()
    {
        $app = require __DIR__.'/../bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        return $app;
    }

    protected function person(array $attributes = []): Person
    {
        return Person::create($attributes + [
            'identification_type_id' => IdentificationType::where('code', 'CC')->value('id'),
            'identification_number'  => (string) random_int(10_000_000, 99_999_999_99),
            'first_name'             => 'Ana',
            'last_name'              => 'Prueba '.Str::random(4),
            'email'                  => Str::lower(Str::random(10)).'@example.test',
        ]);
    }

    /** Usuario con los roles indicados (por nombre). */
    protected function user(array $roles = ['ADMINISTRADOR'], array $attributes = []): User
    {
        $user = User::create($attributes + [
            'person_id'      => $this->person()->id,
            'username'       => 'u_'.Str::lower(Str::random(8)),
            'password_hash'  => Hash::make('Secreta123'),
            'hash_algorithm' => 'bcrypt',
            'is_active'      => true,
        ]);
        $user->roles()->sync(Role::whereIn('name', $roles)->pluck('id'));

        return $user;
    }

    protected function client(bool $consent = true, array $person = []): Client
    {
        $p = $this->person($person);
        $client = Client::create([
            'person_id'     => $p->id,
            'client_code'   => Client::nextCode(),
            'registered_by' => User::value('id'),
        ]);

        if ($consent) {
            DataConsent::create(['person_id' => $p->id, 'policy_version' => '1.0', 'channel' => 'PRESENCIAL', 'granted_at' => now()]);
        }

        return $client;
    }

    protected function project(array $attributes = []): Project
    {
        return Project::create($attributes + [
            'code'       => 'P'.Str::upper(Str::random(6)),
            'name'       => 'Proyecto '.Str::random(5),
            'city_id'    => DB::table('cities')->value('id'),
            'status_id'  => 2,
            'created_by' => User::value('id'),
        ]);
    }

    protected function lot(?Project $project = null, array $attributes = []): Lot
    {
        return Lot::create($attributes + [
            'project_id' => ($project ?? $this->project())->id,
            'block'      => 'A',
            'lot_number' => (string) random_int(1, 999999),
            'area_m2'    => 200,
            'list_price' => 50_000_000,
            'status_id'  => 1,
        ]);
    }
}
