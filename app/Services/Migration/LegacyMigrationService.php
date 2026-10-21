<?php

namespace App\Services\Migration;

use App\Models\Catalogs\FinancingModality;
use App\Models\Catalogs\IdentificationType;
use App\Models\Catalogs\LotStatus;
use App\Models\Catalogs\PaymentFrequency;
use App\Models\Catalogs\PaymentMethod;
use App\Models\Commercial\Client;
use App\Models\Commercial\DataConsent;
use App\Models\Commercial\Lot;
use App\Models\Commercial\Project;
use App\Models\Financing\LateCharge;
use App\Models\Security\Person;
use App\Services\AuditLogger;
use App\Services\Commercial\SaleService;
use App\Services\Financing\AgreementService;
use App\Services\Payments\PaymentService;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Throwable;

/**
 * Sprint 10 · Migración de la cartera vigente desde las hojas de cálculo, para operar en paralelo.
 * Cada fila crea (o reutiliza) el cliente, la negociación sobre un lote DISPONIBLE del inventario,
 * el acuerdo con su plan de cuotas y, si ya había pagos, un abono «Pagado antes del sistema» que
 * se aplica a las cuotas más antiguas. La carga es atómica: con un error no se migra nada.
 * La mora anterior no se reconstruye: por defecto se liquida desde la fecha de la migración.
 */
class LegacyMigrationService
{
    private const HEADER_ROW = 4;

    public const COLUMNS = [
        'A' => ['reference', 'Referencia en la hoja *', 16], 'B' => ['id_type', 'Tipo id *', 9], 'C' => ['id_number', 'Número id *', 15],
        'D' => ['first_name', 'Nombres *', 18], 'E' => ['last_name', 'Apellidos *', 18], 'F' => ['email', 'Correo', 24], 'G' => ['phone', 'Teléfono', 14],
        'H' => ['consent', 'Autoriza datos (SI/NO) *', 12], 'I' => ['project', 'Código proyecto *', 14], 'J' => ['block', 'Manzana *', 9], 'K' => ['lot', 'Lote *', 8],
        'L' => ['sale_date', 'Fecha negociación *', 14], 'M' => ['price', 'Precio pactado *', 16], 'N' => ['modality', 'Modalidad *', 22],
        'O' => ['down_payment', 'Cuota inicial', 14], 'P' => ['financed', 'Capital financiado *', 16], 'Q' => ['count', 'N.º cuotas *', 10],
        'R' => ['frequency', 'Periodicidad *', 12], 'S' => ['rate', 'Tasa corriente % mes', 12], 'T' => ['late_rate', 'Tasa mora % mes', 12],
        'U' => ['first_due', 'Primer vencimiento *', 14], 'V' => ['paid', 'Total pagado a la fecha', 16], 'W' => ['last_payment', 'Fecha último pago', 14],
        'X' => ['legacy_balance', 'Saldo según la hoja', 16],
    ];

    public function __construct(
        private SaleService $sales,
        private AgreementService $agreements,
        private PaymentService $payments,
    ) {}

    public function template(): string
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet()->setTitle('Cartera');
        $sheet->setCellValue('A1', 'Migración de cartera vigente · '.config('lothub.company.name'));
        $sheet->setCellValue('A2', 'Una fila por negociación vigente. El lote debe existir en el inventario y estar DISPONIBLE. Modalidad: '
            .implode(', ', FinancingModality::pluck('code')->all()).'. Periodicidad: '.implode(', ', PaymentFrequency::pluck('code')->all())
            .'. Tipos de id: '.implode(', ', IdentificationType::pluck('code')->all()).'. Fechas en formato AAAA-MM-DD o fecha de Excel.');
        $sheet->mergeCells('A1:X1');
        $sheet->mergeCells('A2:X2');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14)->getColor()->setRGB('10224A');
        $sheet->getStyle('A2')->getFont()->setSize(9)->getColor()->setRGB('667085');
        $sheet->getStyle('A2')->getAlignment()->setWrapText(true);
        $sheet->getRowDimension(2)->setRowHeight(42);

        foreach (self::COLUMNS as $col => [, $title, $width]) {
            $sheet->setCellValue($col.self::HEADER_ROW, $title);
            $sheet->getColumnDimension($col)->setWidth($width);
        }
        $head = $sheet->getStyle('A'.self::HEADER_ROW.':X'.self::HEADER_ROW);
        $head->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $head->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('224690');
        $head->getAlignment()->setWrapText(true);
        $sheet->getRowDimension(self::HEADER_ROW)->setRowHeight(30);
        $sheet->getStyle('C5:C1000')->getNumberFormat()->setFormatCode('@');
        $sheet->getStyle('K5:K1000')->getNumberFormat()->setFormatCode('@');
        $sheet->freezePane('A5');

        $control = new Worksheet($book, 'control');
        $book->addSheet($control);
        $control->setCellValue('A1', 'lothub-migracion');
        $control->setSheetState(Worksheet::SHEETSTATE_VERYHIDDEN);
        $book->setActiveSheetIndex(0);

        $path = tempnam(sys_get_temp_dir(), 'migracion').'.xlsx';
        (new Xlsx($book))->save($path);

        return $path;
    }

    /** @return array{created:int, errors:array<int, array{row:int, reference:string, message:string}>, import_id:?int} */
    public function import(UploadedFile $file, bool $lateFromMigration = true): array
    {
        $hash = hash_file('sha256', $file->getRealPath());
        if (DB::table('legacy_imports')->where('file_hash', $hash)->exists()) {
            throw new RuntimeException('Este archivo ya se migró.');
        }

        $rows = $this->read($file);
        $errors = [];
        $valid = [];
        $seen = [];
        foreach ($rows as $line => $row) {
            $rowErrors = $this->validate($row, $seen);
            foreach ($rowErrors as $message) {
                $errors[] = ['row' => $line, 'reference' => (string) $row['reference'], 'message' => $message];
            }
            if (! $rowErrors) {
                $valid[$line] = $row;
            }
            $seen[mb_strtoupper((string) $row['reference'])] = $line;
        }
        if (! $rows) {
            throw new RuntimeException('La plantilla no tiene filas para migrar.');
        }
        if ($errors) {
            return ['created' => 0, 'errors' => $errors, 'import_id' => null];
        }

        $line = null;
        try {
            $importId = DB::transaction(function () use ($file, $hash, $valid, $lateFromMigration, &$line) {
                $importId = DB::table('legacy_imports')->insertGetId([
                    'original_name' => mb_substr($file->getClientOriginalName(), 0, 255), 'file_hash' => $hash, 'rows_count' => count($valid),
                    'late_charges_from_migration' => $lateFromMigration, 'imported_by' => auth()->id(), 'imported_at' => now(),
                ]);
                foreach ($valid as $line => $row) {
                    $this->migrateRow($importId, $line, $row, $lateFromMigration);
                }

                return $importId;
            });
        } catch (Throwable $e) {
            throw new RuntimeException("Fila {$line}: ".$e->getMessage().' No se migró ninguna fila.');
        }

        AuditLogger::log('MIGRAR_CARTERA', 'legacy_imports', $importId, [], ['archivo' => $file->getClientOriginalName(), 'negociaciones' => count($valid)]);

        return ['created' => count($valid), 'errors' => [], 'import_id' => $importId];
    }

    private function migrateRow(int $importId, int $line, array $row, bool $lateFromMigration): void
    {
        $idType = IdentificationType::where('code', $row['id_type'])->value('id');
        $person = Person::firstOrNew(['identification_type_id' => $idType, 'identification_number' => $row['id_number']]);
        // Una persona existente conserva los datos que la hoja no trae.
        $person->fill(array_filter([
            'first_name' => $row['first_name'], 'last_name' => $row['last_name'],
            'email' => $row['email'] ?: null, 'phone' => $row['phone'] ?: null,
        ], fn ($v) => $v !== null))->save();

        $client = Client::withTrashed()->firstOrCreate(['person_id' => $person->id], ['client_code' => Client::nextCode(), 'registered_by' => auth()->id()]);
        if ($row['consent']) {
            DataConsent::firstOrCreate(['person_id' => $person->id, 'policy_version' => config('lothub.privacy_policy_version')],
                ['channel' => 'PRESENCIAL', 'granted_at' => now()]);
        }

        $lot = Lot::whereHas('project', fn ($q) => $q->where('code', $row['project']))
            ->where('block', $row['block'])->where('lot_number', $row['lot'])->firstOrFail();

        $sale = $this->sales->create([
            'lot_id' => $lot->id, 'agreed_price' => $row['price'], 'sale_date' => $row['sale_date'],
            'financing_modality_id' => FinancingModality::where('code', $row['modality'])->value('id'),
            'buyers' => [['client_id' => $client->id, 'ownership_pct' => 100, 'is_primary' => true]],
        ]);

        $obligation = $this->agreements->create($sale->load('modality'), [
            'down_payment' => $row['down_payment'], 'financed_amount' => $row['financed'], 'installments_count' => $row['count'],
            'frequency_id' => PaymentFrequency::where('code', $row['frequency'])->value('id'),
            'monthly_interest_rate' => $row['rate'], 'late_interest_rate' => $row['late_rate'], 'first_due_date' => $row['first_due'],
        ], migration: true);

        if ($row['paid'] > 0) {
            $this->payments->register($obligation, $client, $row['last_payment'] ?? now()->toDateString(), $row['paid'],
                (int) PaymentMethod::where('code', 'SALDO_MIGRADO')->value('id'), 'MIG-'.$row['reference'], auth()->id());
        }

        // La mora previa no se reconstruye: se deja una base de liquidación en la fecha de migración (valor 0).
        if ($lateFromMigration) {
            $today = CarbonImmutable::today();
            $overdue = $obligation->balances()->filter(fn ($r) => (float) $r->balance > 0 && $r->due_date < $today->toDateString());
            LateCharge::insertOrIgnore($overdue->map(fn ($r) => [
                'installment_id' => $r->installment_id, 'calculated_on' => $today->toDateString(),
                'days_overdue' => (int) CarbonImmutable::parse($r->due_date)->diffInDays($today), 'amount' => 0,
            ])->values()->all());
        }

        DB::table('legacy_import_rows')->insert([
            'import_id' => $importId, 'sheet_row' => $line, 'legacy_reference' => $row['reference'], 'client_id' => $client->id,
            'sale_id' => $sale->id, 'obligation_id' => $obligation->id, 'paid_before' => $row['paid'], 'legacy_balance' => $row['legacy_balance'],
        ]);
    }

    /** @return array<int, array<string, mixed>> filas normalizadas por número de fila */
    private function read(UploadedFile $file): array
    {
        $reader = new XlsxReader();
        $reader->setReadDataOnly(true);
        try {
            $book = $reader->load($file->getRealPath());
        } catch (Throwable) {
            throw new RuntimeException('El archivo no es una planilla de Excel válida (.xlsx).');
        }
        if ($book->getSheetByName('control')?->getCell('A1')->getValue() !== 'lothub-migracion') {
            throw new RuntimeException('Usa la plantilla de migración de LotHub (Operación en paralelo → Plantilla).');
        }

        $sheet = $book->getSheetByName('Cartera') ?? throw new RuntimeException('La plantilla no tiene la hoja «Cartera».');
        $rows = [];
        $blank = 0;
        for ($r = self::HEADER_ROW + 1; $r <= $sheet->getHighestDataRow(); $r++) {
            $raw = [];
            foreach (self::COLUMNS as $col => [$key]) {
                $raw[$key] = $sheet->getCell($col.$r)->getValue();
            }
            if (collect($raw)->every(fn ($v) => trim((string) $v) === '')) {
                // Las celdas con formato pero sin datos cuentan como filas: se corta tras 50 vacías seguidas.
                if (++$blank > 50) {
                    break;
                }
                continue;
            }
            $blank = 0;
            $rows[$r] = $this->normalizeRow($raw);
        }

        return $rows;
    }

    private function normalizeRow(array $raw): array
    {
        $text = fn ($v) => trim((string) $v);
        // 45.000.000 · 45,000,000.00 · $ 45.000.000 · 160,5 · números de Excel
        $num = function ($v): ?float {
            if ($v === null || $v === '') {
                return null;
            }
            if (is_int($v) || is_float($v)) {
                return (float) $v;
            }
            $t = preg_replace('/[^\d,.\-]/', '', (string) $v);
            $t = match (true) {
                (bool) preg_match('/^-?\d{1,3}(\.\d{3})+(,\d+)?$/', $t) => str_replace(['.', ','], ['', '.'], $t),
                (bool) preg_match('/^-?\d{1,3}(,\d{3})+(\.\d+)?$/', $t) => str_replace(',', '', $t),
                default                                                     => str_replace(',', '.', $t),
            };

            return is_numeric($t) ? (float) $t : null;
        };
        $date = function ($v) {
            if ($v === null || $v === '') {
                return null;
            }
            if (is_numeric($v)) {
                return CarbonImmutable::instance(ExcelDate::excelToDateTimeObject((float) $v))->toDateString();
            }
            foreach (['Y-m-d', 'd/m/Y', 'd-m-Y'] as $f) {
                if (($d = DateTimeImmutable::createFromFormat('!'.$f, trim((string) $v))) !== false) {
                    return $d->format('Y-m-d');
                }
            }

            return false;
        };

        return [
            'reference'      => mb_substr($text($raw['reference']), 0, 40),
            'id_type'        => mb_strtoupper($text($raw['id_type'])),
            'id_number'      => preg_replace('/[^\dA-Za-z]/', '', $text($raw['id_number'])),
            'first_name'     => $text($raw['first_name']),
            'last_name'      => $text($raw['last_name']),
            'email'          => mb_strtolower($text($raw['email'])),
            'phone'          => $text($raw['phone']),
            'consent'        => in_array(mb_strtoupper($text($raw['consent'])), ['SI', 'SÍ', 'S', 'X', '1'], true),
            'consent_raw'    => $text($raw['consent']),
            'project'        => mb_strtoupper($text($raw['project'])),
            'block'          => mb_strtoupper($text($raw['block'])),
            'lot'            => $text($raw['lot']),
            'sale_date'      => $date($raw['sale_date']),
            'price'          => $num($raw['price']),
            'modality'       => mb_strtoupper($text($raw['modality'])),
            'down_payment'   => $num($raw['down_payment']) ?? 0.0,
            'financed'       => $num($raw['financed']),
            'count'          => (int) $num($raw['count']),
            'frequency'      => mb_strtoupper($text($raw['frequency'])) ?: 'MENSUAL',
            'rate'           => $num($raw['rate']) ?? 0.0,
            'late_rate'      => $num($raw['late_rate']) ?? 0.0,
            'first_due'      => $date($raw['first_due']),
            'paid'           => $num($raw['paid']) ?? 0.0,
            'last_payment'   => $date($raw['last_payment']),
            'legacy_balance' => $num($raw['legacy_balance']),
        ];
    }

    private function validate(array $row, array $seen): array
    {
        $errors = [];
        $required = ['reference' => 'la referencia', 'id_type' => 'el tipo de identificación', 'id_number' => 'el número de identificación',
            'first_name' => 'los nombres', 'last_name' => 'los apellidos', 'project' => 'el código del proyecto', 'block' => 'la manzana', 'lot' => 'el lote'];
        foreach ($required as $key => $label) {
            if (($row[$key] ?? '') === '') {
                $errors[] = "Falta {$label}.";
            }
        }
        if ($row['reference'] !== '' && (isset($seen[mb_strtoupper($row['reference'])]) || DB::table('legacy_import_rows')->where('legacy_reference', $row['reference'])->exists())) {
            $errors[] = 'La referencia ya fue migrada o está repetida en el archivo.';
        }
        if (! in_array(mb_strtoupper($row['consent_raw']), ['SI', 'SÍ', 'S', 'X', '1', 'NO', 'N', '0', ''], true) || $row['consent_raw'] === '') {
            $errors[] = 'Indica SI o NO en «Autoriza datos».';
        }
        if ($row['id_type'] !== '' && ! IdentificationType::where('code', $row['id_type'])->exists()) {
            $errors[] = "Tipo de identificación desconocido: {$row['id_type']}.";
        }
        if ($row['email'] !== '' && ! filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Correo no válido.';
        }
        if ($row['email'] !== '' && Person::where('email', $row['email'])
            ->where(fn ($q) => $q->where('identification_number', '!=', $row['id_number']))->exists()) {
            $errors[] = 'El correo ya pertenece a otra persona.';
        }
        if ($row['project'] !== '') {
            $project = Project::where('code', $row['project'])->first();
            if (! $project) {
                $errors[] = "No existe el proyecto {$row['project']}.";
            } elseif ($row['block'] !== '' && $row['lot'] !== '') {
                $lot = Lot::where('project_id', $project->id)->where('block', $row['block'])->where('lot_number', $row['lot'])->first();
                if (! $lot) {
                    $errors[] = "El lote {$row['block']}-{$row['lot']} no existe en el inventario del proyecto.";
                } elseif ((int) $lot->status_id !== LotStatus::DISPONIBLE) {
                    $errors[] = "El lote {$row['block']}-{$row['lot']} no está DISPONIBLE (estado {$lot->status->name}).";
                }
            }
        }
        foreach (['sale_date' => 'fecha de negociación', 'first_due' => 'primer vencimiento'] as $key => $label) {
            if (! $row[$key]) {
                $errors[] = "Falta o no es válida la {$label}.";
            }
        }
        if ($row['last_payment'] === false) {
            $errors[] = 'Fecha del último pago no válida.';
        }
        if ($row['sale_date'] && $row['sale_date'] > now()->toDateString()) {
            $errors[] = 'La fecha de negociación no puede ser futura.';
        }
        if (! $row['price'] || $row['price'] <= 0) {
            $errors[] = 'Precio pactado no válido.';
        }
        $modality = FinancingModality::where('code', $row['modality'])->first();
        if (! $modality) {
            $errors[] = "Modalidad desconocida: «{$row['modality']}».";
        }
        if (! PaymentFrequency::where('code', $row['frequency'])->exists()) {
            $errors[] = "Periodicidad desconocida: «{$row['frequency']}».";
        }
        if ($row['financed'] === null || $row['financed'] <= 0) {
            $errors[] = 'Capital financiado no válido.';
        } elseif ($row['price'] && abs($row['down_payment'] + $row['financed'] - $row['price']) >= 1) {
            $errors[] = 'Cuota inicial + capital financiado debe ser igual al precio pactado (RN-09).';
        }
        if ($modality?->requires_agreement && ($row['count'] < 1 || $row['count'] > 360)) {
            $errors[] = 'El número de cuotas debe estar entre 1 y 360.';
        }
        $usury = (float) config('lothub.financing.max_monthly_rate');
        if ($row['rate'] < 0 || $row['rate'] > $usury || $row['late_rate'] < 0 || $row['late_rate'] > $usury) {
            $errors[] = "Las tasas deben estar entre 0 % y la usura vigente ({$usury} % mensual).";
        }
        if ($row['paid'] < 0 || ($row['price'] && $row['paid'] > $row['price'] * 1.5)) {
            $errors[] = 'Total pagado no válido.';
        }

        return $errors;
    }
}
