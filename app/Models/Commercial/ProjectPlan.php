<?php

namespace App\Models\Commercial;

use App\Models\Security\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Versión cargada del plano de un proyecto (DWG/DXF) y su resultado procesado.
 * El JSON del plano (geometría de lotes, manzanas, vías…) vive en disco: data_path.
 */
class ProjectPlan extends Model
{
    const PROCESSING = 'PROCESANDO';
    const READY = 'LISTO';
    const FAILED = 'ERROR';

    protected $table = 'project_plans';

    protected $fillable = [
        'project_id', 'original_name', 'source_path', 'source_hash', 'data_path', 'status',
        'error_message', 'lots_detected', 'report', 'is_published', 'uploaded_by', 'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'report'       => 'array',
            'is_published' => 'boolean',
            'processed_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isReady(): bool
    {
        return $this->status === self::READY;
    }

    /** Geometría procesada (decodificada) o null si aún no está lista. */
    public function data(): ?array
    {
        if (! $this->isReady() || ! $this->data_path || ! is_file(storage_path('app/'.$this->data_path))) {
            return null;
        }

        return json_decode(file_get_contents(storage_path('app/'.$this->data_path)), true);
    }
}
