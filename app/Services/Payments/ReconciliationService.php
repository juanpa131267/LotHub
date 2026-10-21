<?php

namespace App\Services\Payments;

use App\Models\Catalogs\PaymentMethod;
use App\Models\Catalogs\PaymentStatus;
use App\Models\Catalogs\TransactionStatus;
use App\Models\Payments\GatewayNotification;
use App\Models\Payments\GatewayTransaction;
use App\Models\Payments\Payment;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * CU-22 Conciliar transacciones de la pasarela en un periodo:
 *  · Transacciones APROBADAS sin abono (incluye diferencias de valor, CU-17.2 1a).
 *  · Abonos de pasarela cuya transacción no está aprobada o fue anulada en el proveedor.
 *  · Transacciones PENDIENTES que superaron el tiempo de pago.
 *  · Notificaciones con firma inválida o con referencia desconocida (CU-21 2a / 3a).
 * Cada diferencia se resuelve reprocesando, aplicando el valor informado, reversando o
 * marcándola como revisada; todas las acciones quedan en auditoría.
 */
class ReconciliationService
{
    public function __construct(private OnlinePaymentService $online) {}

    /** @return array<string, Collection> */
    public function report(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $period = [$from->startOfDay(), $to->endOfDay()];
        $tx = fn () => GatewayTransaction::with(['obligation', 'client.person', 'payment'])->whereBetween('requested_at', $period)->whereNull('reviewed_at');

        return [
            'approved_without_payment' => $tx()->where('status_id', TransactionStatus::APROBADA)->whereDoesntHave('payment')->oldest('requested_at')->get(),
            'payments_without_approval' => Payment::with(['obligation', 'payer.person'])
                ->where('status_id', PaymentStatus::APLICADO)->whereBetween('payment_date', [$period[0]->toDateString(), $period[1]->toDateString()])
                ->where('payment_method_id', PaymentMethod::where('code', 'PASARELA')->value('id'))
                ->where(fn ($q) => $q->whereNull('gateway_transaction_id')
                    ->orWhereHas('gatewayTransaction', fn ($t) => $t->where('status_id', '!=', TransactionStatus::APROBADA)->orWhereNotNull('review_notes')->whereNull('reviewed_at')))
                ->oldest('payment_date')->get(),
            'stale_pending' => $tx()->where('status_id', TransactionStatus::PENDIENTE)
                ->where('requested_at', '<', now()->subMinutes((int) config('lothub.wompi.expiration_minutes')))->oldest('requested_at')->get(),
            'notifications' => GatewayNotification::whereBetween('received_at', $period)->whereNull('reviewed_at')
                ->where(fn ($q) => $q->where('signature_valid', false)->orWhere(fn ($w) => $w->whereNull('transaction_id')->where('event_type', 'transaction.updated')))
                ->latest('received_at')->get(),
        ];
    }

    /** Totales del periodo para el encabezado de la conciliación. */
    public function totals(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = GatewayTransaction::whereBetween('requested_at', [$from->startOfDay(), $to->endOfDay()])
            ->selectRaw('status_id, COUNT(*) total, SUM(amount) amount')->groupBy('status_id')->get()->keyBy('status_id');

        return [
            'approved'       => (int) ($rows[TransactionStatus::APROBADA]->total ?? 0),
            'approved_amount'=> (float) ($rows[TransactionStatus::APROBADA]->amount ?? 0),
            'rejected'       => (int) (($rows[TransactionStatus::RECHAZADA]->total ?? 0) + ($rows[TransactionStatus::FALLIDA]->total ?? 0)),
            'pending'        => (int) ($rows[TransactionStatus::PENDIENTE]->total ?? 0),
            'expired'        => (int) ($rows[TransactionStatus::EXPIRADA]->total ?? 0),
        ];
    }

    /** Reprocesa con la última notificación válida o, si no la hay, consultando a Wompi. */
    public function reprocess(GatewayTransaction $transaction): void
    {
        $notification = $transaction->notifications()->where('signature_valid', true)->first();
        $data = $notification?->payload['data']['transaction'] ?? null;

        if ($data) {
            $this->online->apply($transaction, $data, "reproceso de la notificación #{$notification->id}");
        } elseif (! $this->online->refresh($transaction, 'conciliación')) {
            throw new RuntimeException('No hay una notificación válida y Wompi no respondió la consulta. Intenta más tarde.');
        }

        AuditLogger::log('CONCILIAR_REPROCESAR', 'gateway_transactions', $transaction->id, [], ['estado' => $transaction->fresh()->status->name]);
    }

    /** Diferencia de valor: el Auxiliar decide aplicar el abono con el valor que informó la pasarela. */
    public function applyReportedAmount(GatewayTransaction $transaction): void
    {
        if (! $transaction->isApproved() || $transaction->payment()->exists() || ! $transaction->hasAmountMismatch()) {
            throw new RuntimeException('La transacción no tiene una diferencia de valor pendiente.');
        }

        $this->online->registerPayment($transaction, [], 'conciliación (valor informado)', useReportedAmount: true);
        if (! $transaction->payment()->exists()) {
            throw new RuntimeException($transaction->fresh()->review_notes ?? 'No se pudo registrar el abono.');
        }
        AuditLogger::log('CONCILIAR_VALOR_INFORMADO', 'gateway_transactions', $transaction->id, ['solicitado' => (float) $transaction->amount], ['aplicado' => (float) $transaction->reported_amount]);
    }

    public function markReviewed(GatewayTransaction|GatewayNotification $item, string $notes): void
    {
        $item->update(['reviewed_by' => auth()->id(), 'reviewed_at' => now(), 'review_notes' => mb_substr($notes, 0, 255)]);
        AuditLogger::log('CONCILIAR_REVISADA', $item->getTable(), $item->id, [], ['notas' => $notes]);
    }
}
