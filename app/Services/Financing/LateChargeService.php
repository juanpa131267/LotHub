<?php

namespace App\Services\Financing;

use App\Models\Catalogs\ObligationStatus;
use App\Models\Financing\LateCharge;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * RN-19 / CU-27 Liquidación de intereses de mora.
 *  · La mora se mide en días desde el vencimiento de una cuota con saldo.
 *  · Base: capital e interés corriente pendientes de la cuota (nunca intereses sobre intereses de mora).
 *  · Tasa: la de mora pactada en el acuerdo, sin superar el límite de usura vigente
 *    (lothub.financing.max_monthly_rate), convertida a diaria con base 30.
 *  · Se liquida el tramo transcurrido desde la última liquidación (o desde el vencimiento).
 *  · Idempotente: una sola liquidación por cuota y fecha (UNIQUE de late_charges).
 * La ejecuta el planificador todos los días: php artisan portfolio:late-charges
 */
class LateChargeService
{
    /** @return array{installments:int, amount:float} */
    public function run(?CarbonImmutable $on = null): array
    {
        $on = ($on ?? CarbonImmutable::today())->startOfDay();
        $usury = (float) config('lothub.financing.max_monthly_rate');
        $grace = (int) config('lothub.portfolio.grace_days');

        $applied = fn (string $concept) => "COALESCE((SELECT SUM(a.amount) FROM payment_allocations a
            JOIN payments p ON p.id = a.payment_id AND p.status_id = 1
            WHERE a.installment_id = i.id AND a.concept = '{$concept}'), 0)";

        $rows = DB::table('installments as i')
            ->join('obligations as o', 'o.id', '=', 'i.obligation_id')
            ->join('payment_agreements as pa', 'pa.id', '=', 'o.agreement_id')
            ->where('o.status_id', ObligationStatus::ACTIVA)
            ->where('pa.late_interest_rate', '>', 0)
            ->where('i.due_date', '<', $on->subDays($grace)->toDateString())
            ->selectRaw("i.id, i.due_date, pa.late_interest_rate,
                (i.principal_amount - {$applied('CAPITAL')}) + (i.interest_amount - {$applied('INTERES_CORRIENTE')}) AS base,
                (SELECT MAX(lc.calculated_on) FROM late_charges lc WHERE lc.installment_id = i.id) AS last_calculated")
            ->get();

        $charges = [];
        foreach ($rows as $row) {
            $base = round((float) $row->base, 2);
            if ($base <= 0) {
                continue;
            }
            $from = CarbonImmutable::parse($row->last_calculated ?? $row->due_date);
            $days = (int) $from->diffInDays($on, false);
            if ($days <= 0) {
                continue;
            }

            $dailyRate = min((float) $row->late_interest_rate, $usury) / 100 / 30;
            $amount = round($base * $dailyRate * $days);
            if ($amount <= 0) {
                continue;
            }

            $charges[] = [
                'installment_id' => $row->id,
                'calculated_on'  => $on->toDateString(),
                'days_overdue'   => (int) CarbonImmutable::parse($row->due_date)->diffInDays($on),
                'amount'         => $amount,
            ];
        }

        $inserted = 0;
        foreach (array_chunk($charges, 500) as $chunk) {
            $inserted += LateCharge::insertOrIgnore($chunk);
        }
        $total = (float) array_sum(array_column($charges, 'amount'));

        if ($inserted) {
            AuditLogger::log('LIQUIDAR_MORA', 'late_charges', 0, [], ['fecha' => $on->toDateString(), 'cuotas' => $inserted, 'valor' => $total], null);
        }

        return ['installments' => $inserted, 'amount' => $inserted ? $total : 0.0];
    }
}
