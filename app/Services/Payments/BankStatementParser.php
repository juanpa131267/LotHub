<?php

namespace App\Services\Payments;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

/**
 * Lee extractos bancarios en Excel (.xlsx, .xls) o CSV. Cada banco exporta columnas distintas,
 * así que se busca la fila de encabezados entre las primeras 20 y se reconocen sus nombres
 * más comunes. Solo se toman los créditos (valores positivos o la columna «crédito»).
 */
class BankStatementParser
{
    private const SYNONYMS = [
        'date'           => ['fecha', 'fecha transaccion', 'fecha movimiento', 'fecha operacion', 'fecha de transaccion'],
        'description'    => ['descripcion', 'concepto', 'detalle', 'descripcion movimiento', 'transaccion'],
        'reference'      => ['referencia', 'ref', 'referencia 1', 'numero documento', 'nro documento', 'comprobante', 'documento'],
        'payer_document' => ['documento depositante', 'nit', 'cedula', 'identificacion', 'nit originador', 'cc nit', 'documento originador'],
        'credit'         => ['credito', 'creditos', 'abono', 'abonos', 'valor credito', 'entrada'],
        'debit'          => ['debito', 'debitos', 'cargo', 'valor debito', 'salida'],
        'amount'         => ['valor', 'monto', 'importe', 'valor transaccion'],
    ];

    /**
     * @return array{lines: array<int, array{line_number:int, transaction_date:string, description:?string, reference:?string, payer_document:?string, amount:float}>,
     *               skipped:int, errors: array<int, array{row:int, message:string}>}
     */
    public function parse(string $path, string $extension): array
    {
        $rows = $this->read($path, strtolower($extension));
        [$headerRow, $map] = $this->findHeader($rows);

        $result = ['lines' => [], 'skipped' => 0, 'errors' => []];

        foreach ($rows as $index => $row) {
            if ($index <= $headerRow) {
                continue;
            }
            $cell = fn (string $key) => isset($map[$key]) ? ($row[$map[$key]] ?? null) : null;
            if (collect($row)->every(fn ($v) => trim((string) $v) === '')) {
                continue;
            }

            $amount = isset($map['credit']) ? $this->amount($cell('credit')) : $this->amount($cell('amount'));
            if ($amount === null || $amount <= 0) {
                $result['skipped']++;   // débitos, saldos o filas de totales
                continue;
            }
            $date = $this->date($cell('date'));
            if (! $date) {
                $result['errors'][] = ['row' => $index + 1, 'message' => 'Fecha no válida: «'.Str::limit((string) $cell('date'), 25).'».'];
                continue;
            }

            $text = fn ($v) => ($v = trim(preg_replace('/\s+/', ' ', (string) $v))) === '' ? null : $v;
            $result['lines'][] = [
                'line_number'      => $index + 1,
                'transaction_date' => $date,
                'description'      => ($v = $text($cell('description'))) ? mb_substr($v, 0, 255) : null,
                'reference'        => ($v = $text($cell('reference'))) ? mb_substr($v, 0, 80) : null,
                'payer_document'   => ($v = $text($cell('payer_document'))) ? mb_substr($v, 0, 25) : null,
                'amount'           => round($amount, 2),
            ];
        }

        if (! $result['lines'] && ! $result['errors']) {
            throw new RuntimeException('El extracto no tiene movimientos crédito para procesar.');
        }

        return $result;
    }

    /** Plantilla de extracto para bancos que no exportan un formato reconocible. */
    public function template(): string
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet()->setTitle('Extracto');
        $sheet->fromArray([
            ['Fecha', 'Descripción', 'Referencia', 'Documento depositante', 'Valor'],
            [now()->format('d/m/Y'), 'CONSIGNACION NACIONAL PEREZ GOMEZ', 'OBL-000001', '1070123456', 1250000],
        ]);
        $sheet->getStyle('A1:E1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle('A1:E1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('224690');
        $sheet->getStyle('E2:E5000')->getNumberFormat()->setFormatCode('"$" #,##0');
        foreach (['A' => 12, 'B' => 44, 'C' => 18, 'D' => 22, 'E' => 16] as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }

        $path = tempnam(sys_get_temp_dir(), 'extracto').'.xlsx';
        (new Xlsx($book))->save($path);

        return $path;
    }

    // ── Internos ───────────────────────────────────────────────────────────
    private function read(string $path, string $extension): array
    {
        try {
            if (in_array($extension, ['csv', 'txt'], true)) {
                // Se lee como texto: «777.777» debe llegar tal cual para interpretar el punto de miles.
                $content = (string) file_get_contents($path);
                if (! mb_check_encoding($content, 'UTF-8')) {
                    $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
                }
                $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
                $delimiter = $this->delimiter($path);

                return array_map(fn ($line) => str_getcsv($line, $delimiter),
                    preg_split('/\r\n|\n|\r/', rtrim($content, "\r\n")));
            } else {
                $reader = IOFactory::createReaderForFile($path);
                $reader->setReadDataOnly(true);
                $book = $reader->load($path);
            }
        } catch (\Throwable) {
            throw new RuntimeException('No se pudo leer el archivo. Usa Excel (.xlsx, .xls) o CSV.');
        }

        return $book->getActiveSheet()->toArray(null, true, false, false);
    }

    private function delimiter(string $path): string
    {
        $first = (string) strtok((string) file_get_contents($path, length: 4096), "\n");
        $counts = [';' => substr_count($first, ';'), ',' => substr_count($first, ','), "\t" => substr_count($first, "\t"), '|' => substr_count($first, '|')];
        arsort($counts);

        return reset($counts) > 0 ? (string) array_key_first($counts) : ';';
    }

    /** @return array{0:int, 1:array<string,int>} índice de la fila de encabezados y columna de cada campo */
    private function findHeader(array $rows): array
    {
        foreach (array_slice($rows, 0, 20, true) as $index => $row) {
            $names = array_map(fn ($v) => preg_replace('/\s+/', ' ', trim(preg_replace('/[^a-z0-9 ]+/', ' ', Str::lower(Str::ascii((string) $v))))), $row);
            $map = [];

            // 1.ª pasada: nombre exacto. 2.ª pasada: el encabezado contiene todas las palabras de un
            // sinónimo («NIT/Cédula», «Valor crédito $»), sin reutilizar columnas ya reconocidas.
            foreach ([true, false] as $exact) {
                foreach ($names as $col => $name) {
                    if ($name === '' || in_array($col, $map, true)) {
                        continue;
                    }
                    $words = explode(' ', $name);
                    foreach (self::SYNONYMS as $field => $options) {
                        if (isset($map[$field])) {
                            continue;
                        }
                        $hit = collect($options)->contains(fn ($o) => $exact ? $o === $name : ! array_diff(explode(' ', $o), $words));
                        if ($hit) {
                            $map[$field] = $col;
                            break;
                        }
                    }
                }
            }
            if (isset($map['date']) && (isset($map['amount']) || isset($map['credit']))) {
                return [$index, $map];
            }
        }

        throw new RuntimeException('No se reconocieron las columnas del extracto. Debe tener al menos «Fecha» y «Valor» (o «Crédito»). Descarga la plantilla.');
    }

    private function amount(mixed $raw): ?float
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_int($raw) || is_float($raw)) {
            return (float) $raw;
        }
        $text = preg_replace('/[^\d,.\-]/', '', (string) $raw);
        if ($text === '' || $text === '-') {
            return null;
        }
        $lastComma = strrpos($text, ',');
        $lastDot = strrpos($text, '.');
        if ($lastComma !== false && ($lastDot === false || $lastComma > $lastDot)) {
            // 1.234.567,89 → coma decimal (o 1,234 sin decimales si son 3 dígitos tras la coma)
            $decimals = strlen($text) - $lastComma - 1;
            $text = $decimals === 3 && $lastDot === false ? str_replace(',', '', $text) : str_replace(['.', ','], ['', '.'], $text);
        } elseif ($lastDot !== false) {
            $decimals = strlen($text) - $lastDot - 1;
            $text = $decimals === 3 && substr_count($text, '.') >= 1 && $lastComma === false ? str_replace('.', '', $text) : str_replace(',', '', $text);
        }

        return is_numeric($text) ? (float) $text : null;
    }

    private function date(mixed $raw): ?string
    {
        if (is_numeric($raw) && (float) $raw > 20000 && (float) $raw < 80000) {
            return CarbonImmutable::instance(ExcelDate::excelToDateTimeObject((float) $raw))->toDateString();
        }
        $text = trim((string) $raw);
        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d', 'Y/m/d', 'd/m/y', 'd.m.Y', 'Ymd', 'd/m/Y H:i', 'd/m/Y H:i:s', 'Y-m-d H:i:s'] as $format) {
            $date = \DateTimeImmutable::createFromFormat('!'.$format, $text);
            if ($date && $date->format($format) === $text) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }
}
