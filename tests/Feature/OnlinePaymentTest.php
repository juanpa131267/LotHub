<?php

namespace Tests\Feature;

use App\Models\Catalogs\TransactionStatus;
use App\Models\Payments\GatewayNotification;
use App\Models\Payments\GatewayTransaction;
use App\Models\Payments\Payment;
use App\Services\Payments\OnlinePaymentService;
use App\Services\Portal\PortalAccessService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Sprint 7: CU-20 pago en línea con Wompi, CU-21 notificaciones, CU-17.2 abono automático y CU-22 conciliación. */
class OnlinePaymentTest extends TestCase
{
    private function portalUser($client)
    {
        Mail::fake();

        return app(PortalAccessService::class)->invite($client);
    }

    /** Evento transaction.updated firmado como lo envía Wompi. */
    private function event(GatewayTransaction $tx, string $status, ?int $cents = null, string $secret = 'test_events_secret', ?string $reference = null): array
    {
        $transaction = [
            'id' => 'w-'.$tx->id.'-'.$status, 'reference' => $reference ?? $tx->internal_reference, 'amount_in_cents' => $cents ?? (int) round($tx->amount * 100),
            'currency' => 'COP', 'status' => $status, 'payment_method_type' => 'CARD', 'customer_email' => 'privado@example.test',
            'payment_method' => ['type' => 'CARD', 'extra' => ['last_four' => '4242']], 'finalized_at' => now()->toIso8601String(),
        ];
        $timestamp = now()->timestamp;
        $checksum = hash('sha256', $transaction['id'].$transaction['status'].$transaction['amount_in_cents'].$timestamp.$secret);

        return [
            'event' => 'transaction.updated', 'data' => ['transaction' => $transaction], 'environment' => 'test',
            'signature' => ['properties' => ['transaction.id', 'transaction.status', 'transaction.amount_in_cents'], 'checksum' => strtoupper($checksum)],
            'timestamp' => $timestamp, 'sent_at' => now()->toIso8601String(),
        ];
    }

    /** Cliente con portal y obligación; inicia un pago por $valor y devuelve la transacción. */
    private function startPayment(float $amount = 10_000_000): array
    {
        $client = $this->client();
        $obligation = $this->obligation($client);
        $user = $this->portalUser($client);

        $response = $this->actingAs($user)->post(route('portal.pay', $obligation->id), ['amount' => $amount]);

        return [$response, GatewayTransaction::where('obligation_id', $obligation->id)->latest('id')->first(), $obligation, $user];
    }

    public function test_portal_payment_redirects_to_wompi_with_integrity_signature(): void
    {
        [$response, $tx] = $this->startPayment();

        $this->assertSame(TransactionStatus::PENDIENTE, (int) $tx->status_id);
        $location = $response->headers->get('Location');
        $this->assertStringStartsWith('https://checkout.wompi.co/p/?', $location);
        parse_str(parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('1000000000', $query['amount-in-cents']);
        $this->assertSame($tx->internal_reference, $query['reference']);
        $this->assertSame(hash('sha256', $tx->internal_reference.'1000000000COP'.$query['expiration-time'].'test_integrity_secret'), $query['signature:integrity']);
        $this->assertStringContainsString('/mi-cuenta/pagos/'.$tx->internal_reference, $query['redirect-url']);
    }

    public function test_approved_event_creates_a_single_automatic_payment(): void
    {
        [, $tx, $obligation, $user] = $this->startPayment();
        $event = $this->event($tx, 'APPROVED');

        $this->postJson(route('gateway.wompi.events'), $event)->assertOk();
        $this->postJson(route('gateway.wompi.events'), $event)->assertOk();               // repetida (CU-21 2b)

        $payment = Payment::where('gateway_transaction_id', $tx->id)->sole();
        $this->assertNull($payment->registered_by);                                     // RN-16: origen pasarela
        $this->assertSame('PASARELA', $payment->method->code);
        $this->assertEquals(10_000_000, $payment->amount);
        $this->assertSame(TransactionStatus::APROBADA, (int) $tx->fresh()->status_id);
        $this->assertSame(2, GatewayNotification::where('transaction_id', $tx->id)->count());

        // RN-18: el payload guardado no conserva datos del pagador ni del medio de pago.
        $payload = GatewayNotification::where('transaction_id', $tx->id)->first()->payload;
        $this->assertArrayNotHasKey('customer_email', $payload['data']['transaction']);
        $this->assertArrayNotHasKey('payment_method', $payload['data']['transaction']);

        $this->actingAs($user)->get(route('portal.payments.result', $tx->internal_reference))->assertOk()->assertSee('Pago aplicado');
    }

    public function test_invalid_signature_is_stored_but_not_processed(): void
    {
        [, $tx] = $this->startPayment();

        $this->postJson(route('gateway.wompi.events'), $this->event($tx, 'APPROVED', secret: 'otro_secreto'))->assertStatus(401);

        $this->assertFalse(GatewayNotification::latest('id')->first()->signature_valid);
        $this->assertSame(TransactionStatus::PENDIENTE, (int) $tx->fresh()->status_id);
        $this->assertSame(0, Payment::count());
    }

    public function test_amount_mismatch_goes_to_reconciliation_and_can_be_applied(): void
    {
        [, $tx] = $this->startPayment();
        $this->postJson(route('gateway.wompi.events'), $this->event($tx, 'APPROVED', cents: 900_000_000))->assertOk();

        $this->assertSame(0, Payment::count());
        $this->assertTrue($tx->fresh()->hasAmountMismatch());

        $clerk = $this->user(['AUXILIAR_CARTERA']);
        $this->actingAs($clerk)->get(route('reconciliation.index'))->assertOk()->assertSee($tx->internal_reference)->assertSee('Diferencia de valor');
        $this->actingAs($clerk)->post(route('reconciliation.apply-reported', $tx->id))->assertSessionHas('success');

        $payment = Payment::where('gateway_transaction_id', $tx->id)->sole();
        $this->assertEquals(9_000_000, $payment->amount);
        $this->assertSame($clerk->id, $payment->registered_by);
    }

    public function test_declined_payment_and_unknown_reference(): void
    {
        [, $tx, , $user] = $this->startPayment();

        $this->postJson(route('gateway.wompi.events'), $this->event($tx, 'DECLINED'))->assertOk();
        $this->assertSame(TransactionStatus::RECHAZADA, (int) $tx->fresh()->status_id);
        $this->actingAs($user)->get(route('portal.payments.result', $tx->internal_reference))->assertOk()->assertSee('Pago rechazado');

        // Referencia desconocida (CU-21 3a): se guarda para conciliación.
        $this->postJson(route('gateway.wompi.events'), $this->event($tx, 'APPROVED', reference: 'LH-NO-EXISTE'))->assertOk();
        $notification = GatewayNotification::latest('id')->first();
        $this->assertNull($notification->transaction_id);

        $clerk = $this->user(['AUXILIAR_CARTERA']);
        $this->actingAs($clerk)->get(route('reconciliation.index'))->assertSee('Referencia desconocida');
        $this->actingAs($clerk)->post(route('reconciliation.review', ['notificacion', $notification->id]), ['notes' => 'Prueba de Wompi en sandbox'])
            ->assertSessionHas('success');
        $this->actingAs($clerk)->get(route('reconciliation.index'))->assertSee('Conciliación completa');
    }

    public function test_client_cannot_pay_someone_elses_obligation(): void
    {
        $obligation = $this->obligation();
        $stranger = $this->portalUser($this->client());

        $this->actingAs($stranger)->post(route('portal.pay', $obligation->id), ['amount' => 1_000_000])->assertSessionHas('error');
        $this->assertSame(0, GatewayTransaction::count());
    }

    public function test_stale_pending_transactions_expire(): void
    {
        Http::fake();
        [, $tx] = $this->startPayment();
        $tx->update(['requested_at' => now()->subHours(3)]);

        $this->assertSame(1, app(OnlinePaymentService::class)->expireStale());
        $this->assertSame(TransactionStatus::EXPIRADA, (int) $tx->fresh()->status_id);
    }
}
