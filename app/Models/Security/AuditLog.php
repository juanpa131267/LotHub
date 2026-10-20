<?php

namespace App\Models\Security;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Registro de auditoría (RF-20, RN-27). Solo se inserta: nunca se edita. */
class AuditLog extends Model
{
    protected $table = 'audit_logs';

    const UPDATED_AT = null;

    protected $fillable = [
        'user_id', 'action', 'table_name', 'record_id',
        'previous_values', 'new_values', 'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'previous_values' => 'array',
            'new_values'      => 'array',
            'created_at'      => 'datetime:Y-m-d H:i',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
