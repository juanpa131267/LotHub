<?php

namespace Tests\Feature;

use App\Models\Catalogs\ObligationStatus;
use App\Models\Commercial\Sale;
use App\Models\Financing\Obligation;
use App\Models\Financing\PaymentAgreement;
use App\Services\Portal\PortalAccessService;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Sprint 5: CU-13 acuerdo de pago, CU-14 plan de cuotas y CU-16 reestructuración (RN-09 a RN-12). */
class AgreementsTest extends TestCase
{
    /** Negociación vigente registrada por un asesor (modalidad 2 = financiación directa, 1 = contado). */
    protected function sale(int $modality = 2, float $price = 60_000_000, $client = null): Sale
    {
        $advisor = $this->user(['ASESOR_COMERCIAL']);
        $lot = $this->lot();
        $client ??= $this->client();

        $this->actingAs($advisor)->post(route('sales.store'), [
            'lot_id' => $lot->id, 'agreed_price' => $price, 'financing_modality_id' => $modality, 'sale_date' => now()->toDateString(),
            'buyers' => [['client_id' => $client->id, 'ownership_pct' => 100]], 'primary' => 0,
        ]);

        return Sale::where('lot_id', $lot->id)->sole();
    }

    private function terms(array $overrides = []): array
    {
        return $overrides + [
            'down_payment' => 12_000_000, 'financed_amount' => 48_000_000, 'installments_count' => 24, 'frequency_id' => 1,
            'monthly_interest_rate' => 1.0, 'late_interest_rate' => 2.0, 'first_due_date' => now()->addMonth()->toDateString(),
        ];
    }

    public function test_agreement_generates_obligation_and_installment_plan(): void
    {
        $sale = $this->sale();
        $clerk = $this->user(['AUXILIAR_CARTERA']);

        $this->actingAs($clerk)->get(route('agreements.pending'))->assertOk()->assertSee($sale->sale_code);
        $this->actingAs($clerk)->get(route('agreements.create', $sale->id))->assertOk();
        $this->actingAs($clerk)->postJson(route('agreements.simulate', $sale->id), $this->terms())
            ->assertOk()->assertJsonPath('totals.principal', 60_000_000);

        $this->actingAs($clerk)->post(route('agreements.store', $sale->id), $this->terms())->assertSessionHas('success');

        $obligation = Obligation::sole();
        $this->assertSame(ObligationStatus::ACTIVA, (int) $obligation->status_id);
        $this->assertSame(25, $obligation->installments()->count());                     // cuota 0 + 24
        $this->assertSame(0, (int) $obligation->installments()->first()->installment_number);
        $this->assertEquals(60_000_000, $obligation->installments()->sum('principal_amount')); // RN-12
        $this->assertEquals(60_000_000, round($obligation->summary()->balance - $obligation->installments()->sum('interest_amount')));

        $this->actingAs($clerk)->get(route('obligations.show', $obligation->id))->assertOk()->assertSee('Inicial');
        $this->actingAs($clerk)->getJson(route('obligations.data'))->assertOk()->assertJsonPath('data.0.obligation_code', $obligation->obligation_code);
        $this->actingAs($clerk)->get(route('sales.show', $sale->id))->assertOk()->assertSee($obligation->obligation_code);
    }

    public function test_down_payment_plus_financed_must_equal_agreed_price(): void
    {
        $sale = $this->sale();
        $clerk = $this->user(['AUXILIAR_CARTERA']);

        $this->actingAs($clerk)->post(route('agreements.store', $sale->id), $this->terms(['financed_amount' => 40_000_000]))
            ->assertSessionHas('error');
        $this->assertSame(0, PaymentAgreement::count());
    }

    public function test_rates_cannot_exceed_usury_limit(): void
    {
        config(['lothub.financing.max_monthly_rate' => 2.0]);
        $sale = $this->sale();

        $this->actingAs($this->user(['AUXILIAR_CARTERA']))->post(route('agreements.store', $sale->id), $this->terms(['late_interest_rate' => 2.5]))
            ->assertSessionHas('error');
        $this->assertSame(0, PaymentAgreement::count());
    }

    public function test_cash_sale_gets_a_single_installment_without_interest(): void
    {
        $sale = $this->sale(modality: 1, price: 45_000_000);

        $this->actingAs($this->user(['AUXILIAR_CARTERA']))->post(route('agreements.store', $sale->id), $this->terms([
            'down_payment' => 0, 'financed_amount' => 45_000_000, 'installments_count' => 12, 'monthly_interest_rate' => 1.5,
        ]))->assertSessionHas('success');

        $installment = Obligation::sole()->installments()->sole();
        $this->assertEquals(45_000_000, $installment->principal_amount);
        $this->assertEquals(0, $installment->interest_amount);
    }

    public function test_restructuring_creates_a_new_version_over_the_balance(): void
    {
        $sale = $this->sale();
        $clerk = $this->user(['AUXILIAR_CARTERA']);
        $this->actingAs($clerk)->post(route('agreements.store', $sale->id), $this->terms(['monthly_interest_rate' => 0]));
        $old = Obligation::sole();

        $this->actingAs($clerk)->get(route('obligations.restructure', $old->id))->assertOk();
        $this->actingAs($clerk)->post(route('obligations.restructure.store', $old->id), $this->terms([
            'down_payment' => 0, 'financed_amount' => 60_000_000, 'installments_count' => 48, 'monthly_interest_rate' => 0,
            'restructure_reason' => 'Disminución de ingresos del comprador',
        ]))->assertSessionHas('success');

        $this->assertSame(ObligationStatus::REESTRUCTURADA, (int) $old->fresh()->status_id);
        $new = Obligation::where('status_id', ObligationStatus::ACTIVA)->sole();
        $this->assertSame(2, (int) $new->agreement->version);
        $this->assertSame('Disminución de ingresos del comprador', $new->agreement->restructure_reason);
        $this->assertSame(48, $new->installments()->count());
        $this->assertSame(2, (int) $sale->fresh()->currentAgreement->version);
    }

    public function test_client_sees_own_plan_in_portal(): void
    {
        Mail::fake();
        $client = $this->client();
        $sale = $this->sale(client: $client);
        $this->actingAs($this->user(['AUXILIAR_CARTERA']))->post(route('agreements.store', $sale->id), $this->terms());
        $portalUser = app(PortalAccessService::class)->invite($client);

        $this->actingAs($portalUser)->get(route('portal.index'))->assertOk()
            ->assertSee(Obligation::sole()->obligation_code)->assertSee('Plan de cuotas (25)');

        // RN-21: otro cliente no ve esa obligación.
        $other = app(PortalAccessService::class)->invite($this->client());
        $this->actingAs($other)->get(route('portal.index'))->assertOk()->assertDontSee(Obligation::sole()->obligation_code);
    }
}
