<?php

namespace App\Models\Commercial;

use App\Models\Security\User;
use App\Support\ColombianCalendar;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Consulta o reclamo del titular (CU-28 / CU-29, Ley 1581 de 2012). RN-24: las consultas se
 * responden en máximo 10 días hábiles y los reclamos (incluye rectificación y supresión) en 15.
 */
class ClientRequest extends Model
{
    const TYPES = [
        'CONSULTA'      => 'Consulta',
        'RECLAMO'       => 'Reclamo',
        'RECTIFICACION' => 'Rectificación de datos',
        'SUPRESION'     => 'Supresión de datos',
    ];

    const STATUSES = [
        'RADICADA'   => 'Radicada',
        'EN_TRAMITE' => 'En trámite',
        'RESPONDIDA' => 'Respondida',
        'CERRADA'    => 'Cerrada',
    ];

    protected $table = 'client_requests';

    public $timestamps = false;

    protected $fillable = [
        'client_id', 'request_type', 'subject', 'description', 'status', 'filed_at', 'due_date', 'response', 'answered_by', 'answered_at',
    ];

    protected function casts(): array
    {
        return [
            'filed_at'    => 'datetime:Y-m-d H:i',
            'due_date'    => 'date:Y-m-d',
            'answered_at' => 'datetime:Y-m-d H:i',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function answeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'answered_by')->withTrashed();
    }

    /** RN-24: plazo legal en días hábiles según el tipo. */
    public static function businessDaysFor(string $type): int
    {
        return $type === 'CONSULTA' ? 10 : 15;
    }

    public static function dueDateFor(string $type, ?DateTimeInterface $filedAt = null): string
    {
        return ColombianCalendar::addBusinessDays(CarbonImmutable::instance($filedAt ?? now()), self::businessDaysFor($type))->toDateString();
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['RADICADA', 'EN_TRAMITE'], true);
    }

    public function isOverdue(): bool
    {
        return $this->isOpen() && $this->due_date->endOfDay()->isPast();
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->request_type] ?? $this->request_type;
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
