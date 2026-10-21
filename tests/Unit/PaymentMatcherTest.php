<?php

namespace Tests\Unit;

use App\Services\Payments\PaymentMatcher;
use PHPUnit\Framework\TestCase;

/** Sprint 6: puntaje del motor de asignación automática de pagos. */
class PaymentMatcherTest extends TestCase
{
    private PaymentMatcher $matcher;

    protected function setUp(): void
    {
        $this->matcher = new PaymentMatcher();
    }

    private function candidate(array $overrides = []): array
    {
        return $overrides + [
            'codes'    => ['OBL-000012', 'VTA-000034', 'CLI-000056'],
            'buyers'   => [['document' => '1.070.123.456', 'first_name' => 'María José', 'last_name' => 'Rodríguez Peña']],
            'next_due' => ['balance' => 1_250_000.0, 'due_date' => '2026-10-15'],
            'overdue'  => 0.0,
            'balance'  => 30_000_000.0,
            'open'     => [1_250_000.0, 1_250_000.0],
        ];
    }

    private function line(array $overrides = []): array
    {
        return $overrides + ['amount' => 1_250_000, 'transaction_date' => '2026-10-14', 'description' => '', 'reference' => '', 'payer_document' => null];
    }

    public function test_document_amount_and_date_produce_a_high_score(): void
    {
        $result = $this->matcher->score($this->line(['payer_document' => '1070123456']), $this->candidate());

        $this->assertSame(75, $result['score']);                       // 45 + 25 + 5
        $this->assertSame(0, $result['buyer_index']);
        $this->assertContains('Valor igual a la cuota que sigue', $result['reasons']);
    }

    public function test_reference_with_obligation_code_and_name_in_description(): void
    {
        $result = $this->matcher->score($this->line([
            'reference' => 'obl-000012', 'description' => 'CONSIG NAL MARIA RODRIGUEZ PENA', 'amount' => 1_000_000, 'transaction_date' => '2026-12-30',
        ]), $this->candidate());

        $this->assertSame(55, $result['score']);                       // 40 + 15 (valor y fecha no coinciden)
        $this->assertContains('La referencia menciona OBL-000012', $result['reasons']);
    }

    public function test_identification_inside_description_counts(): void
    {
        $result = $this->matcher->score($this->line(['description' => 'TRANSF DE 1070123456 PAGO LOTE']), $this->candidate());

        $this->assertSame(65, $result['score']);                       // 35 + 25 + 5
    }

    public function test_amount_over_the_balance_is_penalized_and_never_negative(): void
    {
        $result = $this->matcher->score($this->line(['amount' => 99_000_000, 'transaction_date' => '2027-06-01']), $this->candidate());

        $this->assertSame(0, $result['score']);
        $this->assertContains('El valor supera el saldo de la obligación', $result['reasons']);
    }

    public function test_decision_requires_threshold_and_margin(): void
    {
        $this->assertTrue($this->matcher->decide([['score' => 85], ['score' => 40]], 75, 20));
        $this->assertFalse($this->matcher->decide([['score' => 85], ['score' => 70]], 75, 20));   // dos candidatos parecidos
        $this->assertFalse($this->matcher->decide([['score' => 60]], 75, 20));                     // puntaje insuficiente
        $this->assertFalse($this->matcher->decide([], 75, 20));
    }
}
