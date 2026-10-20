<?php

namespace App\Services;

use App\Models\Security\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Punto único para escribir en audit_logs (RF-20, RN-27).
 * Los cambios de modelos se registran solos mediante AuditObserver; usa este
 * servicio directamente para eventos que no son un CRUD (login, asignaciones...).
 */
class AuditLogger
{
    public static function log(string $action, string $table, int $recordId, array $previous = [], array $new = [], ?int $userId = null): void
    {
        try {
            AuditLog::create([
                'user_id'         => $userId ?? Auth::id(),
                'action'          => $action,
                'table_name'      => $table,
                'record_id'       => $recordId,
                'previous_values' => $previous ?: null,
                'new_values'      => $new ?: null,
                'ip_address'      => request()?->ip(),
            ]);
        } catch (\Throwable $e) {
            // La auditoría nunca debe tumbar la operación del usuario.
            Log::warning('No se pudo registrar auditoría: '.$e->getMessage());
        }
    }

    public static function model(string $action, Model $model, array $previous = [], array $new = []): void
    {
        static::log($action, $model->getTable(), (int) $model->getKey(), $previous, $new);
    }
}
