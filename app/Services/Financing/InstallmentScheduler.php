<?php

namespace App\Services\Financing;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * CU-14 Calcula el plan de cuotas de un acuerdo de pago (RN-12):
 *  · La cuota 0 es la cuota inicial (si la hay).
 *  · Las cuotas 1..n amortizan el capital financiado con cuota fija (sistema francés) a la tasa
 *    del periodo, equivalente a la tasa mensual pactada: (1 + i)^(meses del periodo) − 1.
 *    Con tasa 0 el capital se reparte en partes iguales.
 *  · Las fechas avanzan según el intervalo de la periodicidad desde el primer vencimiento
 *    (sin desbordar el mes: un 31 cae en el último día de los meses cortos).
 *  · Los valores se redondean a pesos; la diferencia de redondeo se ajusta en la última cuota
 *    (CU-14 4a), de modo que la suma del capital es exactamente cuota inicial + capital financiado.
 *
 * Es una clase pura (sin base de datos) para poder probarla de forma aislada.
 */
class InstallmentScheduler
{
    /**
     * @param float $monthlyRatePct tasa de interés corriente mensual en porcentaje (1.5 = 1,5 % mensual)
     * @return array<int, array{installment_number:int, due_date:string, principal_amount:float, interest_amount:float}>
     */
    public function schedule(
        float $downPayment,
        float $financedAmount,
        int $installments,
        int $monthsInterval,
        float $monthlyRatePct,
        CarbonInterface $firstDueDate,
        CarbonInterface $downPaymentDate,
    ): array {
        if ($installments < 1 || $installments > 360) {
            throw new InvalidArgumentException('El número de cuotas debe estar entre 1 y 360 (RN-09).');
        }
        if ($downPayment < 0 || $financedAmount < 0 || $monthlyRatePct < 0 || $monthsInterval < 1) {
            throw new InvalidArgumentException('Los valores del acuerdo no pueden ser negativos (RN-09).');
        }

        $plan = [];
        $downPayment = round($downPayment);
        $principal = round($financedAmount);

        if ($downPayment > 0) {
            $plan[] = $this->row(0, CarbonImmutable::instance($downPaymentDate), $downPayment, 0);
        }

        $rate = $monthlyRatePct > 0 ? (1 + $monthlyRatePct / 100) ** $monthsInterval - 1 : 0.0;
        $payment = $rate > 0
            ? round($principal * $rate / (1 - (1 + $rate) ** -$installments))
            : null;
        $equalShare = $rate > 0 ? null : floor($principal / $installments);

        $balance = $principal;
        $first = CarbonImmutable::instance($firstDueDate)->startOfDay();

        for ($k = 1; $k <= $installments; $k++) {
            $interest = $rate > 0 ? round($balance * $rate) : 0.0;
            $capital = $k === $installments
                ? $balance                                   // la última cuota absorbe el redondeo
                : ($rate > 0 ? max(0, $payment - $interest) : $equalShare);
            $capital = min($capital, $balance);

            $plan[] = $this->row($k, $first->addMonthsNoOverflow(($k - 1) * $monthsInterval), $capital, $interest);
            $balance = round($balance - $capital, 2);
        }

        return $plan;
    }

    /** Totales del plan: capital, intereses y valor total a pagar. */
    public static function totals(array $plan): array
    {
        $principal = array_sum(array_column($plan, 'principal_amount'));
        $interest = array_sum(array_column($plan, 'interest_amount'));

        return ['principal' => $principal, 'interest' => $interest, 'total' => $principal + $interest];
    }

    private function row(int $number, CarbonImmutable $due, float $principal, float $interest): array
    {
        return [
            'installment_number' => $number,
            'due_date'           => $due->toDateString(),
            'principal_amount'   => (float) $principal,
            'interest_amount'    => (float) $interest,
        ];
    }
}
