<?php

namespace App\Services\Payments;

use App\Models\Catalogs\PaymentMethod;
use App\Models\Catalogs\TransactionStatus;
use App\Models\Commercial\Client;
use App\Models\Financing\Obligation;
use App\Models\Payments\GatewayNotification;
use App\Models\Payments\GatewayTransaction;
use App\Models\Payments\PaymentGateway;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * CU-20 Pagar en línea, CU-21 Procesar notificación de la pasarela y CU-17.2 Registrar abono
 * automático. RN-17: el abono solo se crea con la transacción APROBADA, una sola vez por
 * transacción, y con el mismo valor solicitado; cualquier diferencia queda para conciliación.
 */
class OnlinePaymentService
{
    /** Estados de Wompi → transaction_statuses. */
    private const STATUS_MAP = [
        'PENDING'  => TransactionStatus::PENDIENTE,
        'APPROVED' => TransactionStatus::APROBADA,
        'DECLINED' => TransactionStatus::RECHAZADA,
        'VOIDED'   => TransactionStatus::RECHAZADA,
        'ERROR'    => TransactionStatus::FALLIDA,
    ];

    public function __construct(private WompiClient $wompi, private PaymentService $payments) {}

    /** CU-20 pasos 1 a 3: crea la transacción PENDIENTE y devuelve la URL del checkout. */
    public function start(Obligation $obligation, Client $client, float $amount, string $redirectUrl): array
    {
        if (! $obligation->isActive()) {
            throw new RuntimeException('La obligación no está activa.');
        }
        if (! $this->payments->isBuyer($obligation->loadMissing('agreement'), $client)) {
            throw new RuntimeException('Solo puedes pagar obligaciones de las negociaciones en las que eres comprador (RN-21).');
        }
        $balance = round($obligation->summary()->balance);
        $min = (float) config('lothub.wompi.min_amount');
        $amount = round($amount);
        if ($amount < min($min, $balance) || $amount > $balance) {
            throw new RuntimeException('El valor debe estar entre $ '.number_format(min($min, $balance), 0, ',', '.').' y el saldo de $ '.number_format($balance, 0, ',', '.').'.');
        }

        $transaction = GatewayTransaction::create([
            'gateway_id'         => PaymentGateway::wompi()->id,
            'obligation_id'      => $obligation->id,
            'client_id'          => $client->id,
            'internal_reference' => 'LH'.$obligation->id.'-'.now()->format('ymdHis').'-'.Str::upper(Str::random(5)),
            'amount'             => $amount,
            'currency'           => 'COP',
            'status_id'          => TransactionStatus::PENDIENTE,
            'requested_at'       => now(),
        ]);

        $person = $client->person;
        $url = $this->wompi->checkoutUrl($transaction, [
            'email'         => $person->email,
            'full_name'     => $person->full_name,
            'phone'         => $person->phone,
            'legal_id'      => $person->identification_number,
            'legal_id_type' => $person->identificationType?->code,
        ], str_replace('__REF__', $transaction->internal_reference, $redirectUrl));

        AuditLogger::log('INICIAR_PAGO_EN_LINEA', 'gateway_transactions', $transaction->id, [], ['valor' => $amount, 'referencia' => $transaction->internal_reference]);

        return [$transaction, $url];
    }

    /**
     * CU-21: guarda la notificación (depurada), valida la firma y, si es válida, actualiza la
     * transacción. Una notificación repetida no repite efectos (2b) y una referencia desconocida
     * queda para conciliación (3a).
     */
    public function handleEvent(array $event): GatewayNotification
    {
        $valid = $this->wompi->verifyEvent($event);
        $data = (array) data_get($event, 'data.transaction', []);

        $notification = GatewayNotification::create([
            'event_type'         => mb_substr((string) data_get($event, 'event', 'desconocido'), 0, 60),
            'external_reference' => isset($data['id']) ? mb_substr((string) $data['id'], 0, 80) : null,
            'payload'            => $this->sanitize($event),
            'signature_valid'    => $valid,
            'received_at'        => now(),
        ]);

        if (! $valid || data_get($event, 'event') !== 'transaction.updated') {
            return $notification;
        }

        $transaction = GatewayTransaction::where('internal_reference', (string) ($data['reference'] ?? ''))->first();
        if ($transaction) {
            $notification->transaction_id = $transaction->id;
            $this->apply($transaction, $data, "notificación #{$notification->id}");
        }
        $notification->processed_at = now();
        $notification->save();

        return $notification;
    }

    /**
     * Actualiza la transacción con los datos del proveedor y, si quedó APROBADA, crea el abono
     * automático (CU-17.2). $data es data.transaction del evento o la respuesta del API.
     */
    public function apply(GatewayTransaction $transaction, array $data, string $source): void
    {
        DB::transaction(function () use ($transaction, $data, $source) {
            $transaction = GatewayTransaction::lockForUpdate()->findOrFail($transaction->id);
            $status = self::STATUS_MAP[$data['status'] ?? ''] ?? null;

            if (isset($data['id']) && $transaction->external_reference === null) {
                $transaction->external_reference = mb_substr((string) $data['id'], 0, 80);
            }
            if (isset($data['amount_in_cents'])) {
                $transaction->reported_amount = round(((int) $data['amount_in_cents']) / 100, 2);
            }
            if (isset($data['payment_method_type'])) {
                $transaction->payment_method_type = mb_substr((string) $data['payment_method_type'], 0, 30);
            }

            $hasPayment = $transaction->payment()->exists();
            if ($status !== null && ! ($hasPayment && $status !== TransactionStatus::APROBADA)) {
                $transaction->status_id = $status;
            }
            if (($data['status'] ?? null) === 'VOIDED' && $hasPayment) {
                $transaction->review_notes = 'La transacción fue anulada en Wompi: reversa el abono.';
            }
            if ($status !== null && $status !== TransactionStatus::PENDIENTE) {
                $transaction->confirmed_at ??= now();
            }
            $transaction->save();

            if ($transaction->isApproved() && ! $hasPayment) {
                $this->registerPayment($transaction, $data, $source);
            }
        });
    }

    /**
     * CU-17.2: abono automático (registered_by nulo, medio PASARELA). Si el valor no coincide o la
     * obligación ya no admite abonos, no se crea y la transacción queda para conciliación.
     */
    public function registerPayment(GatewayTransaction $transaction, array $data, string $source, bool $useReportedAmount = false): void
    {
        if ($transaction->hasAmountMismatch() && ! $useReportedAmount) {
            AuditLogger::log('ALERTA_VALOR_PASARELA', 'gateway_transactions', $transaction->id, ['solicitado' => (float) $transaction->amount],
                ['informado' => (float) $transaction->reported_amount, 'origen' => $source], null);

            return;
        }

        $date = isset($data['finalized_at']) ? CarbonImmutable::parse($data['finalized_at'])->setTimezone(config('app.timezone'))->toDateString() : now()->toDateString();

        try {
            $payment = $this->payments->register(
                $transaction->obligation()->with('agreement.sale')->firstOrFail(),
                $transaction->client,
                $date,
                (float) ($useReportedAmount ? $transaction->reported_amount : $transaction->amount),
                (int) PaymentMethod::where('code', 'PASARELA')->value('id'),
                $transaction->external_reference ?? $transaction->internal_reference,
                $useReportedAmount ? auth()->id() : null,
                $transaction->id,
            );
            AuditLogger::log('ABONO_PASARELA', 'payments', $payment->id, [], ['transaccion' => $transaction->internal_reference, 'origen' => $source], auth()->id());
        } catch (RuntimeException $e) {
            Log::warning("Pago en línea {$transaction->internal_reference} aprobado sin abono: {$e->getMessage()}");
            $transaction->update(['review_notes' => mb_substr('Aprobada sin abono: '.$e->getMessage(), 0, 255)]);
        }
    }

    /** Consulta el estado en Wompi y lo aplica (conciliación y vencimiento). */
    public function refresh(GatewayTransaction $transaction, string $source): ?array
    {
        $data = $transaction->external_reference
            ? $this->wompi->transaction($transaction->external_reference)
            : $this->wompi->transactionByReference($transaction->internal_reference);

        if ($data && ($data['reference'] ?? $transaction->internal_reference) === $transaction->internal_reference) {
            $this->apply($transaction, $data, $source);
        }

        return $data;
    }

    /** CU-20 4b: las transacciones abandonadas EXPIRAN (antes se consulta su estado real en Wompi). */
    public function expireStale(): int
    {
        $limit = now()->subMinutes((int) config('lothub.wompi.expiration_minutes') + 15);
        $expired = 0;

        GatewayTransaction::where('status_id', TransactionStatus::PENDIENTE)->where('requested_at', '<', $limit)->get()
            ->each(function (GatewayTransaction $tx) use (&$expired) {
                if ($this->wompi->isConfigured()) {
                    $this->refresh($tx, 'vencimiento');
                }
                if ((int) $tx->fresh()->status_id === TransactionStatus::PENDIENTE) {
                    $tx->update(['status_id' => TransactionStatus::EXPIRADA]);
                    $expired++;
                }
            });

        return $expired;
    }

    /** RN-18: solo se conservan los datos necesarios del evento (sin datos del pagador ni del medio de pago). */
    private function sanitize(array $event): array
    {
        $tx = (array) data_get($event, 'data.transaction', []);

        return [
            'event'       => data_get($event, 'event'),
            'environment' => data_get($event, 'environment'),
            'timestamp'   => data_get($event, 'timestamp'),
            'sent_at'     => data_get($event, 'sent_at'),
            'signature'   => data_get($event, 'signature'),
            'data'        => ['transaction' => array_intersect_key($tx, array_flip([
                'id', 'reference', 'amount_in_cents', 'currency', 'status', 'status_message', 'payment_method_type', 'created_at', 'finalized_at',
            ]))],
        ];
    }
}
