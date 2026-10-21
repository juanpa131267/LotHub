<?php

namespace App\Services\Commercial;

use App\Models\Catalogs\LotStatus;
use App\Models\Catalogs\SaleStatus;
use App\Models\Commercial\Lot;
use App\Models\Commercial\Reservation;
use App\Models\Commercial\Sale;
use App\Models\Commercial\SaleCancellationRequest;
use App\Services\AuditLogger;
use App\Services\Documents\DocumentService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * CU-12 Registrar negociación y CU-15 Anular / desistir.
 * El motor garantiza RN-05, RN-06 y RN-08 (triggers y columnas generadas); aquí se valida
 * RN-07 (compradores, titular principal único y participaciones que suman 100 %) y RN-09.
 */
class SaleService
{
    public function __construct(private DocumentService $documents) {}

    /**
     * @param array{lot_id:int, agreed_price:float, financing_modality_id:int, sale_date:string,
     *              buyers: array<int, array{client_id:int, ownership_pct:float, is_primary:bool}>} $data
     * @param array<string, UploadedFile|null> $files tipo de documento => archivo (PROMESA, DOC_IDENTIDAD)
     */
    public function create(array $data, array $files = []): Sale
    {
        $buyers = $this->validateBuyers($data['buyers']);

        if ((float) $data['agreed_price'] <= 0) {
            throw new RuntimeException('El precio pactado debe ser mayor que cero (RN-09).');
        }

        return DB::transaction(function () use ($data, $buyers, $files) {
            $lot = Lot::lockForUpdate()->findOrFail($data['lot_id']);
            $reservation = $this->reservationFor($lot, array_column($buyers, 'client_id'));

            $sale = Sale::create([
                'sale_code'             => Sale::nextCode(),
                'lot_id'                => $lot->id,
                'reservation_id'        => $reservation?->id,
                'advisor_id'            => auth()->id(),
                'sale_date'             => $data['sale_date'],
                'agreed_price'          => $data['agreed_price'],
                'financing_modality_id' => $data['financing_modality_id'],
                'status_id'             => SaleStatus::VIGENTE,
            ]);

            foreach ($buyers as $buyer) {
                $sale->clients()->attach($buyer['client_id'], [
                    'is_primary'    => $buyer['is_primary'],
                    'ownership_pct' => $buyer['ownership_pct'],
                ]);
            }

            foreach (array_filter($files) as $type => $file) {
                $this->documents->store($file, $type, ['sale_id' => $sale->id]);
            }

            AuditLogger::model('REGISTRAR_NEGOCIACION', $sale, [], [
                'lote' => $lot->id, 'reserva' => $reservation?->id, 'precio' => (float) $data['agreed_price'],
                'compradores' => $buyers,
            ]);

            return $sale;
        });
    }

    /** CU-15 paso 1: el Asesor solicita la anulación o el desistimiento con el motivo. */
    public function requestCancellation(Sale $sale, string $type, string $reason): SaleCancellationRequest
    {
        if (! $sale->isActive()) {
            throw new RuntimeException('Solo se puede solicitar la anulación de una negociación vigente.');
        }
        if ($sale->pendingCancellation()->exists()) {
            throw new RuntimeException('La negociación ya tiene una solicitud pendiente de aprobación.');
        }

        $request = SaleCancellationRequest::create([
            'sale_id'      => $sale->id,
            'request_type' => $type,
            'reason'       => $reason,
            'status'       => SaleCancellationRequest::PENDING,
            'requested_by' => auth()->id(),
            'requested_at' => now(),
        ]);
        AuditLogger::log('SOLICITAR_ANULACION', 'sales', $sale->id, [], ['tipo' => $type, 'motivo' => $reason]);

        return $request;
    }

    /**
     * CU-15 pasos 2 y 3: el Director aprueba; la negociación queda ANULADA o DESISTIDA, la
     * obligación (si existe) pasa a ANULADA y el trigger trg_sale_after_update libera el lote.
     * 3a: si la obligación tiene abonos aplicados, se exige registrar su tratamiento.
     */
    public function approveCancellation(SaleCancellationRequest $request, ?string $notes): Sale
    {
        $sale = $request->sale;
        $this->ensurePending($request);

        if ($this->appliedPayments($sale) > 0 && blank($notes)) {
            throw new RuntimeException('La obligación tiene abonos aplicados: registra su tratamiento (devolución o penalidad) antes de aprobar.');
        }

        DB::transaction(function () use ($request, $sale, $notes) {
            $sale->update([
                'status_id'           => $request->request_type === 'DESISTIMIENTO' ? SaleStatus::DESISTIDA : SaleStatus::ANULADA,
                'cancellation_reason' => $request->reason,
                'approved_by'         => auth()->id(),
            ]);

            // Las obligaciones activas de la negociación quedan ANULADAS (estado 4).
            DB::table('obligations as o')->join('payment_agreements as pa', 'pa.id', '=', 'o.agreement_id')
                ->where('pa.sale_id', $sale->id)->where('o.status_id', 1)
                ->update(['o.status_id' => 4, 'o.closed_at' => now()->toDateString()]);

            $request->update(['status' => SaleCancellationRequest::APPROVED, 'resolved_by' => auth()->id(), 'resolved_at' => now(), 'resolution_notes' => $notes]);
            AuditLogger::log('APROBAR_ANULACION', 'sales', $sale->id, ['estado' => 'VIGENTE'], [
                'estado' => $request->request_type, 'motivo' => $request->reason, 'tratamiento' => $notes,
            ]);
        });

        return $sale->refresh();
    }

    /** CU-15 2a: el Director rechaza y la negociación sigue VIGENTE. */
    public function rejectCancellation(SaleCancellationRequest $request, string $notes): void
    {
        $this->ensurePending($request);

        $request->update(['status' => SaleCancellationRequest::REJECTED, 'resolved_by' => auth()->id(), 'resolved_at' => now(), 'resolution_notes' => $notes]);
        AuditLogger::log('RECHAZAR_ANULACION', 'sales', $request->sale_id, [], ['motivo_rechazo' => $notes]);
    }

    /** Valor de los abonos aplicados a las obligaciones de la negociación. */
    public function appliedPayments(Sale $sale): float
    {
        return (float) DB::table('payments as p')
            ->join('obligations as o', 'o.id', '=', 'p.obligation_id')
            ->join('payment_agreements as pa', 'pa.id', '=', 'o.agreement_id')
            ->where('pa.sale_id', $sale->id)->where('p.status_id', 1)
            ->sum('p.amount');
    }

    // ── Internos ───────────────────────────────────────────────────────────

    /** RN-07: al menos un comprador, sin repetir, un único titular principal y 100 % entre todos. */
    private function validateBuyers(array $buyers): array
    {
        $buyers = array_values(array_map(fn ($b) => [
            'client_id'     => (int) $b['client_id'],
            'ownership_pct' => round((float) $b['ownership_pct'], 2),
            'is_primary'    => (bool) ($b['is_primary'] ?? false),
        ], $buyers));

        if (! $buyers) {
            throw new RuntimeException('La negociación necesita al menos un comprador (RN-07).');
        }
        if (count(array_unique(array_column($buyers, 'client_id'))) !== count($buyers)) {
            throw new RuntimeException('Un mismo cliente aparece más de una vez entre los compradores.');
        }
        if (count(array_filter(array_column($buyers, 'is_primary'))) !== 1) {
            throw new RuntimeException('Debe haber exactamente un titular principal (RN-07).');
        }
        foreach ($buyers as $b) {
            if ($b['ownership_pct'] <= 0 || $b['ownership_pct'] > 100) {
                throw new RuntimeException('Cada participación debe estar entre 0 % y 100 %.');
            }
        }
        if (abs(array_sum(array_column($buyers, 'ownership_pct')) - 100) > 0.001) {
            throw new RuntimeException('Las participaciones de los compradores deben sumar 100 % (RN-07).');
        }

        return $buyers;
    }

    /**
     * RN-05: un lote RESERVADO solo se negocia mediante su reserva activa, y esa reserva
     * debe ser de uno de los compradores.
     */
    private function reservationFor(Lot $lot, array $clientIds): ?Reservation
    {
        return match ((int) $lot->status_id) {
            LotStatus::DISPONIBLE => null,
            LotStatus::RESERVADO  => tap(Reservation::active()->where('lot_id', $lot->id)->first(), function (?Reservation $r) use ($clientIds) {
                if (! $r || ! in_array($r->client_id, $clientIds, true)) {
                    throw new RuntimeException('El lote está reservado para otro cliente. Anula la reserva o incluye a ese cliente como comprador.');
                }
            }),
            default => throw new RuntimeException("El lote {$lot->label} no se puede negociar: está {$lot->status?->name}."),
        };
    }

    private function ensurePending(SaleCancellationRequest $request): void
    {
        if ($request->status !== SaleCancellationRequest::PENDING) {
            throw new RuntimeException('La solicitud ya fue resuelta.');
        }
        if (! $request->sale->isActive()) {
            throw new RuntimeException('La negociación ya no está vigente.');
        }
    }
}
