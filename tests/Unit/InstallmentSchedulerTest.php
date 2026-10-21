<?php

namespace Tests\Unit;

use App\Services\Financing\InstallmentScheduler;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** RN-12 / CU-14: plan de cuotas. */
class InstallmentSchedulerTest extends TestCase
{
    private InstallmentScheduler $scheduler;

    protected function setUp(): void
    {
        $this->scheduler = new InstallmentScheduler();
    }

    public function test_zero_rate_splits_principal_and_adjusts_rounding_in_the_last_installment(): void
    {
        $plan = $this->scheduler->schedule(10_000_000, 40_000_001, 3, 1, 0, CarbonImmutable::parse('2026-11-15'), CarbonImmutable::parse('2026-10-20'));

        $this->assertCount(4, $plan);
        $this->assertSame(0, $plan[0]['installment_number']);
        $this->assertSame('2026-10-20', $plan[0]['due_date']);
        $this->assertSame(10_000_000.0, $plan[0]['principal_amount']);
        $this->assertSame([13_333_333.0, 13_333_333.0, 13_333_335.0], array_column(array_slice($plan, 1), 'principal_amount'));
        $this->assertSame(50_000_001.0, InstallmentScheduler::totals($plan)['principal']);
        $this->assertSame(0.0, InstallmentScheduler::totals($plan)['interest']);
    }

    public function test_french_amortization_keeps_principal_equal_to_the_financed_amount(): void
    {
        $plan = $this->scheduler->schedule(0, 36_000_000, 36, 1, 1.2, CarbonImmutable::parse('2026-11-30'), CarbonImmutable::parse('2026-10-20'));

        $this->assertCount(36, $plan);
        $this->assertSame(1, $plan[0]['installment_number']);
        $this->assertSame(36_000_000.0, InstallmentScheduler::totals($plan)['principal']);
        $this->assertSame(432_000.0, $plan[0]['interest_amount']);                    // 1,2 % de 36.000.000
        $installments = array_map(fn ($r) => $r['principal_amount'] + $r['interest_amount'], array_slice($plan, 0, 35));
        $this->assertLessThanOrEqual(1.0, max($installments) - min($installments));   // cuota fija (±1 peso)
        $this->assertSame('2027-02-28', $plan[3]['due_date']);                        // sin desbordar el mes
    }

    public function test_quarterly_frequency_uses_the_equivalent_period_rate(): void
    {
        $plan = $this->scheduler->schedule(0, 10_000_000, 4, 3, 1.0, CarbonImmutable::parse('2027-01-10'), CarbonImmutable::parse('2026-10-20'));

        $this->assertSame(['2027-01-10', '2027-04-10', '2027-07-10', '2027-10-10'], array_column($plan, 'due_date'));
        $this->assertSame(303_010.0, $plan[0]['interest_amount']);                    // (1,01^3 − 1) × 10.000.000
        $this->assertSame(10_000_000.0, InstallmentScheduler::totals($plan)['principal']);
    }

    public function test_cash_sale_is_a_single_installment_without_interest(): void
    {
        $plan = $this->scheduler->schedule(0, 48_000_000, 1, 1, 0, CarbonImmutable::parse('2026-10-30'), CarbonImmutable::parse('2026-10-20'));

        $this->assertSame([[
            'installment_number' => 1, 'due_date' => '2026-10-30', 'principal_amount' => 48_000_000.0, 'interest_amount' => 0.0,
        ]], $plan);
    }

    public function test_rejects_out_of_range_values(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->scheduler->schedule(0, 1_000_000, 361, 1, 0, CarbonImmutable::now(), CarbonImmutable::now());
    }
}
