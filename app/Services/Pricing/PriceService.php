<?php

namespace App\Services\Pricing;

use App\Models\Catalogs\LotStatus;
use App\Models\Commercial\Lot;
use App\Models\Commercial\Project;
use App\Services\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Protection;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use RuntimeException;

/**
 * Precios de lista de los lotes de un proyecto. Dos formas:
 *  1. Automática: un valor general por m² (área × valor, redondeado al millar).
 *  2. Planilla de Excel: se descarga con todos los lotes del proyecto, se llena el precio de
 *     cada uno (p. ej. los cercanos al lago valen más) y se vuelve a cargar.
 * Los lotes vendidos no cambian de precio (su valor real está en la negociación).
 */
class PriceService
{
    /** Fila de encabezados de la planilla (los datos empiezan en la siguiente). */
    private const HEADER_ROW = 6;

    private const COLUMNS = [
        'A' => ['Código', 12], 'B' => ['Manzana', 10], 'C' => ['Lote', 8], 'D' => ['Etapa', 14],
        'E' => ['Ref. plano', 12], 'F' => ['Área (m²)', 11], 'G' => ['Estado', 13],
        'H' => ['Precio actual', 17], 'I' => ['Precio nuevo', 18], 'J' => ['Valor m² nuevo', 15], 'K' => ['Observación', 32],
    ];

    private const CONTROL_SHEET = 'control';

    public const SCOPES = [
        'available' => 'Solo lotes disponibles',
        'unsold'    => 'Disponibles, reservados y bloqueados',
    ];

    private const SCOPE_STATUSES = [
        'available' => [LotStatus::DISPONIBLE],
        'unsold'    => [LotStatus::DISPONIBLE, LotStatus::RESERVADO, LotStatus::BLOQUEADO],
    ];

    public function summary(Project $project): array
    {
        $row = DB::table('lots')->where('project_id', $project->id)->selectRaw(
            'COUNT(*) total, SUM(status_id = ?) available, MIN(list_price) min_price, MAX(list_price) max_price,
             AVG(list_price) avg_price, SUM(list_price) / NULLIF(SUM(area_m2), 0) avg_m2', [LotStatus::DISPONIBLE]
        )->first();

        return array_map(fn ($v) => $v === null ? null : (float) $v, (array) $row);
    }

    // ── 1. Precio general por m² ───────────────────────────────────────────
    public function applyPerM2(Project $project, float $pricePerM2, string $scope): int
    {
        $count = Lot::where('project_id', $project->id)
            ->whereIn('status_id', self::SCOPE_STATUSES[$scope])
            ->update(['list_price' => DB::raw('GREATEST(1000, ROUND(area_m2 * '.(float) $pricePerM2.', -3))'), 'updated_at' => now()]);

        AuditLogger::log('PRECIO_POR_M2', 'projects', $project->id, [], ['valor_m2' => $pricePerM2, 'alcance' => $scope, 'lotes' => $count]);

        return $count;
    }

    // ── 2. Planilla de Excel ───────────────────────────────────────────────
    /** Genera la planilla .xlsx y devuelve la ruta del archivo temporal. */
    public function template(Project $project): string
    {
        $lots = $this->lotsOf($project);
        $book = new Spreadsheet();
        $book->getProperties()->setCreator(config('app.name'))->setTitle("Precios {$project->code}");

        $sheet = $book->getActiveSheet()->setTitle('Precios');
        $last = self::HEADER_ROW + max(1, $lots->count());

        // Encabezado del documento.
        $sheet->setCellValue('A1', "Planilla de precios · {$project->name} ({$project->code})");
        $sheet->setCellValue('A2', 'Generada el '.now()->format('Y-m-d H:i').' · '.$lots->count().' lotes');
        $sheet->setCellValue('A3', 'Escribe el precio de lista (COP, sin decimales) en la columna «Precio nuevo». Las celdas vacías no cambian. '
            .'No modifiques las demás columnas ni la hoja; luego cárgala en LotHub → Proyecto → Precios.');
        $sheet->setCellValue('A4', 'Los lotes vendidos se incluyen como referencia pero no cambian de precio.');
        $sheet->mergeCells('A1:K1');
        $sheet->mergeCells('A3:K3');
        $sheet->mergeCells('A4:K4');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14)->getColor()->setRGB('10224A');
        $sheet->getStyle('A2:A4')->getFont()->setSize(10)->getColor()->setRGB('667085');
        $sheet->getStyle('A3')->getAlignment()->setWrapText(true);
        $sheet->getRowDimension(3)->setRowHeight(30);

        // Encabezados de columnas.
        foreach (self::COLUMNS as $col => [$title, $width]) {
            $sheet->setCellValue("{$col}".self::HEADER_ROW, $title);
            $sheet->getColumnDimension($col)->setWidth($width);
        }
        $header = $sheet->getStyle('A'.self::HEADER_ROW.':K'.self::HEADER_ROW);
        $header->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $header->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('224690');
        $header->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $sheet->getStyle('I'.self::HEADER_ROW)->getFill()->getStartColor()->setRGB('8A9A10');
        $sheet->getRowDimension(self::HEADER_ROW)->setRowHeight(22);

        // Filas de lotes.
        $row = self::HEADER_ROW + 1;
        foreach ($lots as $lot) {
            $sheet->fromArray([
                "{$lot->block}-{$lot->lot_number}", $lot->block, $lot->lot_number, $lot->stage?->name ?? '',
                $this->planRef($lot), (float) $lot->area_m2, $lot->status?->name, (float) $lot->list_price,
            ], null, "A{$row}", true);
            $sheet->setCellValue("J{$row}", "=IF(AND(ISNUMBER(I{$row}),F{$row}>0),ROUND(I{$row}/F{$row},0),\"\")");
            if ((int) $lot->status_id === LotStatus::VENDIDO) {
                $sheet->getStyle("A{$row}:K{$row}")->getFont()->getColor()->setRGB('98A2B3');
            }
            $row++;
        }

        $data = 'A'.(self::HEADER_ROW + 1).":K{$last}";
        $sheet->getStyle($data)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('E0E5EE');
        $sheet->getStyle('F'.(self::HEADER_ROW + 1).":F{$last}")->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle('H'.(self::HEADER_ROW + 1).":J{$last}")->getNumberFormat()->setFormatCode('"$" #,##0');

        // Columna editable: resaltada, desbloqueada y con validación (número entero ≥ 1.000).
        $editable = 'I'.(self::HEADER_ROW + 1).":I{$last}";
        $sheet->getStyle($editable)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F2F6D3');
        foreach ([$editable, 'K'.(self::HEADER_ROW + 1).":K{$last}"] as $range) {
            $sheet->getStyle($range)->getProtection()->setLocked(Protection::PROTECTION_UNPROTECTED);
        }
        $validation = $sheet->getCell('I'.(self::HEADER_ROW + 1))->getDataValidation();
        $validation->setType(DataValidation::TYPE_WHOLE)->setOperator(DataValidation::OPERATOR_GREATERTHANOREQUAL)
            ->setFormula1('1000')->setAllowBlank(true)->setShowErrorMessage(true)
            ->setErrorTitle('Precio no válido')->setError('Escribe el precio en pesos, como número entero (mínimo $1.000), o deja la celda vacía.');
        $validation->setSqref($editable);

        // Protección: solo se editan «Precio nuevo» y «Observación»; se permite ordenar y filtrar.
        $sheet->setAutoFilter('A'.self::HEADER_ROW.":K{$last}");
        $sheet->freezePane('B'.(self::HEADER_ROW + 1));
        $protection = $sheet->getProtection();
        $protection->setSheet(true)->setSort(false)->setAutoFilter(false)->setFormatColumns(false)->setSelectLockedCells(false);

        // Hoja oculta de control: evita cargar la planilla de otro proyecto.
        $control = new Worksheet($book, self::CONTROL_SHEET);
        $book->addSheet($control);
        $control->fromArray([['lothub-precios'], [$project->id], [$project->code], [$this->signature($project)]], null, 'A1');
        $control->setSheetState(Worksheet::SHEETSTATE_VERYHIDDEN);
        $book->setActiveSheetIndex(0);

        $path = tempnam(sys_get_temp_dir(), 'precios').'.xlsx';
        (new XlsxWriter($book))->save($path);

        return $path;
    }

    /**
     * Lee la planilla y aplica los precios. Es atómica: si hay un error en cualquier fila no se
     * cambia nada y se devuelven los errores para corregirlos.
     *
     * @return array{updated:int, unchanged:int, blank:int, sold:int, errors:array<int, array{row:int, code:string, message:string}>}
     */
    public function import(Project $project, UploadedFile $file): array
    {
        $reader = new XlsxReader();
        $reader->setReadDataOnly(true);
        try {
            $book = $reader->load($file->getRealPath());
        } catch (\Throwable) {
            throw new RuntimeException('El archivo no es una planilla de Excel válida (.xlsx).');
        }

        $control = $book->getSheetByName(self::CONTROL_SHEET);
        if (! $control || $control->getCell('A1')->getValue() !== 'lothub-precios') {
            throw new RuntimeException('Este archivo no es una planilla de precios de LotHub. Descárgala desde la pestaña Precios del proyecto.');
        }
        if ((int) $control->getCell('A2')->getValue() !== $project->id || $control->getCell('A4')->getValue() !== $this->signature($project)) {
            throw new RuntimeException('Esta planilla pertenece a otro proyecto ('.$control->getCell('A3')->getValue().'). Descarga la planilla de este proyecto.');
        }

        $sheet = $book->getSheetByName('Precios') ?? throw new RuntimeException('La planilla no tiene la hoja «Precios».');
        $lots = $this->lotsOf($project)->keyBy(fn ($l) => "{$l->block}-{$l->lot_number}");
        $result = ['updated' => 0, 'unchanged' => 0, 'blank' => 0, 'sold' => 0, 'errors' => []];
        $changes = [];
        $seen = [];

        $highest = $sheet->getHighestDataRow();
        for ($row = self::HEADER_ROW + 1; $row <= $highest; $row++) {
            $code = strtoupper(trim((string) $sheet->getCell("A{$row}")->getValue()));
            $raw = $sheet->getCell("I{$row}")->getValue();
            if ($code === '' && ($raw === null || $raw === '')) {
                continue;
            }
            // Closure con referencia: una arrow function (fn) capturaría $result por valor y los errores se perderían.
            $fail = function (string $message) use (&$result, $row, $code) {
                $result['errors'][] = ['row' => $row, 'code' => $code, 'message' => $message];
            };

            $lot = $lots[$code] ?? null;
            if (! $lot) {
                $fail('El código no corresponde a ningún lote del proyecto.');
                continue;
            }
            if (isset($seen[$code])) {
                $fail("Código repetido (también en la fila {$seen[$code]}).");
                continue;
            }
            $seen[$code] = $row;

            if ($raw === null || $raw === '') {
                $result['blank']++;
                continue;
            }
            $price = $this->parsePrice($raw);
            if ($price === null || $price < 1000 || $price > 999_999_999_999) {
                $fail('Precio no válido: «'.mb_strimwidth((string) $raw, 0, 30, '…').'». Escribe un número entero en pesos (mínimo $1.000).');
                continue;
            }
            if ((int) $lot->status_id === LotStatus::VENDIDO) {
                $result['sold']++;
                continue;
            }
            if (abs((float) $lot->list_price - $price) < 0.5) {
                $result['unchanged']++;
                continue;
            }
            $changes[$lot->id] = $price;
        }

        if ($result['errors']) {
            return $result;
        }

        DB::transaction(function () use ($changes) {
            foreach ($changes as $id => $price) {
                Lot::whereKey($id)->update(['list_price' => $price, 'updated_at' => now()]);
            }
        });
        $result['updated'] = count($changes);

        AuditLogger::log('PRECIOS_PLANILLA', 'projects', $project->id, [], [
            'archivo' => $file->getClientOriginalName(), 'actualizados' => $result['updated'], 'sin_cambio' => $result['unchanged'],
        ]);

        return $result;
    }

    // ── Internos ───────────────────────────────────────────────────────────
    private function lotsOf(Project $project)
    {
        return Lot::with(['stage', 'status'])->where('project_id', $project->id)->get()
            ->sortBy(fn ($l) => sprintf('%04d%-6s%08d%s', strlen($l->block), $l->block, (int) $l->lot_number, $l->lot_number))->values();
    }

    private function planRef(Lot $lot): string
    {
        return preg_match('/Ref\. plano: ([\w-]+)/u', (string) $lot->notes, $m) ? $m[1] : '';
    }

    /** Acepta números de Excel o textos como "$ 25.200.000", "25,200,000" o "25200000". */
    private function parsePrice(mixed $raw): ?float
    {
        if (is_int($raw) || is_float($raw)) {
            return round((float) $raw);
        }
        $text = trim((string) $raw);
        if (! preg_match('/^\$?\s*[\d.,\s]+$/', $text)) {
            return null;
        }
        $digits = preg_replace('/[^\d]/', '', preg_replace('/[.,]\d{1,2}$/', '', $text));

        return $digits === '' ? null : (float) $digits;
    }

    private function signature(Project $project): string
    {
        return substr(hash_hmac('sha256', "precios|{$project->id}|{$project->code}", (string) config('app.key')), 0, 16);
    }
}
