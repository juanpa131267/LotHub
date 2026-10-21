<?php

namespace App\Services\Payments;

use Illuminate\Support\Str;

/**
 * Puntaje de asignación de un movimiento bancario a una obligación (0 a 100).
 *
 * Señales y pesos (configurables en lothub.allocation.weights):
 *  · Documento del depositante igual a la identificación de un comprador ......... 45
 *  · La referencia o descripción trae el código de la obligación, negociación o
 *    cliente (OBL-, VTA-, CLI-) ......................................................... 40
 *    … o la identificación de un comprador ............................................ 35
 *  · Valor igual a la cuota que sigue, a lo vencido o al saldo total (±1 peso) ......... 25
 *    … igual a otra cuota pendiente ..................................................... 20
 *    … cercano (±2 %) a la cuota que sigue ............................................ 10
 *  · Nombre y apellido del comprador en la descripción ............................. 15
 *    … solo el apellido .................................................................. 8
 *  · Fecha a ±10 días del vencimiento de la cuota que sigue ............................ 5
 *  · Penalización si el valor supera el saldo total ................................... −10
 *
 * Es una clase pura: recibe el movimiento y el candidato ya armados (ver BankStatementService).
 */
class PaymentMatcher
{
    public const DEFAULT_WEIGHTS = [
        'document'       => 45,
        'code'           => 40,
        'id_in_text'     => 35,
        'amount_exact'   => 25,
        'amount_other'   => 20,
        'amount_close'   => 10,
        'full_name'      => 15,
        'last_name'      => 8,
        'date'           => 5,
        'over_balance'   => -10,
    ];

    public function __construct(private array $weights = self::DEFAULT_WEIGHTS) {}

    /**
     * @param array{amount:float, transaction_date:string, description:?string, reference:?string, payer_document:?string} $line
     * @param array{codes:string[], buyers:array<int, array{document:string, first_name:string, last_name:string}>,
     *              next_due:?array{balance:float, due_date:string}, overdue:float, balance:float, open:float[]} $candidate
     * @return array{score:int, reasons:string[], buyer_index:?int}
     */
    public function score(array $line, array $candidate): array
    {
        $w = $this->weights + self::DEFAULT_WEIGHTS;
        $text = $this->normalize(trim(($line['reference'] ?? '').' '.($line['description'] ?? '')));
        $digitsInText = preg_match_all('/\d{6,}/', $text, $m) ? $m[0] : [];
        $payerDocument = $this->digits($line['payer_document'] ?? '');
        $amount = round((float) $line['amount'], 2);

        $score = 0;
        $reasons = [];
        $buyerIndex = null;

        // 1. Documento del depositante.
        foreach ($candidate['buyers'] as $i => $buyer) {
            if ($payerDocument !== '' && $payerDocument === $this->digits($buyer['document'])) {
                $score += $w['document'];
                $reasons[] = 'Documento del depositante coincide con un comprador';
                $buyerIndex = $i;
                break;
            }
        }

        // 2. Códigos o identificación en la referencia / descripción.
        $code = collect($candidate['codes'])->first(fn ($c) => $c !== '' && str_contains($text, $this->normalize($c)));
        if ($code) {
            $score += $w['code'];
            $reasons[] = "La referencia menciona {$code}";
        } else {
            foreach ($candidate['buyers'] as $i => $buyer) {
                $doc = $this->digits($buyer['document']);
                if (strlen($doc) >= 6 && in_array($doc, $digitsInText, true)) {
                    $score += $w['id_in_text'];
                    $reasons[] = 'La descripción trae la identificación de un comprador';
                    $buyerIndex ??= $i;
                    break;
                }
            }
        }

        // 3. Valor.
        $same = fn (float $a, float $b) => $b > 0 && abs($a - $b) <= 1;
        $next = $candidate['next_due']['balance'] ?? 0.0;
        if ($same($amount, $next) || $same($amount, $candidate['overdue']) || $same($amount, $candidate['balance'])) {
            $score += $w['amount_exact'];
            $reasons[] = match (true) {
                $same($amount, $next)                  => 'Valor igual a la cuota que sigue',
                $same($amount, $candidate['overdue'])  => 'Valor igual a lo vencido',
                default                                => 'Valor igual al saldo total',
            };
        } elseif (collect($candidate['open'])->contains(fn ($b) => $same($amount, (float) $b))) {
            $score += $w['amount_other'];
            $reasons[] = 'Valor igual a una cuota pendiente';
        } elseif ($next > 0 && abs($amount - $next) / $next <= 0.02) {
            $score += $w['amount_close'];
            $reasons[] = 'Valor cercano a la cuota que sigue';
        }
        if ($amount > $candidate['balance'] + 1) {
            $score += $w['over_balance'];
            $reasons[] = 'El valor supera el saldo de la obligación';
        }

        // 4. Nombre del comprador en la descripción.
        $words = array_flip(preg_split('/[^A-Z0-9]+/', $text, -1, PREG_SPLIT_NO_EMPTY));
        foreach ($candidate['buyers'] as $i => $buyer) {
            $first = $this->nameTokens($buyer['first_name']);
            $last = $this->nameTokens($buyer['last_name']);
            $hasLast = $last && collect($last)->every(fn ($t) => isset($words[$t]));
            $hasFirst = $first && collect($first)->contains(fn ($t) => isset($words[$t]));
            if ($hasLast) {
                $score += $hasFirst ? $w['full_name'] : $w['last_name'];
                $reasons[] = $hasFirst ? 'Nombre y apellido del comprador en la descripción' : 'Apellido del comprador en la descripción';
                $buyerIndex ??= $i;
                break;
            }
        }

        // 5. Cercanía al vencimiento.
        if (! empty($candidate['next_due']['due_date']) && ! empty($line['transaction_date'])) {
            $days = abs((strtotime($line['transaction_date']) - strtotime($candidate['next_due']['due_date'])) / 86400);
            if ($days <= 10) {
                $score += $w['date'];
                $reasons[] = 'Fecha cercana al vencimiento';
            }
        }

        return ['score' => max(0, min(100, (int) round($score))), 'reasons' => $reasons, 'buyer_index' => $buyerIndex];
    }

    /**
     * Decide entre asignar automáticamente o enviar a revisión.
     * @param array<int, array{score:int}> $ranked candidatos ordenados de mayor a menor puntaje
     */
    public function decide(array $ranked, int $threshold, int $margin): bool
    {
        $top = $ranked[0]['score'] ?? 0;
        $second = $ranked[1]['score'] ?? 0;

        return $top >= $threshold && ($top - $second) >= $margin;
    }

    private function normalize(string $text): string
    {
        return Str::upper(Str::ascii($text));
    }

    private function digits(?string $value): string
    {
        return preg_replace('/\D+/', '', (string) $value);
    }

    /** Palabras significativas de un nombre (sin tildes, de 3 o más letras, sin conectores). */
    private function nameTokens(string $name): array
    {
        $tokens = preg_split('/[^A-Z]+/', $this->normalize($name), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_filter($tokens, fn ($t) => strlen($t) >= 3 && ! in_array($t, ['DEL', 'LAS', 'LOS'], true)));
    }
}
