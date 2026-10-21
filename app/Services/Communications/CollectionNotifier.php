<?php

namespace App\Services\Communications;

use App\Mail\ClientNoticeMail;
use App\Models\Catalogs\ObligationStatus;
use App\Models\Commercial\Client;
use App\Models\Commercial\ClientNotification;
use App\Models\Payments\Payment;
use App\Support\ColombianCalendar;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

/**
 * CU-27 Recordatorios de vencimiento, avisos de mora y confirmaciones de pago.
 *
 * RN-23 (Ley 2300 de 2023) para las comunicaciones de cobro:
 *  · Solo por canales autorizados y vigentes (FK fk_notif_channel + revoked_at) y con
 *    autorización de datos vigente (RN-22).
 *  · Lunes a viernes de 7:00 a. m. a 7:00 p. m. y sábados de 8:00 a. m. a 3:00 p. m.;
 *    nunca domingos ni festivos (App\Support\ColombianCalendar).
 *  · Máximo una comunicación de cobro por día y por cliente.
 *  · Un solo canal por semana: si ya se contactó al cliente esa semana se usa el mismo canal.
 * La confirmación de pago no es una comunicación de cobro: sale de inmediato por un canal autorizado.
 * Canales automáticos: correo y portal; SMS y WhatsApp solo si hay un proveedor configurado.
 */
class CollectionNotifier
{
    public function __construct(private ContactChannelService $channels) {}

    /** Programa las comunicaciones de cobro del día. @return array{reminders:int, overdue:int} */
    public function schedule(?CarbonImmutable $day = null): array
    {
        $day = ($day ?? CarbonImmutable::today())->startOfDay();
        $created = ['overdue' => 0, 'reminders' => 0];

        // Los avisos de mora van primero: si un cliente tiene ambos, el de mora ocupa el cupo del día.
        foreach ($this->overdueCandidates($day) as $row) {
            $created['overdue'] += (int) $this->queue($row, ClientNotification::OVERDUE, $day);
        }
        foreach ($this->reminderCandidates($day) as $row) {
            $created['reminders'] += (int) $this->queue($row, ClientNotification::REMINDER, $day);
        }

        return $created;
    }

    /** Envía las comunicaciones programadas cuya hora llegó. @return array{sent:int, failed:int, rescheduled:int, cancelled:int} */
    public function send(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $result = ['sent' => 0, 'failed' => 0, 'rescheduled' => 0, 'cancelled' => 0];

        ClientNotification::with('client.person', 'client.consents')->where('status', 'PROGRAMADA')->where('scheduled_at', '<=', $now)
            ->orderBy('scheduled_at')->limit(500)->get()
            ->each(function (ClientNotification $n) use ($now, &$result) {
                $collection = in_array($n->notification_type, ClientNotification::COLLECTION_TYPES, true);

                if ($collection && ! self::allowedAt($now)) {
                    $n->update(['scheduled_at' => self::nextSlot($now)]);
                    $result['rescheduled']++;

                    return;
                }
                $channel = $this->channels->active($n->client)->get($n->channel);
                if (! $channel || ! $n->client->hasActiveConsent()) {
                    $n->update(['status' => 'CANCELADA', 'error_message' => 'El canal o la autorización de datos ya no están vigentes.']);
                    $result['cancelled']++;

                    return;
                }

                try {
                    $this->deliver($n, $channel->contact_value);
                    $n->update(['status' => 'ENVIADA', 'sent_at' => now(), 'error_message' => null]);
                    $result['sent']++;
                } catch (Throwable $e) {
                    $n->update(['status' => 'FALLIDA', 'error_message' => mb_substr($e->getMessage(), 0, 255)]);
                    $result['failed']++;
                }
            });

        return $result;
    }

    /** Confirmación de pago al pagador (no es cobro: sin ventana horaria). */
    public function paymentConfirmation(Payment $payment): ?ClientNotification
    {
        $payment->loadMissing('payer.person', 'payer.consents', 'obligation');
        $client = $payment->payer;
        if (! $client->hasActiveConsent()) {
            return null;
        }
        $channel = $this->channels->active($client)->keys()->intersect($this->automaticChannels())->first();
        if (! $channel) {
            return null;
        }

        $notification = ClientNotification::firstOrCreate(['payment_id' => $payment->id, 'channel' => $channel], [
            'client_id'         => $client->id,
            'notification_type' => ClientNotification::PAYMENT,
            'message'           => $this->message(ClientNotification::PAYMENT, $client, [
                'amount' => $payment->amount, 'date' => $payment->payment_date->format('Y-m-d'), 'code' => $payment->obligation->obligation_code,
            ]),
            'scheduled_at'      => now(),
            'status'            => 'PROGRAMADA',
        ]);

        return $notification;
    }

    // ── Reglas de horario (RN-23) ─────────────────────────────────────────
    public static function allowedAt(CarbonInterface $at): bool
    {
        if ($at->isSunday() || ColombianCalendar::isHoliday($at)) {
            return false;
        }
        $minutes = $at->hour * 60 + $at->minute;

        return $at->isSaturday() ? ($minutes >= 8 * 60 && $minutes < 15 * 60) : ($minutes >= 7 * 60 && $minutes < 19 * 60);
    }

    /** Primer momento permitido igual o posterior a $from. */
    public static function nextSlot(CarbonInterface $from): CarbonImmutable
    {
        $at = CarbonImmutable::instance($from)->second(0);
        for ($i = 0; $i < 30; $i++) {
            if (self::allowedAt($at)) {
                return $at;
            }
            $open = $at->isSaturday() ? 8 : 7;
            $workingDay = ! $at->isSunday() && ! ColombianCalendar::isHoliday($at);
            if ($workingDay && $at->hour < $open) {
                $at = $at->setTime($open, 0);    // todavía no abre la ventana de hoy
                continue;
            }
            $next = $at->addDay()->startOfDay();  // la ventana de hoy ya cerró o hoy no se puede
            $at = $next->setTime($next->isSaturday() ? 8 : 7, 0);
        }

        return $at;
    }

    // ── Internos ───────────────────────────────────────────────────────────
    private function queue(object $row, string $type, CarbonImmutable $day): bool
    {
        $client = Client::with('person', 'consents')->find($row->client_id);
        if (! $client || ! $client->hasActiveConsent()) {
            return false;
        }

        $preferred = (int) config('lothub.notifications.send_hour');
        $slot = self::nextSlot($day->setTime($preferred, 0));

        $existing = ClientNotification::where('client_id', $client->id)->whereIn('notification_type', ClientNotification::COLLECTION_TYPES)
            ->whereIn('status', ['PROGRAMADA', 'ENVIADA']);

        // Idempotente: la misma cuota y el mismo tipo no se programan dos veces el mismo día.
        if ((clone $existing)->where('notification_type', $type)->where('installment_id', $row->installment_id)
            ->whereDate('scheduled_at', $slot->toDateString())->exists()) {
            return false;
        }
        // Máximo una comunicación de cobro por día.
        if ((clone $existing)->whereDate('scheduled_at', $slot->toDateString())->exists()) {
            return false;
        }

        // Un solo canal por semana.
        $weekChannel = (clone $existing)->whereBetween('scheduled_at', [$slot->startOfWeek(), $slot->endOfWeek()])->value('channel');
        $active = $this->channels->active($client)->keys();
        $channel = $weekChannel
            ? ($active->contains($weekChannel) ? $weekChannel : null)
            : $active->intersect($this->automaticChannels())->first();
        if (! $channel) {
            return false;
        }

        ClientNotification::create([
            'client_id'         => $client->id,
            'installment_id'    => $row->installment_id,
            'channel'           => $channel,
            'notification_type' => $type,
            'message'           => $this->message($type, $client, (array) $row),
            'scheduled_at'      => $slot,
            'status'            => 'PROGRAMADA',
        ]);

        return true;
    }

    private function deliver(ClientNotification $n, string $address): void
    {
        match ($n->channel) {
            'EMAIL'  => Mail::to($address)->send(new ClientNoticeMail($n)),
            'PORTAL' => null,  // se muestra en «Mis avisos» del portal
            default  => throw new RuntimeException("No hay un proveedor configurado para el canal {$n->channel}."),
        };
    }

    /** Orden de preferencia de los canales que el sistema puede usar solo. */
    private function automaticChannels(): array
    {
        return array_values(array_filter(['EMAIL', 'WHATSAPP', 'SMS', 'PORTAL'],
            fn ($c) => in_array($c, ['EMAIL', 'PORTAL'], true) || filled(config('lothub.notifications.providers.'.strtolower($c)))));
    }

    /** Cuotas con saldo que vencen dentro de los días configurados. */
    private function reminderCandidates(CarbonImmutable $day): iterable
    {
        $dates = collect(config('lothub.notifications.reminder_days_before'))->map(fn ($d) => $day->addDays((int) $d)->toDateString());

        return $this->baseQuery()->whereIn('b.due_date', $dates)->where('b.balance', '>', 0)->get();
    }

    /** Obligaciones que hoy cumplen uno de los días de mora configurados (cuota más antigua vencida). */
    private function overdueCandidates(CarbonImmutable $day): iterable
    {
        $days = collect(config('lothub.notifications.overdue_days'))->map(fn ($d) => (int) $d);

        return $this->baseQuery()->where('b.balance', '>', 0)->where('b.due_date', '<', $day->toDateString())
            ->whereIn(DB::raw('DATEDIFF('.DB::getPdo()->quote($day->toDateString()).', b.due_date)'), $days)
            ->whereRaw('b.installment_number = (SELECT MIN(b2.installment_number) FROM v_installment_balance b2
                WHERE b2.obligation_id = b.obligation_id AND b2.balance > 0)')
            ->get();
    }

    private function baseQuery()
    {
        return DB::table('v_installment_balance as b')
            ->join('obligations as o', 'o.id', '=', 'b.obligation_id')
            ->join('payment_agreements as pa', 'pa.id', '=', 'o.agreement_id')
            ->join('sales as s', 's.id', '=', 'pa.sale_id')
            ->join('sale_clients as sc', fn ($j) => $j->on('sc.sale_id', '=', 's.id')->where('sc.is_primary', true))
            ->join('lots as l', 'l.id', '=', 's.lot_id')
            ->join('projects as p', 'p.id', '=', 'l.project_id')
            ->where('o.status_id', ObligationStatus::ACTIVA)
            ->select('b.installment_id', 'b.installment_number', 'b.due_date', 'b.balance', 'o.obligation_code as code',
                'sc.client_id', 'p.name as project', 'l.block', 'l.lot_number');
    }

    private function message(string $type, Client $client, array $data): string
    {
        $name = $client->person->first_name;
        $money = fn ($v) => '$ '.number_format((float) $v, 0, ',', '.');
        $company = config('lothub.company');
        $lot = isset($data['project']) ? "{$data['project']} (Mz {$data['block']} lote {$data['lot_number']})" : '';
        $installment = isset($data['installment_number']) ? ((int) $data['installment_number'] === 0 ? 'la cuota inicial' : "la cuota {$data['installment_number']}") : '';

        $text = match ($type) {
            ClientNotification::REMINDER => "Hola {$name}, te recordamos que {$installment} de tu lote en {$lot} por {$money($data['balance'])} vence el {$data['due_date']}. Puedes pagar en línea desde el portal de clientes.",
            ClientNotification::OVERDUE  => "Hola {$name}, {$installment} de tu lote en {$lot}, vencida el {$data['due_date']}, tiene un saldo de {$money($data['balance'])}. Ponte al día desde el portal de clientes o comunícate al {$company['phone']}.",
            ClientNotification::PAYMENT  => "Hola {$name}, recibimos tu pago de {$money($data['amount'])} del {$data['date']} para la obligación {$data['code']}. ¡Gracias!",
            default                      => (string) ($data['text'] ?? ''),
        };

        return mb_substr($text.' — '.$company['name'], 0, 500);
    }
}
