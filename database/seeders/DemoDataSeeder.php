<?php

namespace Database\Seeders;

use App\Models\Catalogs\City;
use App\Models\Catalogs\LotStatus;
use App\Models\Catalogs\ProjectStatus;
use App\Models\Commercial\Lot;
use App\Models\Commercial\Project;
use App\Models\Commercial\ProjectStage;
use App\Models\Security\User;
use Illuminate\Database\Seeder;

/**
 * Datos de demostración: 6 proyectos con etapas y lotes y, encima, 18 meses de operación simulada
 * (DemoPortfolioSeeder). NO ejecutar en producción.
 *
 *   php artisan db:seed --class=DemoDataSeeder
 */
class DemoDataSeeder extends Seeder
{
    /** código, nombre, municipio (DANE), dirección, descripción, estado, manzanas => lotes, área base, precio base */
    public const PROJECTS = [
        ['PRY-001', 'Reserva del Magdalena', '25307', 'Vía Girardot – Nariño km 4',
         'Condominio campestre con vista al río Magdalena, vías internas pavimentadas, portería y zonas verdes.',
         ProjectStatus::EN_VENTA, ['A' => 10, 'B' => 10, 'C' => 8, 'D' => 12], 160, 42_000_000],
        ['PRY-002', 'Altos de Flandes', '73275', 'Sector El Pórtico, Flandes',
         'Lotes urbanizados a cinco minutos del puente Girardot–Flandes, con servicios públicos instalados.',
         ProjectStatus::EN_VENTA, ['1' => 12, '2' => 12, '3' => 14], 120, 35_000_000],
        ['PRY-003', 'Hacienda Ricaurte', '25612', 'Vereda Manuel Norte, Ricaurte',
         'Parcelación para segunda vivienda con club house y piscina. Preventa en planeación.',
         ProjectStatus::EN_PLANEACION, ['A' => 6], 1000, 95_000_000],
        ['DEM-004', 'Mirador de Melgar', '73449', 'Kilómetro 2 vía Melgar – Carmen de Apicalá',
         'Lotes campestres con vista a la cordillera, lago artificial, cancha múltiple y sendero ecológico.',
         ProjectStatus::EN_VENTA, ['A' => 15, 'B' => 15, 'C' => 15, 'D' => 15, 'E' => 15, 'F' => 15], 200, 58_000_000],
        ['DEM-005', 'Lagos de Nilo', '25488', 'Vereda Pueblo Nuevo, Nilo',
         'Condominio con dos lagos, kiosco social y vías en adoquín. Financiación directa hasta 60 meses.',
         ProjectStatus::EN_VENTA, ['A' => 20, 'B' => 20, 'C' => 20, 'D' => 20], 180, 47_000_000],
        ['DEM-006', 'Campestre Tocaima', '25815', 'Vía Tocaima – Agua de Dios km 1',
         'Lotes para vivienda campestre con acometida de agua y energía. Proyecto con ventas anteriores al sistema.',
         ProjectStatus::EN_VENTA, ['A' => 20, 'B' => 20, 'C' => 20], 150, 32_000_000],
    ];

    public function run(): void
    {
        $adminId = User::orderBy('id')->value('id');

        foreach (self::PROJECTS as [$code, $name, $dane, $address, $description, $status, $blocks, $area, $price]) {
            $project = Project::firstOrCreate(['code' => $code], [
                'name' => $name, 'city_id' => City::where('dane_code', $dane)->value('id'),
                'address' => $address, 'description' => $description, 'status_id' => $status,
                'launch_date' => now()->subMonths(20), 'created_by' => $adminId,
                'total_area_m2' => array_sum($blocks) * $area * 1.4,
            ]);

            $stages = [
                ProjectStage::firstOrCreate(['project_id' => $project->id, 'name' => 'Etapa 1'], ['display_order' => 1]),
                ProjectStage::firstOrCreate(['project_id' => $project->id, 'name' => 'Etapa 2'], ['display_order' => 2]),
            ];

            $i = 0;
            foreach ($blocks as $block => $count) {
                $stage = $stages[$i++ < count($blocks) / 2 ? 0 : 1];
                for ($n = 1; $n <= $count; $n++) {
                    $lotArea = $area + ($n % 4) * 12;
                    Lot::firstOrCreate(
                        ['project_id' => $project->id, 'block' => (string) $block, 'lot_number' => (string) $n],
                        [
                            'stage_id'   => $stage->id,
                            'area_m2'    => $lotArea,
                            // Los esquineros (n múltiplo de 5) valen un 8 % más.
                            'list_price' => round($price * $lotArea / $area * ($n % 5 === 0 ? 1.08 : 1), -5),
                            // Algunos lotes bloqueados (reservados para la constructora) para ver la variedad de estados.
                            'status_id'  => $n % 13 === 0 ? LotStatus::BLOQUEADO : LotStatus::DISPONIBLE,
                        ],
                    );
                }
            }
        }

        $this->call(DemoPortfolioSeeder::class);
    }
}
