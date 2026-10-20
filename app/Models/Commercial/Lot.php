<?php

namespace App\Models\Commercial;

use App\Models\Catalogs\LotStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lote identificado por proyecto + manzana + número (RN-01).
 * El estado RESERVADO/VENDIDO lo cambian los triggers de reservas y ventas;
 * desde la interfaz solo se alterna DISPONIBLE ↔ BLOQUEADO.
 */
class Lot extends Model
{
    protected $table = 'lots';

    protected $fillable = [
        'project_id', 'stage_id', 'block', 'lot_number',
        'area_m2', 'list_price', 'status_id', 'notes',
    ];

    protected $appends = ['label'];

    protected function casts(): array
    {
        return [
            'area_m2'    => 'decimal:2',
            'list_price' => 'decimal:2',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(ProjectStage::class, 'stage_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(LotStatus::class, 'status_id');
    }

    public function getLabelAttribute(): string
    {
        return self::groupLabel($this->block, short: true)." · Lote {$this->lot_number}";
    }

    /**
     * Nombre del grupo del lote. Los planos por manzanas usan letras (A, B…); los planos por
     * etapas (Civil 3D) crean los lotes con block = E1, E2… (ver tools/plan-processor).
     */
    public static function groupLabel(string $block, bool $short = false): string
    {
        if (preg_match('/^E(\d+)$/', $block, $m)) {
            $roman = [1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV', 5 => 'V', 6 => 'VI'][(int) $m[1]] ?? $m[1];

            return "Etapa {$roman}";
        }

        return ($short ? 'Mz ' : 'Manzana ').$block;
    }

    /** Solo los estados manuales pueden cambiarse desde el formulario. */
    public function hasManualStatus(): bool
    {
        return in_array((int) $this->status_id, [LotStatus::DISPONIBLE, LotStatus::BLOQUEADO], true);
    }
}
