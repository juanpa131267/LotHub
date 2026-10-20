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
 * Datos de ejemplo para desarrollo: 3 proyectos con etapas y lotes.
 * NO ejecutar en producción.   php artisan db:seed --class=DemoDataSeeder
 */
class DemoDataSeeder extends Seeder
{
    private const PROJECTS = [
        ['PRY-001', 'Reserva del Magdalena', '25307', 'Vía Girardot – Nariño km 4',
         'Condominio campestre con vista al río Magdalena, vías internas pavimentadas, portería y zonas verdes.',
         ProjectStatus::EN_VENTA, ['A' => 10, 'B' => 10, 'C' => 8], 160, 42_000_000],
        ['PRY-002', 'Altos de Flandes', '73275', 'Sector El Pórtico, Flandes',
         'Lotes urbanizados a cinco minutos del puente Girardot–Flandes, con servicios públicos instalados.',
         ProjectStatus::EN_VENTA, ['1' => 12, '2' => 12], 120, 35_000_000],
        ['PRY-003', 'Hacienda Ricaurte', '25612', 'Vereda Manuel Norte, Ricaurte',
         'Parcelación para segunda vivienda con club house y piscina. Preventa en planeación.',
         ProjectStatus::EN_PLANEACION, ['A' => 6], 1000, 95_000_000],
    ];

    public function run(): void
    {
        $adminId = User::orderBy('id')->value('id');

        foreach (self::PROJECTS as [$code, $name, $dane, $address, $description, $status, $blocks, $area, $price]) {
            $project = Project::updateOrCreate(['code' => $code], [
                'name' => $name, 'city_id' => City::where('dane_code', $dane)->value('id'),
                'address' => $address, 'description' => $description, 'status_id' => $status,
                'launch_date' => now()->subMonths(3), 'created_by' => $adminId,
                'total_area_m2' => array_sum($blocks) * $area * 1.4,
            ]);

            $stage = ProjectStage::firstOrCreate(['project_id' => $project->id, 'name' => 'Etapa 1'], ['display_order' => 1]);

            foreach ($blocks as $block => $count) {
                for ($n = 1; $n <= $count; $n++) {
                    $lotArea = $area + ($n % 4) * 12;
                    Lot::firstOrCreate(
                        ['project_id' => $project->id, 'block' => (string) $block, 'lot_number' => (string) $n],
                        [
                            'stage_id'   => $stage->id,
                            'area_m2'    => $lotArea,
                            'list_price' => round($price * $lotArea / $area, -5),
                            // Algunos lotes bloqueados para ver la variedad de estados.
                            'status_id'  => $n % 9 === 0 ? LotStatus::BLOQUEADO : LotStatus::DISPONIBLE,
                        ],
                    );
                }
            }
        }
    }
}
