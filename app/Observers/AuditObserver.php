<?php

namespace App\Observers;

use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;

/**
 * Registra automáticamente CREAR / ACTUALIZAR / ELIMINAR / RESTAURAR de los
 * modelos registrados en AppServiceProvider::AUDITED_MODELS.
 */
class AuditObserver
{
    private const HIDDEN = ['password_hash', 'created_at', 'updated_at', 'deleted_at', 'failed_attempts', 'last_login_at'];

    public function created(Model $model): void
    {
        AuditLogger::model('CREAR', $model, [], $this->clean($model->getAttributes()));
    }

    public function updated(Model $model): void
    {
        $changes = $this->clean($model->getChanges());

        if (! $changes) {
            return;
        }

        $previous = array_intersect_key($model->getOriginal(), $changes);
        AuditLogger::model('ACTUALIZAR', $model, $previous, $changes);
    }

    public function deleted(Model $model): void
    {
        AuditLogger::model('ELIMINAR', $model, $this->clean($model->getAttributes()));
    }

    public function restored(Model $model): void
    {
        AuditLogger::model('RESTAURAR', $model);
    }

    private function clean(array $attributes): array
    {
        return array_diff_key($attributes, array_flip(self::HIDDEN));
    }
}
