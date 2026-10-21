<?php

namespace Tests\Feature;

use App\Models\Commercial\Sale;
use App\Models\Financing\LateCharge;
use App\Models\Financing\Obligation;
use App\Models\Payments\Payment;
use App\Services\Financing\LateChargeService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/** Sprint 10: migración de la cartera vigente y cortes de la operación en paralelo. */
class ParallelOperationTest extends TestCase
{
    private function filled($user, string $route, array $rows, int $firstRow, bool $removeExisting = false): UploadedFile
    {
        $response = $this->actingAs($user)->get(route($route))->assertOk();
        $book = IOFactory::load($response->getFile()->getPathname());
        $sheet = $book->getActiveSheet();
        if ($removeExisting) {
            $sheet->removeRow($firstRow, 500);
        }
        $sheet->fromArray($rows, null, 'A'.$firstRow);
        $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        IOFactory::createWriter($book, 'Xlsx')->save($path);

        return new UploadedFile($path, 'cartera.xlsx', null, null, true);
    }

    /** Fila de migración: 60 M con 12 M de inicial y 12 cuotas mensuales desde hace 3 meses; pagado 22 M. */
    private function row(string $ref, $project, string $lot, array $overrides = []): array
    {
        $values = array_merge([
            'ref' => $ref, 'type' => 'CC', 'id' => '79'.random_int(100000, 999999), 'first' => 'Pedro', 'last' => 'Gaitán', 'email' => '', 'phone' => '3001112233',
            'consent' => 'SI', 'project' => $project->code, 'block' => 'A', 'lot' => $lot, 'sale_date' => now()->subMonths(4)->toDateString(),
            'price' => 60_000_000, 'modality' => 'FINANCIACION_DIRECTA', 'down' => 12_000_000, 'financed' => 48_000_000, 'count' => 12,
            'frequency' => 'MENSUAL', 'rate' => 0, 'late' => 2, 'first_due' => now()->subMonths(3)->toDateString(), 'paid' => 22_000_000,
            'last_payment' => now()->subMonth()->toDateString(), 'legacy_balance' => 38_000_000,
        ], $overrides);

        return array_values($values);
    }

    public function test_migrates_portfolio_with_plan_and_payments_made_before_the_system(): void
    {
        $clerk = $this->user(['AUXILIAR_CARTERA']);
        $project = $this->project();
        $this->lot($project, ['block' => 'A', 'lot_number' => '1']);
        $this->lot($project, ['block' => 'A', 'lot_number' => '2']);

        $file = $this->filled($clerk, 'parallel.template', [$this->row('HOJA-001', $project, '1'), $this->row('HOJA-002', $project, '2', ['paid' => 0])], 5);
        $this->actingAs($clerk)->post(route('parallel.import'), ['migration_file' => $file, 'late_from_today' => 1])->assertSessionHas('success');

        $row = DB::table('legacy_import_rows')->where('legacy_reference', 'HOJA-001')->first();
        $sale = Sale::find($row->sale_id);
        $obligation = Obligation::find($row->obligation_id);
        $this->assertSame(now()->subMonths(4)->toDateString(), $sale->sale_date->toDateString());
        $this->assertSame(13, $obligation->installments()->count());
        $this->assertSame('SALDO_MIGRADO', Payment::where('obligation_id', $obligation->id)->sole()->method->code);
        $this->assertEquals(38_000_000, $obligation->summary()->balance);          // 60 M − 22 M pagados

        // La mora se liquida desde la migración: el primer cálculo de hoy no cobra los días previos.
        $this->assertGreaterThan(0, LateCharge::whereHas('installment', fn ($q) => $q->where('obligation_id', $obligation->id))->count());
        $this->assertSame(0.0, app(LateChargeService::class)->run()['amount']);

        // El corte: una coincide, otra difiere y una referencia no está migrada.
        $cut = $this->filled($clerk, 'parallel.cut-template', [
            ['HOJA-001', '', '', 38_000_000], ['HOJA-002', '', '', 45_000_000], ['HOJA-999', '', '', 1_000_000],
        ], 2, removeExisting: true);
        $response = $this->actingAs($clerk)->post(route('parallel.compare'), ['cut_file' => $cut, 'cut_off_date' => now()->toDateString(), 'tolerance' => 1000]);
        $run = DB::table('parallel_runs')->latest('id')->first();
        $response->assertRedirect(route('parallel.show', $run->id));
        $this->assertSame(1, (int) $run->matched);
        $this->assertSame(2, (int) $run->differences);
        $this->actingAs($clerk)->get(route('parallel.show', $run->id))->assertOk()->assertSee('No migrada')->assertSee('Diferencia');
        $this->actingAs($clerk)->get(route('parallel.export', $run->id))->assertOk();
    }

    public function test_migration_is_atomic_and_reports_errors(): void
    {
        $clerk = $this->user(['AUXILIAR_CARTERA']);
        $project = $this->project();
        $this->lot($project, ['block' => 'A', 'lot_number' => '1']);

        $file = $this->filled($clerk, 'parallel.template', [
            $this->row('HOJA-010', $project, '1'),
            $this->row('HOJA-011', $project, '77', ['financed' => 40_000_000]),             // lote inexistente y suma errada
        ], 5);
        $this->actingAs($clerk)->post(route('parallel.import'), ['migration_file' => $file])->assertSessionHas('error');

        $this->assertCount(2, session('migration_result')['errors']);
        $this->assertSame(0, DB::table('legacy_import_rows')->count());
        $this->assertSame(0, Sale::count());
        $this->actingAs($clerk)->get(route('parallel.index'))->assertOk()->assertSee('Errores de la migración');
    }
}
