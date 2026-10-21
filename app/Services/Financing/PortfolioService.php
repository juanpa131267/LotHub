<?php

namespace App\Services\Financing;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * CU-23 Consultar cartera y mora: consolidado sobre v_obligation_balance (solo obligaciones
 * activas) con filtros por proyecto, rango de mora y cliente, y exportación a Excel (3a).
 */
class PortfolioService
{
    public const BUCKETS = [
        'AL_DIA'    => ['Al día', 0, 0],
        '1_A_30'    => ['1 a 30 días', 1, 30],
        '31_A_60'   => ['31 a 60 días', 31, 60],
        '61_A_90'   => ['61 a 90 días', 61, 90],
        'MAS_DE_90' => ['Más de 90 días', 91, null],
    ];

    /** Obligaciones activas con su saldo, mora, lote y titular, según los filtros. */
    public function query(array $filters): Builder
    {
        $query = DB::table('v_obligation_balance as ob')
            ->join('sales as s', 's.id', '=', 'ob.sale_id')
            ->join('lots as l', 'l.id', '=', 's.lot_id')
            ->join('projects as p', 'p.id', '=', 'l.project_id')
            ->join('clients as c', 'c.id', '=', 'ob.primary_client_id')
            ->join('people as pe', 'pe.id', '=', 'c.person_id')
            ->select('ob.*', 'p.id as project_id', 'p.name as project_name', 'l.block', 'l.lot_number',
                'c.client_code', 'pe.first_name', 'pe.last_name', 'pe.identification_number', 'pe.phone', 'pe.email');

        if (! empty($filters['project_id'])) {
            $query->where('p.id', $filters['project_id']);
        }
        if (! empty($filters['bucket']) && isset(self::BUCKETS[$filters['bucket']])) {
            [, $min, $max] = self::BUCKETS[$filters['bucket']];
            $query->where('ob.max_days_overdue', '>=', $min);
            if ($max !== null) {
                $query->where('ob.max_days_overdue', '<=', $max);
            }
        }
        if ($term = trim((string) ($filters['q'] ?? ''))) {
            $query->where(fn ($w) => $w->where('ob.obligation_code', 'like', "%{$term}%")->orWhere('c.client_code', 'like', "%{$term}%")
                ->orWhere('pe.identification_number', 'like', "{$term}%")
                ->orWhereRaw("CONCAT(pe.first_name, ' ', pe.last_name) LIKE ?", ["%{$term}%"]));
        }

        return $query;
    }

    /** Indicadores del tablero para los filtros dados. */
    public function summary(array $filters): array
    {
        $rows = $this->query($filters)->get(['ob.balance', 'ob.max_days_overdue', 'ob.total_paid']);
        $overdue = $rows->where('max_days_overdue', '>', 0);

        $buckets = collect(self::BUCKETS)->map(function ($bucket, $code) use ($rows) {
            [$label, $min, $max] = $bucket;
            $in = $rows->filter(fn ($r) => (int) $r->max_days_overdue >= $min && ($max === null || (int) $r->max_days_overdue <= $max));

            return ['code' => $code, 'label' => $label, 'count' => $in->count(), 'balance' => (float) $in->sum('balance')];
        })->values();

        $total = (float) $rows->sum('balance');

        return [
            'obligations'     => $rows->count(),
            'balance'         => $total,
            'collected'       => (float) $rows->sum('total_paid'),
            'overdue_count'   => $overdue->count(),
            'overdue_balance' => (float) $overdue->sum('balance'),
            'overdue_ratio'   => $total > 0 ? round(100 * $overdue->sum('balance') / $total, 1) : 0.0,
            'buckets'         => $buckets,
        ];
    }

    /** Valor vencido (capital, interés y mora) por obligación, para la exportación y el tablero. */
    public function overdueAmounts(Collection $obligationIds): Collection
    {
        return DB::table('v_installment_balance')->whereIn('obligation_id', $obligationIds)->where('days_overdue', '>', 0)
            ->groupBy('obligation_id')->selectRaw('obligation_id, SUM(balance) amount')->pluck('amount', 'obligation_id');
    }

    /** CU-23 3a: exporta el resultado filtrado. Devuelve la ruta del archivo temporal. */
    public function export(array $filters, string $title): string
    {
        $rows = $this->query($filters)->orderByDesc('ob.max_days_overdue')->orderBy('ob.obligation_code')->get();
        $overdue = $this->overdueAmounts($rows->pluck('obligation_id'));

        $book = new Spreadsheet();
        $book->getProperties()->setCreator(config('app.name'))->setTitle('Cartera');
        $sheet = $book->getActiveSheet()->setTitle('Cartera');
        $sheet->setCellValue('A1', 'Cartera y mora · '.config('lothub.company.name'));
        $sheet->setCellValue('A2', $title.' · corte '.now()->format('Y-m-d H:i').' · '.$rows->count().' obligaciones');
        $sheet->mergeCells('A1:N1');
        $sheet->mergeCells('A2:N2');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14)->getColor()->setRGB('10224A');
        $sheet->getStyle('A2')->getFont()->setSize(10)->getColor()->setRGB('667085');

        $sheet->fromArray(['Obligación', 'Negociación', 'Proyecto', 'Lote', 'Titular', 'Identificación', 'Teléfono', 'Correo',
            'Total a pagar', 'Pagado', 'Saldo', 'Valor vencido', 'Días de mora', 'Próximo vencimiento'], null, 'A4');
        $head = $sheet->getStyle('A4:N4');
        $head->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $head->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('224690');

        $data = $rows->map(fn ($r) => [
            $r->obligation_code, $r->sale_code, $r->project_name, "Mz {$r->block} Lote {$r->lot_number}",
            trim("{$r->first_name} {$r->last_name}"), $r->identification_number, $r->phone, $r->email,
            (float) $r->total_due, (float) $r->total_paid, (float) $r->balance, (float) ($overdue[$r->obligation_id] ?? 0),
            (int) $r->max_days_overdue, $r->next_due_date,
        ])->all();
        if ($data) {
            $sheet->fromArray($data, null, 'A5', true);
        }

        $last = 4 + max(1, count($data));
        $sheet->getStyle("I5:L{$last}")->getNumberFormat()->setFormatCode('"$" #,##0');
        $sheet->getStyle("F5:F{$last}")->getNumberFormat()->setFormatCode('@');
        foreach (['A' => 13, 'B' => 13, 'C' => 24, 'D' => 16, 'E' => 28, 'F' => 15, 'G' => 14, 'H' => 26, 'I' => 16, 'J' => 16, 'K' => 16, 'L' => 16, 'M' => 12, 'N' => 16] as $col => $w) {
            $sheet->getColumnDimension($col)->setWidth($w);
        }
        $sheet->setAutoFilter("A4:N{$last}");
        $sheet->freezePane('A5');

        $path = tempnam(sys_get_temp_dir(), 'cartera').'.xlsx';
        (new Xlsx($book))->save($path);

        return $path;
    }
}
