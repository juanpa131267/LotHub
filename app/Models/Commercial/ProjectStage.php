<?php

namespace App\Models\Commercial;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Etapa comercial de un proyecto (Etapa 1, Etapa 2...). */
class ProjectStage extends Model
{
    protected $table = 'project_stages';

    public $timestamps = false;

    protected $fillable = ['project_id', 'name', 'display_order'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function lots(): HasMany
    {
        return $this->hasMany(Lot::class, 'stage_id');
    }
}
