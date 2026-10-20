<?php

namespace App\Models\Commercial;

use App\Models\Catalogs\City;
use App\Models\Catalogs\LotStatus;
use App\Models\Catalogs\ProjectStatus;
use App\Models\Security\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Proyecto inmobiliario: entidad raíz de etapas, lotes y negociaciones. */
class Project extends Model
{
    use SoftDeletes;

    protected $table = 'projects';

    protected $fillable = [
        'code', 'name', 'city_id', 'address', 'description',
        'total_area_m2', 'launch_date', 'status_id', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'launch_date'   => 'date:Y-m-d',
            'total_area_m2' => 'decimal:2',
        ];
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(ProjectStatus::class, 'status_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function stages(): HasMany
    {
        return $this->hasMany(ProjectStage::class)->orderBy('display_order');
    }

    public function lots(): HasMany
    {
        return $this->hasMany(Lot::class);
    }

    public function plans(): HasMany
    {
        return $this->hasMany(ProjectPlan::class)->latest('id');
    }

    /** Fotos de la ficha de venta, en el orden definido por el usuario. */
    public function photos(): HasMany
    {
        return $this->hasMany(ProjectPhoto::class)->orderBy('sort_order')->orderBy('id');
    }

    /** Foto de portada; si no hay una marcada se usa la primera de la galería (ver coverPhoto()). */
    public function cover(): HasOne
    {
        return $this->hasOne(ProjectPhoto::class)->where('is_cover', true);
    }

    public function coverPhoto(): ?ProjectPhoto
    {
        return $this->cover ?? $this->photos->first();
    }

    /** Plano que se muestra en el sitio público (como máximo uno). */
    public function publishedPlan(): HasOne
    {
        return $this->hasOne(ProjectPlan::class)->where('is_published', true);
    }

    /** Proyectos visibles en el sitio público (CU-30: solo EN_VENTA). */
    public function scopeOnSale(Builder $query): Builder
    {
        return $query->where('status_id', ProjectStatus::EN_VENTA);
    }

    /** Agrega available_lots, total_lots y min_price para las tarjetas públicas. */
    public function scopeWithLotStats(Builder $query): Builder
    {
        return $query
            ->withCount('lots as total_lots')
            ->withCount(['lots as available_lots' => fn ($q) => $q->where('status_id', LotStatus::DISPONIBLE)])
            ->withMin(['lots as min_price' => fn ($q) => $q->where('status_id', LotStatus::DISPONIBLE)], 'list_price');
    }
}
