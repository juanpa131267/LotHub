<?php

namespace App\Models\Commercial;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Foto del proyecto para la ficha de venta. Archivos en storage/app/photos/{project_id}/. */
class ProjectPhoto extends Model
{
    protected $table = 'project_photos';

    protected $fillable = [
        'project_id', 'path', 'thumb_path', 'original_name', 'caption',
        'width', 'height', 'sort_order', 'is_cover', 'uploaded_by',
    ];

    protected $appends = ['url', 'thumb_url'];

    protected $hidden = ['path', 'thumb_path'];

    protected function casts(): array
    {
        return ['is_cover' => 'boolean'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    public function getUrlAttribute(): string
    {
        return route('media.photo', [$this->id, 'full']).'?v='.$this->updated_at?->timestamp;
    }

    public function getThumbUrlAttribute(): string
    {
        return route('media.photo', [$this->id, 'thumb']).'?v='.$this->updated_at?->timestamp;
    }
}
