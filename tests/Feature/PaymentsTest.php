<?php

namespace Tests\Feature;

use App\Models\Catalogs\ObligationStatus;
use App\Models\Catalogs\SaleStatus;
use App\Models\Financing\Installment;
use App\Models\Payments\Payment;
use App\Models\Payments\PaymentAllocation;
use App\Services\Payments\PaymentService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/** Sprint 6 (base): CU-17 abonos, CU-18 aplicación a cuotas, CU-19 reversión y RN-13 a RN-20. */
class PaymentsTest extends TestCase
{
    private function pay($obligation, float $amount, ?string $reference = null): Payment
    {
        return app(PaymentService::class)->register(
            $obligation, $obligation->agreement->sale->primaryClient(), now()->toDateString(), $amount, 2, $reference, auth()->id() ?? $this->user()->id,
        );
    }

    public function test_payment_applies_late_interest_then_interest_then_principal_from_the_oldest_installment(): void
    {
        $obligation = $this->obligation(terms: ['monthly_interest_rate' => 1.0]);
        $first = Installment::where('obligation_id', $obligation->id)->where('installment_number', 1)->first();
        DB::table('late_charges')->insert(['installment_id' => $first->id, 'calculated_on' => now()->toDateString(), 'days_overdue' => 10, 'amount' => 50_000]);

        // Cuota inicial (10 M) + mora de la cuota 1 + parte de su interés.
        $payment = $this->pay($obligation, 10_000_000 + 50_000 + 100_000);

        $alloc = PaymentAllocation::where('payment_id', $payment->id)->get();
        $this->assertSame(['CAPITAL', 'INTERES_MORA', 'INTERES_CORRIENTE'], $alloc->pluck('concept')->all());
        $this->assertEquals([10_000_000, 50_000, 100_000], $alloc->pluck('amount')->map(fn ($v) => (float) $v)->all());
        $this->assertSame([0, 1, 1], $alloc->map(fn ($a) => (int) $a->installment->installment_number)->all());
    }

    public function test_full_payment_closes_obligation_and_sale_with_credit_left_over(): void
    {
        $obligation = $this->obligation();

        $payment = $this->pay($obligation, 60_500_000);

        $this->assertSame(ObligationStatus::CANCELADA, (int) $obligation->fresh()->status_id);
        $this->assertSame(SaleStatus::PAGADA, (int) $obligation->agreement->sale->fresh()->status_id);
        $this->assertEquals(500_000, $payment->unallocated());                  // saldo a favor (RN-14)
    }

    public function test_reversal_restores_the_balance_and_reopens_the_obligation(): void
    {
        $obligation = $this->obligation();
        $payment = $this->pay($obligation, 60_000_000);
        $this->assertSame(ObligationStatus::CANCELADA, (int) $obligation->fresh()->status_id);

        $clerk = $this->user(['AUXILIAR_CARTERA']);
        $this->actingAs($clerk)->post(route('payments.reverse', $payment->id), ['reversal_reason' => 'Consignación rechazada por el banco'])
            ->assertSessionHas('success');

        $payment->refresh();
        $this->assertSame(2, (int) $payment->status_id);
        $this->assertSame($clerk->id, $payment->reversed_by);
        $this->assertSame(ObligationStatus::ACTIVA, (int) $obligation->fresh()->status_id);
        $this->assertSame(SaleStatus::VIGENTE, (int) $obligation->agreement->sale->fresh()->status_id);
        $this->assertEquals(60_000_000, $obligation->fresh()->summary()->balance);

        // RN-15: el motor impide borrar o alterar el valor de un abono.
        $this->expectException(\Illuminate\Database\QueryException::class);
        $payment->delete();
    }

    public function test_payer_must_be_a_buyer(): void
    {
        $obligation = $this->obligation();

        $this->expectException(RuntimeException::class);
        app(PaymentService::class)->register($obligation, $this->client(), now()->toDateString(), 1_000_000, 2, null, $this->user()->id);
    }

    public function test_manual_payment_with_support_and_duplicate_reference_warning(): void
    {
        Storage::fake('local');
        $obligation = $this->obligation();
        $clerk = $this->user(['AUXILIAR_CARTERA']);
        $payer = $obligation->agreement->sale->primaryClient();
        $form = fn (array $extra = []) => $extra + [
            'payer_client_id' => $payer->id, 'payment_date' => now()->toDateString(), 'amount' => 2_000_000, 'payment_method_id' => 2,
            'bank_reference' => 'CNS-778899', 'support_file' => UploadedFile::fake()->image('soporte.jpg'),
        ];

        $this->actingAs($clerk)->get(route('payments.create-for', $obligation->id))->assertOk();
        $this->actingAs($clerk)->post(route('payments.store-for', $obligation->id), $form())->assertSessionHas('success');
        $payment = Payment::where('obligation_id', $obligation->id)->sole();
        $this->assertSame(1, $payment->documents()->count());
        $this->actingAs($clerk)->get(route('payments.show', $payment->id))->assertOk()->assertSee('CNS-778899');

        // CU-17.1 2a: la misma referencia se advierte y exige confirmación.
        $this->actingAs($clerk)->post(route('payments.store-for', $obligation->id), $form())->assertSessionHas('duplicate', $payment->id);
        $this->assertSame(1, Payment::where('obligation_id', $obligation->id)->count());
        $this->actingAs($clerk)->post(route('payments.store-for', $obligation->id), $form(['confirm_duplicate' => 1]))->assertSessionHas('success');
        $this->assertSame(2, Payment::where('obligation_id', $obligation->id)->count());

        $this->actingAs($clerk)->getJson(route('payments.data'))->assertOk()->assertJsonPath('data.0.origin', 'Manual');
        $this->actingAs($clerk)->get(route('obligations.show', $obligation->id))->assertOk()->assertSee('Registrar abono');
    }
}
