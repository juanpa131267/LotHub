<?php

namespace Tests\Feature;

use App\Models\Financing\AccountStatement;
use App\Models\Financing\Installment;
use App\Models\Financing\LateCharge;
use App\Services\Financing\LateChargeService;
use App\Services\Payments\PaymentService;
use App\Services\Portal\PortalAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/** Sprint 8: RN-19 liquidación de mora, CU-23 cartera y mora, CU-24 estado de cuenta en PDF. */
class PortfolioTest extends TestCase
{
    public function test_late_charges_accrue_daily_on_overdue_principal_and_are_idempotent(): void
    {
        $obligation = $this->obligation();             // cuota 0 y 1 vencidas hace 10 días; mora pactada 2 % mensual
        $service = app(LateChargeService::class);

        $result = $service->run();
        // 2 cuotas × 10.000.000 × (2 % / 30) × 10 días = 66.667 c/u
        $this->assertSame(2, $result['installments']);
        $this->assertEquals(133_334, $result['amount']);
        $this->assertSame(0, $service->run()['installments']);                 // misma fecha: no repite

        // Al día siguiente solo se liquida el tramo nuevo (1 día).
        $service->run(CarbonImmutable::tomorrow());
        $first = Installment::where('obligation_id', $obligation->id)->where('installment_number', 0)->first();
        $this->assertEquals([66_667, 6_667], LateCharge::where('installment_id', $first->id)->orderBy('calculated_on')->pluck('amount')->map(fn ($v) => (float) $v)->all());

        // La mora entra en el saldo y se paga primero (RN-14).
        $this->assertEquals(60_000_000 + 133_334 + 13_334, round($obligation->fresh()->summary()->balance));
    }

    public function test_late_rate_is_capped_by_current_usury(): void
    {
        $this->obligation(terms: ['late_interest_rate' => 2.0]);
        config(['lothub.financing.max_monthly_rate' => 1.5]);

        $this->assertEquals(2 * 50_000, app(LateChargeService::class)->run()['amount']);   // 10 M × 1,5 %/30 × 10 días
    }

    public function test_portfolio_board_filters_and_exports(): void
    {
        $director = $this->user(['DIRECTOR_COMERCIAL']);
        $late = $this->obligation();                                    // en mora
        $current = $this->obligation();
        Installment::where('obligation_id', $current->id)->update(['due_date' => now()->addMonth()->toDateString()]);

        $this->actingAs($director)->get(route('portfolio.index'))->assertOk()->assertSee($late->obligation_code)->assertSee($current->obligation_code);
        $this->actingAs($director)->get(route('portfolio.index', ['bucket' => '1_A_30']))->assertOk()
            ->assertSee($late->obligation_code)->assertDontSee($current->obligation_code);

        $response = $this->actingAs($director)->get(route('portfolio.export', ['bucket' => '1_A_30']))->assertOk();
        $sheet = IOFactory::load($response->getFile()->getPathname())->getActiveSheet();
        $this->assertSame($late->obligation_code, $sheet->getCell('A5')->getValue());
        $this->assertEquals(20_000_000, $sheet->getCell('L5')->getValue());        // valor vencido
        $this->assertNull($sheet->getCell('A6')->getValue());
    }

    public function test_account_statement_pdf_for_staff_and_client(): void
    {
        Storage::fake('local');
        Mail::fake();
        $client = $this->client();
        $obligation = $this->obligation($client);
        app(PaymentService::class)->register($obligation, $client, now()->toDateString(), 5_000_000, 2, 'C-1', $this->user()->id);

        $clerk = $this->user(['AUXILIAR_CARTERA']);
        $response = $this->actingAs($clerk)->post(route('account-statements.generate', $obligation->id))->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', file_get_contents($response->getFile()->getPathname()));

        $statement = AccountStatement::sole();
        $this->assertSame($clerk->id, $statement->generated_by);
        $this->assertSame(hash_file('sha256', Storage::disk('local')->path($statement->file_path)), $statement->file_hash);

        // Portal: el comprador genera el suyo (generated_by nulo); otro cliente no puede (RN-21).
        $portalUser = app(PortalAccessService::class)->invite($client);
        $this->actingAs($portalUser)->get(route('portal.statement', $obligation->id))->assertOk();
        $this->assertNull(AccountStatement::latest('id')->first()->generated_by);

        $stranger = app(PortalAccessService::class)->invite($this->client());
        $this->actingAs($stranger)->get(route('portal.statement', $obligation->id))->assertForbidden();
    }
}
