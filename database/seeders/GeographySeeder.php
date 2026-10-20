<?php

namespace Database\Seeders;

use App\Models\Catalogs\City;
use App\Models\Catalogs\State;
use Illuminate\Database\Seeder;

/** Departamentos y municipios del área de influencia (Alto Magdalena) con código DANE. */
class GeographySeeder extends Seeder
{
    private const DATA = [
        ['Cundinamarca', '25', [
            ['Girardot', '25307'], ['Ricaurte', '25612'], ['Agua de Dios', '25001'], ['Nilo', '25488'],
            ['Tocaima', '25815'], ['Nariño', '25483'], ['Jerusalén', '25368'], ['Guataquí', '25324'],
            ['Fusagasugá', '25290'], ['Bogotá D.C.', '11001'],
        ]],
        ['Tolima', '73', [
            ['Flandes', '73275'], ['Melgar', '73449'], ['Carmen de Apicalá', '73148'],
            ['Espinal', '73268'], ['Ibagué', '73001'], ['Coello', '73200'],
        ]],
    ];

    public function run(): void
    {
        foreach (self::DATA as [$stateName, $stateCode, $cities]) {
            $state = State::updateOrCreate(['dane_code' => $stateCode], ['name' => $stateName]);

            foreach ($cities as [$name, $code]) {
                City::updateOrCreate(['dane_code' => $code], ['state_id' => $state->id, 'name' => $name]);
            }
        }
    }
}
