<?php

namespace App\Services\Migration;

use App\Models\Catalogs\ObligationStatus;
use App\Models\Financing\Obligation;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Throwable;

/**
 * Sprint 10 · Operación en paralelo: mientras la empresa sigue llevando sus hojas, en cada corte se
 * carga el saldo de cada negociación según la hoja y se compara con el saldo del sistema.
 * Resultado por obligación: COINCIDE, DIFERENCIA, NO_MIGRADA (en la hoja pero no en el sistema) o
 * SOLO_SISTEMA (migrada pero ausente en la hoja del corte).
 */
class ParallelRunService
{
    public const STATUSES = [
        'COINCIDE'     => 'Coincide',
        'DIFERENCIA'   => 'Diferencia',
        'NO_MIGRADA'   => 'No migrada',
        'SOLO_SISTEMA' => 'Solo en el sistema',
    ];

    /** Plantilla del corte, prellenada con las negociaciones migradas. */
    public function template(): string
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet()->setTitle('Corte');
        $sheet->fromArray([['Referencia en la hoja', 'Obligación (referencia)', 'Titular', 'Saldo según la hoja']], null, 'A1');
        $head = $sheet->getStyle('A1:D1');
        $head->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $head->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('224690');

        $rows = $this->migrated()->map(fn ($r) => [$r->legacy_reference, $r->obligation_code, trim("{$r->first_name} {$r->last_name}"), null])->values()->all();
        if ($rows) {
            $sheet->fromArray($rows, null, 'A2', true);
        }
        $sheet->getStyle('D2:D5000')->getNumberFormat()->setFormatCode('"$" #,##0');
        foreach (['A' => 22, 'B' => 22, 'C' => 32, 'D' => 20] as $col => $w) {
            $sheet->getColumnDimension($col)->setWidth($w);
        }

        $path = tempnam(sys_get_temp_dir(), 'corte').'.xlsx';
        (new Xlsx($book))->save($path);

        return $path;
    }

    public function compare(UploadedFile $file, CarbonImmutable $cutOff, float $tolerance): int
    {
        try {
            $sheet = IOFactory::load($file->getRealPath())->getActiveSheet();
        } catch (Throwable) {
            throw new RuntimeException('No se pudo leer el archivo del corte (.xlsx o .csv).');
        }

        $legacy = [];
        for ($r = 2; $r <= $sheet->getHighestDataRow(); $r++) {
            $reference = trim((string) $sheet->getCell("A{$r}")->getValue());
            $balance = $sheet->getCell("D{$r}")->getValue();
            if ($reference === '' || $balance === null || $balance === '') {
                continue;
            }
            $legacy[mb_strtoupper($reference)] = ['reference' => $reference, 'balance' => $this->amount($balance)];
        }
        if (! $legacy) {
            throw new RuntimeException('El archivo no tiene saldos: llena la columna «Saldo según la hoja».');
        }

        $result = [];
        $migrated = $this->migrated()->keyBy(fn ($r) => mb_strtoupper($r->legacy_reference));

        foreach ($legacy as $key => $item) {
            $row = $migrated[$key] ?? null;
            if (! $row) {
                $result[] = ['reference' => $item['reference'], 'obligation' => null, 'holder' => null, 'legacy' => $item['balance'], 'system' => null, 'difference' => null, 'status' => 'NO_MIGRADA'];
                continue;
            }
            $system = $this->systemBalance((int) $row->sale_id);
            $difference = round($system - $item['balance'], 2);
            $result[] = [
                'reference' => $row->legacy_reference, 'obligation' => $row->obligation_code, 'holder' => trim("{$row->first_name} {$row->last_name}"),
                'legacy' => $item['balance'], 'system' => $system, 'difference' => $difference,
                'status' => abs($difference) <= $tolerance ? 'COINCIDE' : 'DIFERENCIA',
            ];
        }
        foreach ($migrated as $key => $row) {
            if (! isset($legacy[$key])) {
                $result[] = ['reference' => $row->legacy_reference, 'obligation' => $row->obligation_code, 'holder' => trim("{$row->first_name} {$row->last_name}"),
                    'legacy' => null, 'system' => $this->systemBalance((int) $row->sale_id), 'difference' => null, 'status' => 'SOLO_SISTEMA'];
            }
        }

        $counts = collect($result)->countBy('status');
        $id = DB::table('parallel_runs')->insertGetId([
            'cut_off_date' => $cutOff->toDateString(),
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'compared' => count($result),
            'matched' => (int) ($counts['COINCIDE'] ?? 0),
            'differences' => count($result) - (int) ($counts['COINCIDE'] ?? 0),
            'tolerance' => $tolerance,
            'result' => json_encode($result, JSON_UNESCAPED_UNICODE),
            'run_by' => auth()->id(),
            'run_at' => now(),
        ]);
        AuditLogger::log('CORTE_PARALELO', 'parallel_runs', $id, [], ['corte' => $cutOff->toDateString(), 'coinciden' => (int) ($counts['COINCIDE'] ?? 0), 'total' => count($result)]);

        return $id;
    }

    public function export(object $run): string
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet()->setTitle('Comparación');
        $sheet->setCellValue('A1', "Operación en paralelo · corte {$run->cut_off_date} · tolerancia $ ".number_format((float) $run->tolerance, 0, ',', '.'));
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $sheet->fromArray([['Referencia', 'Obligación', 'Titular', 'Saldo hoja', 'Saldo sistema', 'Diferencia', 'Resultado']], null, 'A3');
        $sheet->getStyle('A3:G3')->getFont()->setBold(true);
        $rows = collect(json_decode($run->result, true))->map(fn ($r) => [
            $r['reference'], $r['obligation'], $r['holder'], $r['legacy'], $r['system'], $r['difference'], self::STATUSES[$r['status']],
        ])->all();
        if ($rows) {
            $sheet->fromArray($rows, null, 'A4', true);
        }
        $sheet->getStyle('D4:F5000')->getNumberFormat()->setFormatCode('"$" #,##0');
        foreach (['A' => 20, 'B' => 14, 'C' => 30, 'D' => 16, 'E' => 16, 'F' => 14, 'G' => 18] as $col => $w) {
            $sheet->getColumnDimension($col)->setWidth($w);
        }

        $path = tempnam(sys_get_temp_dir(), 'paralelo').'.xlsx';
        (new Xlsx($book))->save($path);

        return $path;
    }

    /** Valores de Excel o textos como «$ 45.000.000» o «45000000,50». */
    private function amount(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        $text = preg_replace('/[^\d,.\-]/', '', (string) $value);
        $text = preg_match('/^-?\d{1,3}(\.\d{3})+(,\d+)?$/', $text) ? str_replace(['.', ','], ['', '.'], $text) : str_replace(',', '.', $text);

        return is_numeric($text) ? (float) $text : 0.0;
    }

    /** Negociaciones migradas con su obligación vigente (si se reestructuró, la nueva). */
    private function migrated()
    {
        return DB::table('legacy_import_rows as r')
            ->join('clients as c', 'c.id', '=', 'r.client_id')->join('people as p', 'p.id', '=', 'c.person_id')
            ->join('obligations as o', 'o.id', '=', 'r.obligation_id')
            ->select('r.legacy_reference', 'r.sale_id', 'o.obligation_code', 'p.first_name', 'p.last_name')
            ->orderBy('r.id')->get();
    }

    /** Saldo actual de la obligación vigente de la negociación (0 si ya se canceló). */
    private function systemBalance(int $saleId): float
    {
        $obligation = Obligation::whereHas('agreement', fn ($q) => $q->where('sale_id', $saleId))
            ->whereIn('status_id', [ObligationStatus::ACTIVA, ObligationStatus::CANCELADA])->latest('id')->first();

        return $obligation ? round($obligation->summary()->balance, 2) : 0.0;
    }
}
