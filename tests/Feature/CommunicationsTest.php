<?php

namespace Tests\Feature;

use App\Mail\ClientNoticeMail;
use App\Mail\ClientRequestAnsweredMail;
use App\Models\Commercial\ClientNotification;
use App\Models\Commercial\ClientRequest;
use App\Models\Financing\Installment;
use App\Services\Communications\CollectionNotifier;
use App\Services\Communications\ContactChannelService;
use App\Services\Payments\PaymentService;
use App\Services\Portal\PortalAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Sprint 9: canales autorizados, comunicaciones de cobro (Ley 2300, RN-23) y consultas y reclamos (RN-24). */
class CommunicationsTest extends TestCase
{
    private function withChannels($client, array $channels = ['EMAIL' => true]): void
    {
        app(ContactChannelService::class)->sync($client->load('person'), collect($channels)->map(fn ($on) => ['enabled' => $on])->all());
    }

    public function test_collection_window_rules(): void
    {
        $at = fn (string $d) => CarbonImmutable::parse($d, config('app.timezone'));

        $this->assertTrue(CollectionNotifier::allowedAt($at('2026-10-21 07:00')));      // miércoles
        $this->assertFalse(CollectionNotifier::allowedAt($at('2026-10-21 19:00')));
        $this->assertTrue(CollectionNotifier::allowedAt($at('2026-10-24 14:59')));      // sábado
        $this->assertFalse(CollectionNotifier::allowedAt($at('2026-10-24 15:00')));
        $this->assertFalse(CollectionNotifier::allowedAt($at('2026-10-25 10:00')));     // domingo
        $this->assertFalse(CollectionNotifier::allowedAt($at('2026-11-02 10:00')));     // festivo (Todos los Santos)

        // Sábado 15:30 → el domingo no se puede, el lunes 2 nov es festivo → martes 3 nov 7:00.
        $this->assertSame('2026-11-03 07:00', CollectionNotifier::nextSlot($at('2026-10-31 15:30'))->format('Y-m-d H:i'));
        $this->assertSame('2026-10-21 07:00', CollectionNotifier::nextSlot($at('2026-10-21 05:10'))->format('Y-m-d H:i'));
    }

    public function test_channels_require_data_consent(): void
    {
        $client = $this->client(consent: false);

        $this->expectException(\RuntimeException::class);
        $this->withChannels($client);
    }

    public function test_overdue_notice_is_scheduled_once_per_day_and_sent_by_email(): void
    {
        Mail::fake();
        $client = $this->client();
        $this->withChannels($client);
        $obligation = $this->obligation($client);
        $wednesday = CarbonImmutable::parse('2026-10-21');
        // Cuotas 0 y 1 con 8 días de mora ese miércoles; el resto, al día.
        Installment::where('obligation_id', $obligation->id)->update(['due_date' => $wednesday->subDays(8)->toDateString()]);
        Installment::where('obligation_id', $obligation->id)->where('installment_number', '>', 1)->update(['due_date' => $wednesday->addMonth()->toDateString()]);

        $notifier = app(CollectionNotifier::class);
        $this->assertSame(['overdue' => 1, 'reminders' => 0], $notifier->schedule($wednesday));
        $this->assertSame(['overdue' => 0, 'reminders' => 0], $notifier->schedule($wednesday));     // idempotente

        $notice = ClientNotification::where('client_id', $client->id)->sole();
        $this->assertSame('AVISO_MORA', $notice->notification_type);
        $this->assertSame('EMAIL', $notice->channel);
        $this->assertSame('2026-10-21 09:00', $notice->scheduled_at->format('Y-m-d H:i'));
        $this->assertStringContainsString('vencida el', $notice->message);

        // A las 6:00 todavía no se envía; a las 9:05 sí.
        $this->assertSame(0, $notifier->send(CarbonImmutable::parse('2026-10-21 06:00'))['sent']);
        $this->assertSame(1, $notifier->send(CarbonImmutable::parse('2026-10-21 09:05'))['sent']);
        Mail::assertSent(ClientNoticeMail::class, fn ($m) => $m->hasTo($client->person->email));
    }

    public function test_one_channel_per_week_and_revoked_channels_are_not_used(): void
    {
        Mail::fake();
        $client = $this->client();
        app(PortalAccessService::class)->invite($client);
        $this->withChannels($client, ['EMAIL' => true, 'PORTAL' => true]);
        $obligation = $this->obligation($client);
        $notifier = app(CollectionNotifier::class);

        // Recordatorio el lunes por correo (cuota que vence en 3 días).
        Installment::where('obligation_id', $obligation->id)->update(['due_date' => '2026-10-22']);
        $notifier->schedule(CarbonImmutable::parse('2026-10-19'));
        $this->assertSame('EMAIL', ClientNotification::where('client_id', $client->id)->value('channel'));

        // El cliente revoca el correo: esa semana no se usa otro canal (un canal por semana).
        $this->withChannels($client, ['EMAIL' => false, 'PORTAL' => true]);
        Installment::where('obligation_id', $obligation->id)->update(['due_date' => '2026-10-24']);
        $notifier->schedule(CarbonImmutable::parse('2026-10-21'));
        $this->assertSame(1, ClientNotification::where('client_id', $client->id)->count());

        // Y la comunicación ya programada por correo se cancela al enviar.
        $this->assertSame(1, $notifier->send(CarbonImmutable::parse('2026-10-19 10:00'))['cancelled']);
    }

    public function test_payment_confirmation_is_queued_after_a_payment(): void
    {
        Mail::fake();
        $client = $this->client();
        app(PortalAccessService::class)->invite($client);
        $this->withChannels($client, ['PORTAL' => true]);
        $obligation = $this->obligation($client);

        $payment = app(PaymentService::class)->register($obligation, $client, now()->toDateString(), 2_000_000, 2, 'C-9', $this->user()->id);

        $notice = ClientNotification::where('payment_id', $payment->id)->sole();
        $this->assertSame('CONFIRMACION_PAGO', $notice->notification_type);
        $this->assertSame('PORTAL', $notice->channel);
        $this->assertStringContainsString('recibimos tu pago de $ 2.000.000', $notice->message);
    }

    public function test_client_files_a_claim_with_legal_due_date_and_staff_answers(): void
    {
        Mail::fake();
        $client = $this->client();
        $portalUser = app(PortalAccessService::class)->invite($client);

        $this->actingAs($portalUser)->get(route('portal.service'))->assertOk();
        $this->actingAs($portalUser)->post(route('portal.requests.store'), [
            'request_type' => 'RECLAMO', 'subject' => 'Abono no reflejado', 'description' => 'Consigné el 10 de octubre y no veo el abono en mi estado de cuenta.',
        ])->assertSessionHas('success');

        $claim = ClientRequest::where('client_id', $client->id)->sole();
        $this->assertSame('RADICADA', $claim->status);
        $this->assertSame(ClientRequest::dueDateFor('RECLAMO', $claim->filed_at), $claim->due_date->toDateString());   // 15 días hábiles

        $clerk = $this->user(['AUXILIAR_CARTERA']);
        $this->actingAs($clerk)->getJson(route('client-requests.data'))->assertOk()->assertJsonPath('data.0.term', 'En plazo');
        $this->actingAs($clerk)->get(route('client-requests.show', $claim->id))->assertOk()->assertSee('Abono no reflejado');
        $this->actingAs($clerk)->post(route('client-requests.answer', $claim->id), ['response' => 'Verificamos la consignación y la aplicamos a la cuota 2.'])
            ->assertSessionHas('success');

        $this->assertSame('RESPONDIDA', $claim->fresh()->status);
        Mail::assertSent(ClientRequestAnsweredMail::class);
        $this->actingAs($portalUser)->get(route('portal.service'))->assertSee('Verificamos la consignación');
    }
}
