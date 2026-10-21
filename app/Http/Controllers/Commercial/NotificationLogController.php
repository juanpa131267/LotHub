<?php

namespace App\Http\Controllers\Commercial;

use App\Http\Controllers\Crud\CrudController;
use App\Models\Commercial\ClientNotification;
use App\Models\Commercial\ContactChannel;
use Illuminate\Database\Eloquent\Model;

/** Registro de las comunicaciones a clientes (CU-27): qué se envió, por qué canal y cuándo. */
class NotificationLogController extends CrudController
{
    const MODEL = ClientNotification::class;
    const ROUTE = 'notifications';
    const PERMISSION = 'comunicaciones';

    protected array $with = ['client.person'];
    protected array $searchable = ['message', 'client.client_code', 'client.person.first_name', 'client.person.last_name'];
    protected array $actions = ['create' => false, 'edit' => false, 'delete' => false];
    protected string $orderBy = 'scheduled_at';

    protected function meta(): array
    {
        return [
            'title'    => 'Comunicaciones',
            'subtitle' => 'Recordatorios, avisos de mora y confirmaciones enviados a los clientes por sus canales autorizados (Ley 2300).',
            'icon'     => 'ph-megaphone',
            'singular' => 'comunicación',
        ];
    }

    protected function columns(): array
    {
        return [
            ['key' => 'scheduled_at', 'label' => 'Programada', 'type' => 'muted'],
            ['key' => 'client.person.full_name', 'label' => 'Cliente', 'type' => 'strong'],
            ['key' => 'type_label', 'label' => 'Tipo'],
            ['key' => 'channel_label', 'label' => 'Canal', 'type' => 'muted'],
            ['key' => 'message', 'label' => 'Mensaje'],
            ['key' => 'status', 'label' => 'Estado', 'type' => 'badge', 'colors' => ['ENVIADA' => 'success', 'PROGRAMADA' => 'info', 'FALLIDA' => 'danger', 'CANCELADA' => 'neutral']],
        ];
    }

    protected function row(Model $n): array
    {
        return $n->toArray() + ['type_label' => $n->type_label, 'channel_label' => ContactChannel::CHANNELS[$n->channel] ?? $n->channel];
    }

    protected function filters(): array
    {
        return [
            ['name' => 'status', 'label' => 'Estado', 'options' => ['PROGRAMADA' => 'Programada', 'ENVIADA' => 'Enviada', 'FALLIDA' => 'Fallida', 'CANCELADA' => 'Cancelada']],
            ['name' => 'notification_type', 'label' => 'Tipo', 'options' => ClientNotification::TYPES],
            ['name' => 'channel', 'label' => 'Canal', 'options' => ContactChannel::CHANNELS],
        ];
    }
}
