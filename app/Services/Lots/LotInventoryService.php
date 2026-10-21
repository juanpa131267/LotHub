<?php

namespace App\Services\Lots;

use App\Models\Catalogs\LotStatus;
use App\Models\Commercial\Lot;
use App\Models\Commercial\Project;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use RuntimeException;

/**
 * Inventario de lotes en Excel:
 *  · US-LOT-004 Carga masiva: plantilla por proyecto que se llena y se vuelve a cargar. La carga
 *    es atómica: si una fila tiene un error no se crea ningún lote y se informa qué corregir.
 *  · US-LOT-008 Exportación del inventario con los mismos filtros del listado.
 */
class LotInventoryService
{
    private const HEADER_ROW = 5;

    private const TEMPLATE_COLUMNS = [
        'A' => ['Manzana *', 11], 'B' => ['Lote *', 9], 'C' => ['Etapa', 18],
        'D' => ['Área (m²) *', 12], 'E' => ['Precio de lista (COP) *', 20], 'F' => ['Observaciones', 40],
    ];

    private const CONTROL_SHEET = 'control';

    /** Máximo de filas por carga (protege la memoria del servidor). */
    public const MAX_ROWS = 5000;

    // ── Plantilla ──────────────────────────────────────────────────────────
    public function template(Project $project): string
    {
        $book = new Spreadsheet();
        $book->getProperties()->setCreator(config('app.name'))->setTitle("Carga de lotes {$project->code}");
        $sheet = $book->getActiveSheet()->setTitle('Lotes');

        $stages = $project->stages()->pluck('name')->all();
        $sheet->setCellValue('A1', "Carga masiva de lotes · {$project->name} ({$project->code})");
        $sheet->setCellValue('A2', 'Una fila por lote. Las columnas con * son obligatorias. Manzana + Lote no puede repetirse en el proyecto (RN-01).');
        $sheet->setCellValue('A3', $stages
            ? 'Etapas válidas: '.implode(', ', $stages).'. Déjala vacía si el lote no pertenece a una etapa.'
            : 'El proyecto no tiene etapas: deja la columna «Etapa» vacía.');
        foreach (['A1:F1', 'A2:F2', 'A3:F3'] as $range) {
            $sheet->mergeCells($range);
        }
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14)->getColor()->setRGB('10224A');
        $sheet->getStyle('A2:A3')->getFont()->setSize(10)->getColor()->setRGB('667085');

        foreach (self::TEMPLATE_COLUMNS as $col => [$title, $width]) {
            $sheet->setCellValue($col.self::HEADER_ROW, $title);
            $sheet->getColumnDimension($col)->setWidth($width);
        }
        $header = $sheet->getStyle('A'.self::HEADER_ROW.':F'.self::HEADER_ROW);
        $header->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $header->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('224690');

        // Fila de ejemplo que se puede borrar o sobrescribir.
        $sheet->fromArray(['A', '1', $stages[0] ?? '', 160.5, 45000000, 'Fila de ejemplo: reemplázala'], null, 'A'.(self::HEADER_ROW + 1));
        $sheet->getStyle('A'.(self::HEADER_ROW + 1).':F'.(self::HEADER_ROW + 1))->getFont()->setItalic(true)->getColor()->setRGB('98A2B3');

        $last = self::HEADER_ROW + self::MAX_ROWS;
        $sheet->getStyle('D'.(self::HEADER_ROW + 1).":D{$last}")->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle('E'.(self::HEADER_ROW + 1).":E{$last}")->getNumberFormat()->setFormatCode('"$" #,##0');
        $sheet->getStyle('A'.(self::HEADER_ROW + 1).":B{$last}")->getNumberFormat()->setFormatCode('@');

        if ($stages) {
            $validation = $sheet->getCell('C'.(self::HEADER_ROW + 1))->getDataValidation();
            $validation->setType(DataValidation::TYPE_LIST)->setAllowBlank(true)->setShowDropDown(true)
                ->setShowErrorMessage(true)->setErrorTitle('Etapa no válida')->setError('Elige una etapa del proyecto o deja la celda vacía.')
                ->setFormula1('"'.implode(',', array_map(fn ($s) => str_replace([',', '"'], ' ', $s), $stages)).'"');
            $validation->setSqref('C'.(self::HEADER_ROW + 1).":C{$last}");
        }
        $sheet->freezePane('A'.(self::HEADER_ROW + 1));

        $control = new Worksheet($book, self::CONTROL_SHEET);
        $book->addSheet($control);
        $control->fromArray([['lothub-lotes'], [$project->id], [$project->code], [$this->signature($project)]], null, 'A1');
        $control->setSheetState(Worksheet::SHEETSTATE_VERYHIDDEN);
        $book->setActiveSheetIndex(0);

        return $this->save($book, 'lotes');
    }

    // ── Carga ──────────────────────────────────────────────────────────────
    /**
     * @return array{created:int, skipped:int, errors:array<int, array{row:int, code:string, message:string}>}
     */
    public function import(Project $project, UploadedFile $file): array
    {
        $book = $this->load($file);
        $control = $book->getSheetByName(self::CONTROL_SHEET);

        if (! $control || $control->getCell('A1')->getValue() !== 'lothub-lotes') {
            throw new RuntimeException('Este archivo no es una plantilla de carga de lotes de LotHub. Descárgala desde Lotes → Carga masiva.');
        }
        if ((int) $control->getCell('A2')->getValue() !== $project->id || $control->getCell('A4')->getValue() !== $this->signature($project)) {
            throw new RuntimeException('La plantilla pertenece a otro proyecto ('.$control->getCell('A3')->getValue().'). Descarga la de este proyecto.');
        }

        $sheet = $book->getSheetByName('Lotes') ?? throw new RuntimeException('La plantilla no tiene la hoja «Lotes».');
        $highest = $sheet->getHighestDataRow();
        if ($highest - self::HEADER_ROW > self::MAX_ROWS) {
            throw new RuntimeException('La plantilla supera el máximo de '.number_format(self::MAX_ROWS, 0, ',', '.').' lotes por carga. Divídela en varios archivos.');
        }

        $stages = $project->stages()->get()->keyBy(fn ($s) => mb_strtolower(trim($s->name)));
        $existing = Lot::where('project_id', $project->id)->get(['block', 'lot_number'])
            ->mapWithKeys(fn ($l) => [mb_strtoupper("{$l->block}-{$l->lot_number}") => true]);

        $result = ['created' => 0, 'skipped' => 0, 'errors' => []];
        $rows = [];
        $seen = [];

        for ($row = self::HEADER_ROW + 1; $row <= $highest; $row++) {
            $values = array_map(fn ($c) => $sheet->getCell("{$c}{$row}")->getValue(), ['A', 'B', 'C', 'D', 'E', 'F']);
            [$block, $number, $stage, $area, $price, $notes] = $values;
            $block = mb_strtoupper(trim((string) $block));
            $number = trim((string) $number);
            $code = $block !== '' || $number !== '' ? "{$block}-{$number}" : '';

            if (collect($values)->every(fn ($v) => trim((string) $v) === '')) {
                continue;
            }
            if (str_starts_with((string) $notes, 'Fila de ejemplo')) {
                $result['skipped']++;
                continue;
            }

            $errors = [];
            if ($block === '' || mb_strlen($block) > 10) {
                $errors[] = 'Manzana obligatoria (máximo 10 caracteres).';
            }
            if ($number === '' || mb_strlen($number) > 10) {
                $errors[] = 'Número de lote obligatorio (máximo 10 caracteres).';
            }
            $areaValue = $this->number($area);
            if ($areaValue === null || $areaValue <= 0 || $areaValue > 99_999_999) {
                $errors[] = 'Área no válida: debe ser un número mayor que cero.';
            }
            $priceValue = $this->number($price);
            if ($priceValue === null || $priceValue < 1000 || $priceValue > 999_999_999_999) {
                $errors[] = 'Precio no válido: número entero en pesos (mínimo $1.000).';
            }
            $stageId = null;
            if (trim((string) $stage) !== '') {
                $stageId = $stages[mb_strtolower(trim((string) $stage))]->id ?? null;
                if (! $stageId) {
                    $errors[] = "La etapa «{$stage}» no existe en el proyecto.";
                }
            }
            $key = mb_strtoupper($code);
            if ($block !== '' && $number !== '') {
                if (isset($existing[$key])) {
                    $errors[] = 'Ya existe ese lote en el proyecto (RN-01).';
                } elseif (isset($seen[$key])) {
                    $errors[] = "Lote repetido en la plantilla (también en la fila {$seen[$key]}).";
                }
                $seen[$key] ??= $row;
            }

            if ($errors) {
                foreach ($errors as $message) {
                    $result['errors'][] = ['row' => $row, 'code' => $code, 'message' => $message];
                }
                continue;
            }

            $rows[] = [
                'project_id' => $project->id,
                'stage_id'   => $stageId,
                'block'      => $block,
                'lot_number' => $number,
                'area_m2'    => round($areaValue, 2),
                'list_price' => round($priceValue),
                'status_id'  => LotStatus::DISPONIBLE,
                'notes'      => trim((string) $notes) !== '' ? mb_substr(trim((string) $notes), 0, 255) : null,
                'created_at' => now(),
            ];
        }

        if ($result['errors']) {
            return $result;
        }
        if (! $rows) {
            throw new RuntimeException('La plantilla no tiene lotes para cargar.');
        }

        DB::transaction(function () use ($rows) {
            foreach (array_chunk($rows, 500) as $chunk) {
                Lot::insert($chunk);
            }
        });
        $result['created'] = count($rows);

        AuditLogger::log('CARGA_MASIVA_LOTES', 'projects', $project->id, [], [
            'archivo' => $file->getClientOriginalName(), 'lotes_creados' => $result['created'],
        ]);

        return $result;
    }

    // ── Exportación ────────────────────────────────────────────────────────
    /** @param Builder $query consulta de lotes ya filtrada (ver LotController::filteredQuery). */
    public function export(Builder $query, string $title): string
    {
        $lots = $query->with(['project', 'stage', 'status'])->orderBy('project_id')->orderBy('block')->orderBy('lot_number')->get();

        $book = new Spreadsheet();
        $book->getProperties()->setCreator(config('app.name'))->setTitle('Inventario de lotes');
        $sheet = $book->getActiveSheet()->setTitle('Inventario');

        $sheet->setCellValue('A1', 'Inventario de lotes · '.config('lothub.company.name'));
        $sheet->setCellValue('A2', $title.' · generado el '.now()->format('Y-m-d H:i').' · '.$lots->count().' lotes');
        $sheet->mergeCells('A1:K1');
        $sheet->mergeCells('A2:K2');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14)->getColor()->setRGB('10224A');
        $sheet->getStyle('A2')->getFont()->setSize(10)->getColor()->setRGB('667085');

        $headers = ['Proyecto', 'Código proyecto', 'Etapa', 'Manzana', 'Lote', 'Área (m²)', 'Precio de lista', 'Valor m²', 'Estado', 'Observaciones', 'Actualizado'];
        $sheet->fromArray($headers, null, 'A4');
        $head = $sheet->getStyle('A4:K4');
        $head->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $head->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('224690');

        $data = $lots->map(fn (Lot $l) => [
            $l->project?->name, $l->project?->code, $l->stage?->name, $l->block, $l->lot_number,
            (float) $l->area_m2, (float) $l->list_price, $l->area_m2 > 0 ? round($l->list_price / $l->area_m2) : null,
            $l->status?->name, $l->notes, ($l->updated_at ?? $l->created_at)?->format('Y-m-d'),
        ])->all();
        if ($data) {
            $sheet->fromArray($data, null, 'A5', true);
        }

        $last = 4 + max(1, count($data));
        $sheet->getStyle("F5:F{$last}")->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle("G5:H{$last}")->getNumberFormat()->setFormatCode('"$" #,##0');
        foreach (['A' => 28, 'B' => 14, 'C' => 16, 'D' => 10, 'E' => 8, 'F' => 12, 'G' => 18, 'H' => 14, 'I' => 13, 'J' => 40, 'K' => 12] as $col => $w) {
            $sheet->getColumnDimension($col)->setWidth($w);
        }
        $sheet->setAutoFilter("A4:K{$last}");
        $sheet->freezePane('A5');

        return $this->save($book, 'inventario');
    }

    // ── Internos ───────────────────────────────────────────────────────────
    private function load(UploadedFile $file): Spreadsheet
    {
        $reader = new XlsxReader();
        $reader->setReadDataOnly(true);

        try {
            return $reader->load($file->getRealPath());
        } catch (\Throwable) {
            throw new RuntimeException('El archivo no es una planilla de Excel válida (.xlsx).');
        }
    }

    private function save(Spreadsheet $book, string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix).'.xlsx';
        (new XlsxWriter($book))->save($path);

        return $path;
    }

    /** Números de Excel o textos como "160,5", "45.000.000" o "$ 45.000.000". */
    private function number(mixed $raw): ?float
    {
        if (is_int($raw) || is_float($raw)) {
            return (float) $raw;
        }
        $text = trim(str_replace(['$', ' '], '', (string) $raw));
        if ($text === '' || ! preg_match('/^[\d.,]+$/', $text)) {
            return null;
        }
        // "45.000.000" (miles con punto) o "160,5" (decimal con coma).
        if (preg_match('/^\d{1,3}(\.\d{3})+$/', $text)) {
            $text = str_replace('.', '', $text);
        }
        $text = str_replace(',', '.', $text);

        return is_numeric($text) ? (float) $text : null;
    }

    private function signature(Project $project): string
    {
        return substr(hash_hmac('sha256', "lotes|{$project->id}|{$project->code}", (string) config('app.key')), 0, 16);
    }
}
