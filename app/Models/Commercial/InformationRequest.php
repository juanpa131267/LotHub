<?php

namespace App\Models\Commercial;

use App\Models\Security\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Solicitud de información enviada por un visitante desde el sitio público (RF-21). */
class InformationRequest extends Model
{
    const STATUSES = ['NUEVA' => 'Nueva', 'ATENDIDA' => 'Atendida', 'DESCARTADA' => 'Descartada'];

    protected $table = 'information_requests';

    const UPDATED_AT = null;

    protected $fillable = [
        'project_id', 'full_name', 'email', 'phone', 'message',
        'consent_accepted', 'status', 'attended_by',
    ];

    protected function casts(): array
    {
        return [
            'consent_accepted' => 'boolean',
            'created_at'       => 'datetime:Y-m-d H:i',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    public function attendedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attended_by');
    }
}
