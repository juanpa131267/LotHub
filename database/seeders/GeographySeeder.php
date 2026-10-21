<?php

namespace Database\Seeders;

use App\Models\Catalogs\State;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Departamentos y municipios de Colombia con su código DANE (DIVIPOLA): 33 departamentos
 * (incluido Bogotá D.C.) y 1.122 municipios, áreas no municipalizadas e isla.
 * Fuente: DANE — División Político-Administrativa, publicada en datos.gov.co (conjunto gdxc-w37w),
 * guardada en database/seeders/data/divipola.csv. Es idempotente: actualiza por código DANE.
 */
class GeographySeeder extends Seeder
{
    private const FILE = __DIR__.'/data/divipola.csv';

    public function run(): void
    {
        $handle = fopen(self::FILE, 'r') ?: throw new RuntimeException('No se encontró '.self::FILE);
        fgetcsv($handle, separator: ';');

        $states = [];
        $cities = [];
        while (($row = fgetcsv($handle, separator: ';')) !== false) {
            [$stateCode, $stateName, $cityCode, $cityName] = $row;
            $states[$stateCode] = $stateName;
            $cities[] = [$stateCode, $cityCode, $cityName];
        }
        fclose($handle);

        foreach ($states as $code => $name) {
            State::updateOrCreate(['dane_code' => $code], ['name' => $name]);
        }
        $stateIds = State::whereIn('dane_code', array_keys($states))->pluck('id', 'dane_code');

        $rows = array_map(fn ($c) => ['state_id' => $stateIds[$c[0]], 'dane_code' => $c[1], 'name' => $c[2]], $cities);
        foreach (array_chunk($rows, 300) as $chunk) {
            DB::table('cities')->upsert($chunk, ['dane_code'], ['state_id', 'name']);
        }

        $this->command?->info(count($states).' departamentos y '.count($cities).' municipios.');
    }
}
