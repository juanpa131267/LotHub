<?php

namespace Tests\Feature;

use App\Models\Commercial\Lot;
use App\Models\Commercial\LotPriceHistory;
use App\Models\Commercial\ProjectStage;
use App\Models\Security\User;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/** Ajustes del Sprint 2: US-LOT-004, US-LOT-005 y US-LOT-008. */
class LotInventoryTest extends TestCase
{
    public function test_price_changes_are_recorded_with_source_and_user(): void
    {
        $director = $this->user(['DIRECTOR_COMERCIAL']);
        $lot = $this->lot();

        $this->actingAs($director)->put(route('lots.update', $lot->id), [
            'project_id' => $lot->project_id, 'block' => $lot->block, 'lot_number' => $lot->lot_number,
            'area_m2' => 200, 'list_price' => 55_000_000, 'status_id' => 1,
        ])->assertRedirect(route('lots.index'));

        $history = LotPriceHistory::where('lot_id', $lot->id)->sole();
        $this->assertEquals(50_000_000, $history->previous_price);
        $this->assertEquals(55_000_000, $history->new_price);
        $this->assertSame(LotPriceHistory::FORM, $history->source);
        $this->assertSame($director->id, $history->changed_by);

        $this->actingAs($director)->get(route('lots.history', $lot->id))->assertOk()->assertSee('Formulario del lote');
    }

    public function test_bulk_load_creates_lots_atomically(): void
    {
        $director = $this->user(['DIRECTOR_COMERCIAL']);
        $project = $this->project();
        ProjectStage::create(['project_id' => $project->id, 'name' => 'Etapa 1', 'display_order' => 1]);
        $this->lot($project, ['block' => 'B', 'lot_number' => '7']);

        $file = $this->filledTemplate($director, $project->id, [
            ['A', '1', 'Etapa 1', 160.5, 45_000_000, 'Esquinero'],
            ['A', '2', '', '200', '52.000.000', ''],
        ]);
        $this->actingAs($director)->post(route('lots.import.store'), ['project_id' => $project->id, 'lots_file' => $file])
            ->assertSessionHas('success');
        $this->assertSame(3, Lot::where('project_id', $project->id)->count());
        $this->assertEquals(52_000_000, Lot::where('project_id', $project->id)->where('lot_number', '2')->value('list_price'));

        // Una sola fila con error impide toda la carga.
        $file = $this->filledTemplate($director, $project->id, [
            ['C', '1', '', 100, 30_000_000, ''],
            ['B', '7', '', 100, 30_000_000, ''],
            ['C', '2', 'Etapa X', 0, 10, ''],
        ]);
        $this->actingAs($director)->post(route('lots.import.store'), ['project_id' => $project->id, 'lots_file' => $file])
            ->assertSessionHas('error');
        $this->assertSame(3, Lot::where('project_id', $project->id)->count());
        $this->assertCount(4, session('import_result')['errors']);
    }

    public function test_inventory_export_respects_filters(): void
    {
        $advisor = $this->user(['ASESOR_COMERCIAL']);
        $project = $this->project();
        $this->lot($project, ['block' => 'A', 'lot_number' => '1']);
        $this->lot($project, ['block' => 'A', 'lot_number' => '2', 'status_id' => 4]);

        $response = $this->actingAs($advisor)->get(route('lots.export', ['project_id' => $project->id, 'status_id' => 1]))->assertOk();

        $sheet = IOFactory::load($response->getFile()->getPathname())->getActiveSheet();
        $this->assertSame('Proyecto', $sheet->getCell('A4')->getValue());
        $this->assertSame('1', (string) $sheet->getCell('E5')->getValue());
        $this->assertNull($sheet->getCell('A6')->getValue());
    }

    /** Descarga la plantilla del proyecto, le escribe las filas y la devuelve como archivo cargado. */
    private function filledTemplate(User $user, int $projectId, array $rows): UploadedFile
    {
        $response = $this->actingAs($user)->get(route('lots.import.template', ['project_id' => $projectId]))->assertOk();
        $book = IOFactory::load($response->getFile()->getPathname());
        $sheet = $book->getSheetByName('Lotes');
        $sheet->removeRow(6);
        $sheet->fromArray($rows, null, 'A6');

        $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        IOFactory::createWriter($book, 'Xlsx')->save($path);

        return new UploadedFile($path, 'lotes.xlsx', null, null, true);
    }
}
