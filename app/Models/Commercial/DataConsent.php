<?php

namespace App\Models\Commercial;

use Illuminate\Database\Eloquent\Model;

/** Autorización de tratamiento de datos por versión de política (Ley 1581 de 2012). */
class DataConsent extends Model
{
    protected $table = 'data_consents';

    public $timestamps = false;

    protected $fillable = ['person_id', 'policy_version', 'channel', 'granted_at', 'revoked_at', 'ip_address'];

    protected function casts(): array
    {
        return ['granted_at' => 'datetime', 'revoked_at' => 'datetime'];
    }
}
