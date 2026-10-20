<?php

namespace App\Http\Controllers\Security;

use App\Http\Controllers\Crud\CrudController;
use App\Models\Security\AuditLog;
use Illuminate\Database\Eloquent\Model;

/** CU-26 Consultar auditoría. Solo lectura: no se crea, edita ni elimina. */
class AuditLogController extends CrudController
{
    const MODEL = AuditLog::class;
    const ROUTE = 'audit';
    const PERMISSION = 'auditoria';

    protected array $with = ['user'];
    protected array $searchable = ['action', 'table_name', 'record_id', 'user.username', 'ip_address'];
    protected array $actions = ['create' => false, 'edit' => false, 'delete' => false];

    protected function meta(): array
    {
        return [
            'title'    => 'Auditoría',
            'subtitle' => 'Operaciones críticas con usuario, fecha y valores anteriores / nuevos.',
            'icon'     => 'ph-fingerprint',
            'singular' => 'registro',
        ];
    }

    protected function columns(): array
    {
        return [
            ['key' => 'created_at', 'label' => 'Fecha', 'type' => 'muted'],
            ['key' => 'user_label', 'label' => 'Usuario', 'type' => 'strong'],
            ['key' => 'action', 'label' => 'Acción', 'type' => 'badge', 'colors' => [
                'CREAR' => 'success', 'ACTUALIZAR' => 'info', 'ELIMINAR' => 'danger', 'RESTAURAR' => 'warning',
                'LOGIN' => 'neutral', 'LOGOUT' => 'neutral', 'LOGIN_FALLIDO' => 'warning', 'CUENTA_BLOQUEADA' => 'danger',
            ]],
            ['key' => 'table_name', 'label' => 'Tabla', 'type' => 'code'],
            ['key' => 'record_id', 'label' => 'Registro', 'type' => 'number'],
            ['key' => 'changes', 'label' => 'Detalle', 'type' => 'json'],
        ];
    }

    protected function filters(): array
    {
        $actions = AuditLog::query()->distinct()->orderBy('action')->pluck('action', 'action')->all();

        return [['name' => 'action', 'label' => 'Acción', 'options' => $actions]];
    }

    protected function row(Model $log): array
    {
        return $log->toArray() + [
            'user_label' => $log->user?->username ?? 'Sistema',
            'changes'    => array_filter(['antes' => $log->previous_values, 'después' => $log->new_values, 'ip' => $log->ip_address]),
        ];
    }
}
