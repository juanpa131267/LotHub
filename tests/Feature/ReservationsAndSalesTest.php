<?php

namespace Tests\Feature;

use App\Models\Catalogs\LotStatus;
use App\Models\Catalogs\ReservationStatus;
use App\Models\Catalogs\SaleStatus;
use App\Models\Commercial\Reservation;
use App\Models\Commercial\Sale;
use App\Models\Commercial\SaleCancellationRequest;
use App\Models\Documents\Document;
use App\Services\Commercial\ReservationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Sprint 4: CU-11 reservas, CU-12 negociaciones y CU-15 anulación (RN-02 a RN-08). */
class ReservationsAndSalesTest extends TestCase
{
    private function reserve($advisor, $lot, $client, array $extra = [])
    {
        return $this->actingAs($advisor)->post(route('reservations.store'), $extra + [
            'lot_id' => $lot->id, 'client_id' => $client->id, 'deposit_amount' => 1_000_000,
            'expires_at' => now()->addDays(5)->format('Y-m-d H:i'),
        ]);
    }

    public function test_reserving_a_lot_marks_it_reserved_and_blocks_a_second_reservation(): void
    {
        $advisor = $this->user(['ASESOR_COMERCIAL']);
        $lot = $this->lot();

        $this->reserve($advisor, $lot, $this->client())->assertSessionHas('success');
        $this->assertSame(LotStatus::RESERVADO, (int) $lot->fresh()->status_id);

        // RN-02 / RN-04: el lote ya no está disponible.
        $this->reserve($advisor, $lot, $this->client())->assertSessionHas('error');
        $this->assertSame(1, Reservation::where('lot_id', $lot->id)->count());
    }

    public function test_cancelling_a_reservation_releases_the_lot(): void
    {
        $advisor = $this->user(['ASESOR_COMERCIAL']);
        $lot = $this->lot();
        $this->reserve($advisor, $lot, $this->client());
        $reservation = Reservation::where('lot_id', $lot->id)->sole();

        $this->actingAs($advisor)->get(route('reservations.show', $reservation->id))->assertOk()->assertSee('Anular reserva');
        $this->actingAs($advisor)->post(route('reservations.cancel', $reservation->id), ['reason' => 'El cliente desistió'])->assertSessionHas('success');

        $this->assertSame(ReservationStatus::ANULADA, (int) $reservation->fresh()->status_id);
        $this->assertSame(LotStatus::DISPONIBLE, (int) $lot->fresh()->status_id);
    }

    public function test_overdue_reservations_expire_and_release_their_lot(): void
    {
        $advisor = $this->user(['ASESOR_COMERCIAL']);
        $lot = $this->lot();
        $this->reserve($advisor, $lot, $this->client());
        Reservation::where('lot_id', $lot->id)->update(['reserved_at' => now()->subDays(3), 'expires_at' => now()->subMinute()]);

        $this->assertSame(1, app(ReservationService::class)->expireOverdue());
        $this->assertSame(ReservationStatus::VENCIDA, (int) Reservation::where('lot_id', $lot->id)->value('status_id'));
        $this->assertSame(LotStatus::DISPONIBLE, (int) $lot->fresh()->status_id);
    }

    public function test_sale_from_reservation_converts_it_and_sells_the_lot(): void
    {
        Storage::fake('local');
        $advisor = $this->user(['ASESOR_COMERCIAL']);
        $lot = $this->lot();
        $buyer = $this->client();
        $partner = $this->client();
        $this->reserve($advisor, $lot, $buyer);
        $reservation = Reservation::where('lot_id', $lot->id)->sole();

        $this->actingAs($advisor)->get(route('sales.create', ['reservation_id' => $reservation->id]))->assertOk()->assertSee('reserva #'.$reservation->id);

        $response = $this->actingAs($advisor)->post(route('sales.store'), [
            'lot_id' => $lot->id, 'agreed_price' => 48_000_000, 'financing_modality_id' => 2, 'sale_date' => now()->toDateString(),
            'buyers' => [0 => ['client_id' => $buyer->id, 'ownership_pct' => 60], 3 => ['client_id' => $partner->id, 'ownership_pct' => 40]],
            'primary' => 0,
            'promise_file' => UploadedFile::fake()->create('promesa.pdf', 120, 'application/pdf'),
        ]);

        $sale = Sale::where('lot_id', $lot->id)->sole();
        $response->assertRedirect(route('sales.show', $sale->id));
        $this->assertSame(LotStatus::VENDIDO, (int) $lot->fresh()->status_id);
        $this->assertSame(ReservationStatus::CONVERTIDA, (int) $reservation->fresh()->status_id);
        $this->assertSame($buyer->id, $sale->primaryClient()->id);
        $this->assertSame(64, strlen(Document::where('sale_id', $sale->id)->sole()->file_hash));

        $this->actingAs($advisor)->get(route('sales.show', $sale->id))->assertOk()->assertSee('promesa.pdf');
        $this->actingAs($advisor)->getJson(route('sales.data'))->assertOk()->assertJsonPath('data.0.sale_code', $sale->sale_code);
    }

    public function test_buyers_must_add_up_to_one_hundred_percent_with_one_primary(): void
    {
        $advisor = $this->user(['ASESOR_COMERCIAL']);
        $lot = $this->lot();
        $base = ['lot_id' => $lot->id, 'agreed_price' => 48_000_000, 'financing_modality_id' => 2, 'sale_date' => now()->toDateString()];

        $this->actingAs($advisor)->post(route('sales.store'), $base + [
            'buyers' => [['client_id' => $this->client()->id, 'ownership_pct' => 50], ['client_id' => $this->client()->id, 'ownership_pct' => 30]],
            'primary' => 0,
        ])->assertSessionHas('error', 'Las participaciones de los compradores deben sumar 100 % (RN-07).');

        $this->assertSame(0, Sale::where('lot_id', $lot->id)->count());
        $this->assertSame(LotStatus::DISPONIBLE, (int) $lot->fresh()->status_id);
    }

    public function test_reserved_lot_cannot_be_sold_to_a_different_client(): void
    {
        $advisor = $this->user(['ASESOR_COMERCIAL']);
        $lot = $this->lot();
        $this->reserve($advisor, $lot, $this->client());

        $this->actingAs($advisor)->post(route('sales.store'), [
            'lot_id' => $lot->id, 'agreed_price' => 48_000_000, 'financing_modality_id' => 2, 'sale_date' => now()->toDateString(),
            'buyers' => [['client_id' => $this->client()->id, 'ownership_pct' => 100]], 'primary' => 0,
        ])->assertSessionHas('error');

        $this->assertSame(LotStatus::RESERVADO, (int) $lot->fresh()->status_id);
    }

    public function test_cancellation_requires_director_approval_and_releases_the_lot(): void
    {
        $advisor = $this->user(['ASESOR_COMERCIAL']);
        $director = $this->user(['DIRECTOR_COMERCIAL']);
        $lot = $this->lot();
        $this->actingAs($advisor)->post(route('sales.store'), [
            'lot_id' => $lot->id, 'agreed_price' => 48_000_000, 'financing_modality_id' => 1, 'sale_date' => now()->toDateString(),
            'buyers' => [['client_id' => $this->client()->id, 'ownership_pct' => 100]], 'primary' => 0,
        ]);
        $sale = Sale::where('lot_id', $lot->id)->sole();

        $this->actingAs($advisor)->post(route('sales.cancellation.request', $sale->id), ['request_type' => 'DESISTIMIENTO', 'reason' => 'El comprador desiste por cambio de ciudad'])
            ->assertSessionHas('success');
        $request = SaleCancellationRequest::where('sale_id', $sale->id)->sole();

        // El asesor no puede aprobar su propia solicitud.
        $this->actingAs($advisor)->post(route('sales.cancellation.resolve', [$sale->id, $request->id]), ['decision' => 'approve'])
            ->assertRedirect(route('home.redirect'));
        $this->assertSame(SaleStatus::VIGENTE, (int) $sale->fresh()->status_id);

        $this->actingAs($director)->get(route('sales.cancellations'))->assertOk()->assertSee($sale->sale_code);
        $this->actingAs($director)->post(route('sales.cancellation.resolve', [$sale->id, $request->id]), ['decision' => 'approve'])
            ->assertSessionHas('success');

        $sale->refresh();
        $this->assertSame(SaleStatus::DESISTIDA, (int) $sale->status_id);
        $this->assertSame($director->id, $sale->approved_by);
        $this->assertSame(LotStatus::DISPONIBLE, (int) $lot->fresh()->status_id);
        $this->assertSame(SaleCancellationRequest::APPROVED, $request->fresh()->status);
    }

    public function test_lookups_return_lots_and_clients(): void
    {
        $advisor = $this->user(['ASESOR_COMERCIAL']);
        $project = $this->project();
        $lot = $this->lot($project, ['block' => 'Q', 'lot_number' => '77']);
        $client = $this->client(person: ['first_name' => 'Zoraida']);

        $this->actingAs($advisor)->getJson(route('lookup.lots', ['project_id' => $project->id, 'q' => 'Q-77']))
            ->assertOk()->assertJsonPath('data.0.id', $lot->id);
        $this->actingAs($advisor)->getJson(route('lookup.clients', ['q' => 'Zoraida']))
            ->assertOk()->assertJsonPath('data.0.id', $client->id);
    }
}
