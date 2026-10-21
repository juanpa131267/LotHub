<?php

namespace App\Services\Financing;

use App\Models\Catalogs\ObligationStatus;
use App\Models\Catalogs\PaymentFrequency;
use App\Models\Commercial\Sale;
use App\Models\Financing\Installment;
use App\Models\Financing\Obligation;
use App\Models\Financing\PaymentAgreement;
use App\Services\AuditLogger;
use App\Services\Documents\DocumentService;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * CU-13 Definir acuerdo de pago, CU-14 Generar obligación y plan de cuotas y
 * CU-16 Reestructurar. Todo ocurre en una transacción: si un paso falla no queda
 * un acuerdo sin obligación ni una obligación sin cuotas (CU-14 4b).
 */
class AgreementService
{
    public function __construct(private InstallmentScheduler $scheduler, private DocumentService $documents) {}

    /**
     * @param array{down_payment:float, financed_amount:float, installments_count:int, frequency_id:int,
     *              monthly_interest_rate:float, late_interest_rate:float, first_due_date:string} $terms
     * @param bool $migration acuerdo traído de la operación anterior (Sprint 10): admite fechas pasadas y
     *                        la cuota inicial vence en la fecha de la negociación
     */
    public function create(Sale $sale, array $terms, bool $migration = false): Obligation
    {
        if (! $sale->isActive()) {
            throw new RuntimeException('Solo se define el acuerdo de una negociación vigente.');
        }
        if ($sale->agreements()->exists()) {
            throw new RuntimeException('La negociación ya tiene acuerdo de pago; para cambiar las condiciones usa la reestructuración.');
        }

        $terms = $this->normalize($terms, $sale, $migration);

        if (abs($terms['down_payment'] + $terms['financed_amount'] - (float) $sale->agreed_price) >= 1) {
            throw new RuntimeException('La cuota inicial más el capital financiado debe ser igual al precio pactado de $ '
                .number_format((float) $sale->agreed_price, 0, ',', '.').' (RN-09).');
        }

        return DB::transaction(function () use ($sale, $terms, $migration) {
            $agreement = PaymentAgreement::create($terms + ['sale_id' => $sale->id, 'version' => 1, 'created_by' => auth()->id()]);
            $obligation = $this->openObligation($agreement, $terms, $migration ? CarbonImmutable::parse($sale->sale_date) : null);

            AuditLogger::model('DEFINIR_ACUERDO', $agreement, [], $terms + ['obligacion' => $obligation->obligation_code]);

            return $obligation;
        });
    }

    /** CU-16: nueva versión sobre el saldo; la obligación anterior pasa a REESTRUCTURADA (RN-11). */
    public function restructure(Obligation $obligation, array $terms, string $reason): Obligation
    {
        if (! $obligation->isActive()) {
            throw new RuntimeException('Solo se reestructura una obligación activa.');
        }
        $balance = round($obligation->summary()->balance);
        if ($balance <= 0) {
            throw new RuntimeException('La obligación no tiene saldo: no procede la reestructuración (CU-16 1a).');
        }
        if (trim($reason) === '') {
            throw new RuntimeException('La reestructuración exige un motivo (RN-11).');
        }

        $sale = $obligation->agreement->sale;
        $terms = $this->normalize($terms, $sale);

        if (abs($terms['down_payment'] + $terms['financed_amount'] - $balance) >= 1) {
            throw new RuntimeException('El abono inicial más el capital financiado debe ser igual al saldo de la obligación: $ '
                .number_format($balance, 0, ',', '.').' (RN-11).');
        }

        return DB::transaction(function () use ($obligation, $sale, $terms, $reason, $balance) {
            $obligation->update(['status_id' => ObligationStatus::REESTRUCTURADA, 'closed_at' => now()->toDateString()]);

            $agreement = PaymentAgreement::create($terms + [
                'sale_id'            => $sale->id,
                'version'            => (int) $sale->agreements()->max('version') + 1,
                'restructure_reason' => $reason,
                'created_by'         => auth()->id(),
            ]);
            $new = $this->openObligation($agreement, $terms);

            AuditLogger::model('REESTRUCTURAR_ACUERDO', $agreement, ['obligacion' => $obligation->obligation_code, 'saldo' => $balance],
                $terms + ['obligacion' => $new->obligation_code, 'motivo' => $reason]);

            return $new;
        });
    }

    /** CU-13 paso 5: fecha de firma y acuerdo firmado (obligatorio cuando la modalidad lo exige, RN-10). */
    public function sign(PaymentAgreement $agreement, string $signedAt, ?UploadedFile $file): void
    {
        DB::transaction(function () use ($agreement, $signedAt, $file) {
            $agreement->update(['signed_at' => $signedAt]);
            if ($file) {
                $this->documents->store($file, 'ACUERDO_PAGO', ['sale_id' => $agreement->sale_id], "Acuerdo de pago v{$agreement->version} firmado");
            }
            AuditLogger::model('FIRMAR_ACUERDO', $agreement, [], ['firmado' => $signedAt]);
        });
    }

    /** Plan propuesto sin guardar nada (vista previa del formulario). */
    public function simulate(array $terms, Sale $sale): array
    {
        $terms = $this->normalize($terms, $sale);
        $plan = $this->plan($terms);

        return ['terms' => $terms, 'plan' => $plan, 'totals' => InstallmentScheduler::totals($plan)];
    }

    // ── Internos ───────────────────────────────────────────────────────────

    /** RN-10: el contado es una sola cuota sin intereses. RN-19: las tasas no superan la usura. */
    private function normalize(array $terms, Sale $sale, bool $allowPastDates = false): array
    {
        $terms = [
            'down_payment'          => round((float) ($terms['down_payment'] ?? 0)),
            'financed_amount'       => round((float) ($terms['financed_amount'] ?? 0)),
            'installments_count'    => (int) ($terms['installments_count'] ?? 1),
            'frequency_id'          => (int) ($terms['frequency_id'] ?? 0),
            'monthly_interest_rate' => round((float) ($terms['monthly_interest_rate'] ?? 0), 4),
            'late_interest_rate'    => round((float) ($terms['late_interest_rate'] ?? 0), 4),
            'first_due_date'        => (string) ($terms['first_due_date'] ?? ''),
        ];

        if (! $sale->modality->requires_agreement) {
            $terms['financed_amount'] += $terms['down_payment'];
            $terms['down_payment'] = 0;
            $terms['installments_count'] = 1;
            $terms['monthly_interest_rate'] = 0;
        }

        if ($terms['installments_count'] < 1 || $terms['installments_count'] > 360) {
            throw new RuntimeException('El número de cuotas debe estar entre 1 y 360 (RN-09).');
        }
        if ($terms['down_payment'] < 0 || $terms['financed_amount'] < 0) {
            throw new RuntimeException('Los valores no pueden ser negativos (RN-09).');
        }
        if ($terms['financed_amount'] <= 0) {
            throw new RuntimeException('El capital financiado debe ser mayor que cero.');
        }
        $usury = (float) config('lothub.financing.max_monthly_rate');
        foreach (['monthly_interest_rate' => 'corriente', 'late_interest_rate' => 'de mora'] as $key => $label) {
            if ($terms[$key] < 0 || $terms[$key] > $usury) {
                throw new RuntimeException("La tasa {$label} debe estar entre 0 % y el límite de usura vigente ({$usury} % mensual, RN-19).");
            }
        }
        if (! PaymentFrequency::whereKey($terms['frequency_id'])->exists()) {
            throw new RuntimeException('Selecciona la periodicidad de las cuotas.');
        }
        if ($terms['first_due_date'] === '' || (! $allowPastDates && CarbonImmutable::parse($terms['first_due_date'])->lt(now()->startOfDay()))) {
            throw new RuntimeException('El primer vencimiento debe ser hoy o una fecha posterior.');
        }

        return $terms;
    }

    private function plan(array $terms, ?CarbonImmutable $downPaymentDate = null): array
    {
        return $this->scheduler->schedule(
            $terms['down_payment'],
            $terms['financed_amount'],
            $terms['installments_count'],
            (int) PaymentFrequency::whereKey($terms['frequency_id'])->value('months_interval'),
            $terms['monthly_interest_rate'],
            CarbonImmutable::parse($terms['first_due_date']),
            $downPaymentDate ?? CarbonImmutable::today(),
        );
    }

    /** CU-14: obligación ACTIVA con su plan; verifica la suma del capital (RN-12). */
    private function openObligation(PaymentAgreement $agreement, array $terms, ?CarbonImmutable $downPaymentDate = null): Obligation
    {
        $obligation = Obligation::create([
            'obligation_code' => Obligation::nextCode(),
            'agreement_id'    => $agreement->id,
            'status_id'       => ObligationStatus::ACTIVA,
            'opened_at'       => now()->toDateString(),
        ]);

        $plan = $this->plan($terms, $downPaymentDate);
        Installment::insert(array_map(fn ($row) => $row + ['obligation_id' => $obligation->id], $plan));

        $principal = (float) Installment::where('obligation_id', $obligation->id)->sum('principal_amount');
        if (abs($principal - ($terms['down_payment'] + $terms['financed_amount'])) >= 0.01) {
            throw new RuntimeException('El plan de cuotas no cuadra con el capital del acuerdo (RN-12).');
        }

        return $obligation;
    }
}
