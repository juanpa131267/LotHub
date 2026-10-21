<?php

namespace App\Services\Payments;

use App\Events\PaymentRegistered;
use App\Models\Catalogs\ObligationStatus;
use App\Models\Catalogs\PaymentStatus;
use App\Models\Catalogs\SaleStatus;
use App\Models\Commercial\Client;
use App\Models\Financing\Obligation;
use App\Models\Payments\BankStatementLine;
use App\Models\Payments\Payment;
use App\Models\Payments\PaymentAllocation;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * CU-17 Registrar abono (comportamiento común a manual, extracto y pasarela), CU-18 Aplicar
 * abono a cuotas y CU-19 Reversar. Las reglas que garantiza el motor no se duplican:
 * RN-13 (trg_alloc_before_insert), RN-15 (triggers de payments) y RN-16 (CHECK de payments).
 */
class PaymentService
{
    /**
     * @param int|null $registeredBy usuario que registra; null solo para abonos de pasarela (RN-16)
     */
    public function register(
        Obligation $obligation,
        Client $payer,
        string $date,
        float $amount,
        int $methodId,
        ?string $reference,
        ?int $registeredBy,
        ?int $gatewayTransactionId = null,
    ): Payment {
        if ($amount <= 0) {
            throw new RuntimeException('El valor del abono debe ser mayor que cero (RN-16).');
        }

        return DB::transaction(function () use ($obligation, $payer, $date, $amount, $methodId, $reference, $registeredBy, $gatewayTransactionId) {
            // Bloquea la obligación: dos abonos simultáneos no pueden aplicarse a la misma cuota.
            $obligation = Obligation::with('agreement.sale')->lockForUpdate()->findOrFail($obligation->id);

            if (! $obligation->isActive()) {
                throw new RuntimeException("La obligación {$obligation->obligation_code} no está activa (CU-17 2a).");
            }
            if (! $this->isBuyer($obligation, $payer)) {
                throw new RuntimeException('El pagador no figura como comprador de la negociación (CU-17 2a).');
            }

            $payment = Payment::create([
                'obligation_id'          => $obligation->id,
                'payer_client_id'        => $payer->id,
                'payment_date'           => $date,
                'amount'                 => round($amount, 2),
                'payment_method_id'      => $methodId,
                'bank_reference'         => $reference ? mb_substr(trim($reference), 0, 80) : null,
                'gateway_transaction_id' => $gatewayTransactionId,
                'status_id'              => PaymentStatus::APLICADO,
                'registered_by'          => $registeredBy,
            ]);

            $applied = $this->allocate($payment);
            $closed = $this->closeIfPaid($obligation);

            AuditLogger::log('REGISTRAR_ABONO', 'payments', $payment->id, [], [
                'obligacion' => $obligation->obligation_code, 'valor' => (float) $payment->amount, 'aplicado' => $applied,
                'saldo_a_favor' => round((float) $payment->amount - $applied, 2), 'obligacion_cancelada' => $closed,
            ], $registeredBy);

            DB::afterCommit(fn () => PaymentRegistered::dispatch($payment));

            return $payment;
        });
    }

    /**
     * CU-18 / RN-14: distribuye el abono desde la cuota más antigua con saldo y, en cada cuota,
     * en el orden interés de mora → interés corriente → capital. El excedente queda sin aplicar
     * (saldo a favor). Devuelve el valor aplicado.
     */
    public function allocate(Payment $payment): float
    {
        $remaining = round((float) $payment->amount - (float) $payment->allocations()->sum('amount'), 2);
        $applied = 0.0;

        foreach ($this->outstanding($payment->obligation_id) as $installment) {
            foreach ([PaymentAllocation::LATE => 'late', PaymentAllocation::INTEREST => 'interest', PaymentAllocation::PRINCIPAL => 'principal'] as $concept => $key) {
                $value = round(min($remaining, $installment->{$key}), 2);
                if ($value <= 0) {
                    continue;
                }
                PaymentAllocation::create(['payment_id' => $payment->id, 'installment_id' => $installment->id, 'concept' => $concept, 'amount' => $value]);
                $remaining = round($remaining - $value, 2);
                $applied = round($applied + $value, 2);
            }
            if ($remaining <= 0) {
                break;
            }
        }

        return $applied;
    }

    /** RN-20: saldo cero → obligación CANCELADA y negociación PAGADA. */
    public function closeIfPaid(Obligation $obligation): bool
    {
        if (! $obligation->isActive() || $obligation->summary()->balance > 0.004) {
            return false;
        }

        $obligation->update(['status_id' => ObligationStatus::CANCELADA, 'closed_at' => now()->toDateString()]);
        $obligation->agreement->sale->update(['status_id' => SaleStatus::PAGADA]);
        AuditLogger::log('CANCELAR_OBLIGACION', 'obligations', $obligation->id, [], ['negociacion' => $obligation->agreement->sale_id]);

        return true;
    }

    /**
     * CU-19 Reversar: el abono queda REVERSADO con motivo, usuario y fecha; las vistas dejan de
     * sumar sus aplicaciones. Si la obligación estaba CANCELADA vuelve a ACTIVA (y la negociación
     * a VIGENTE). Un abono de pasarela exige confirmar la anulación previa en el proveedor (1b).
     */
    public function reverse(Payment $payment, string $reason, bool $gatewayVoidConfirmed = false): void
    {
        if (! $payment->isApplied()) {
            throw new RuntimeException('El abono ya está reversado (CU-19 1a).');
        }
        if ($payment->gateway_transaction_id && ! $gatewayVoidConfirmed) {
            throw new RuntimeException('Es un abono de la pasarela: confirma que la transacción ya fue anulada en el proveedor (CU-19 1b).');
        }

        DB::transaction(function () use ($payment, $reason) {
            $payment->update([
                'status_id'       => PaymentStatus::REVERSADO,
                'reversal_reason' => mb_substr($reason, 0, 255),
                'reversed_by'     => auth()->id(),
                'reversed_at'     => now(),
            ]);

            $obligation = $payment->obligation()->with('agreement.sale')->first();
            if ((int) $obligation->status_id === ObligationStatus::CANCELADA) {
                $obligation->update(['status_id' => ObligationStatus::ACTIVA, 'closed_at' => null]);
                if ((int) $obligation->agreement->sale->status_id === SaleStatus::PAGADA) {
                    $obligation->agreement->sale->update(['status_id' => SaleStatus::VIGENTE]);
                }
            }

            // Si el abono vino de un extracto, el movimiento vuelve a la cola para asignarlo bien.
            BankStatementLine::where('payment_id', $payment->id)->update([
                'status' => BankStatementLine::REVIEW, 'review_notes' => "Abono #{$payment->id} reversado: ".mb_substr($reason, 0, 200),
            ]);

            AuditLogger::log('REVERSAR_ABONO', 'payments', $payment->id, ['estado' => 'APLICADO'], ['estado' => 'REVERSADO', 'motivo' => $reason]);
        });
    }

    /** CU-17.1 2a: ¿la referencia bancaria ya está en otro abono aplicado? */
    public function referenceInUse(?string $reference, ?int $exceptPaymentId = null): ?Payment
    {
        if (blank($reference)) {
            return null;
        }

        return Payment::where('bank_reference', trim($reference))->where('status_id', PaymentStatus::APLICADO)
            ->when($exceptPaymentId, fn ($q) => $q->whereKeyNot($exceptPaymentId))->first();
    }

    public function isBuyer(Obligation $obligation, Client $client): bool
    {
        return DB::table('sale_clients')->where('sale_id', $obligation->agreement->sale_id)->where('client_id', $client->id)->exists();
    }

    /** Cuotas con saldo por concepto (mora, interés, capital), de la más antigua a la más reciente. */
    private function outstanding(int $obligationId)
    {
        $allocated = fn (string $concept) => "COALESCE((SELECT SUM(a.amount) FROM payment_allocations a
            JOIN payments p ON p.id = a.payment_id AND p.status_id = 1
            WHERE a.installment_id = i.id AND a.concept = '{$concept}'), 0)";

        return DB::table('installments as i')
            ->where('i.obligation_id', $obligationId)
            ->selectRaw('i.id, i.due_date, i.installment_number,
                COALESCE((SELECT SUM(lc.amount) FROM late_charges lc WHERE lc.installment_id = i.id), 0) - '.$allocated(PaymentAllocation::LATE).' AS late,
                i.interest_amount - '.$allocated(PaymentAllocation::INTEREST).' AS interest,
                i.principal_amount - '.$allocated(PaymentAllocation::PRINCIPAL).' AS principal')
            ->orderBy('i.due_date')->orderBy('i.installment_number')
            ->get()
            ->map(fn ($r) => (object) ['id' => $r->id, 'late' => max(0, (float) $r->late), 'interest' => max(0, (float) $r->interest), 'principal' => max(0, (float) $r->principal)])
            ->filter(fn ($r) => $r->late + $r->interest + $r->principal > 0);
    }
}
