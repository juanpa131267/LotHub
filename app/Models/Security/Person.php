<?php

namespace App\Models\Security;

use App\Models\Catalogs\City;
use App\Models\Catalogs\IdentificationType;
use App\Models\Commercial\Client;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Persona natural (tabla people). Es la base común de usuarios (empleados o
 * clientes con portal) y de clientes: ambos la referencian con person_id único.
 */
class Person extends Model
{
    use SoftDeletes;

    protected $table = 'people';

    protected $fillable = [
        'identification_type_id', 'identification_number', 'first_name', 'last_name',
        'email', 'phone', 'city_id', 'address',
    ];

    protected $appends = ['full_name'];

    public function identificationType(): BelongsTo
    {
        return $this->belongsTo(IdentificationType::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }

    public function client(): HasOne
    {
        return $this->hasOne(Client::class);
    }

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }
}
