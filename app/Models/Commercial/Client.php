<?php

namespace App\Models\Commercial;

use App\Models\Security\Person;
use App\Models\Security\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/** Comprador: especialización de Person (clients.person_id es único). */
class Client extends Model
{
    use SoftDeletes;

    protected $table = 'clients';

    protected $fillable = ['person_id', 'client_code', 'occupation', 'registered_by'];

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class)->withTrashed();
    }

    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    public function consents(): HasMany
    {
        return $this->hasMany(DataConsent::class, 'person_id', 'person_id');
    }

    /** RN-22: autorización de tratamiento de datos vigente. */
    public function hasActiveConsent(): bool
    {
        return $this->consents()->whereNull('revoked_at')->exists();
    }

    /** ¿Figura en alguna reserva o negociación? (bloquea su eliminación). */
    public function hasCommercialHistory(): bool
    {
        return DB::table('reservations')->where('client_id', $this->id)->exists()
            || DB::table('sale_clients')->where('client_id', $this->id)->exists();
    }

    /** Siguiente código consecutivo: CLI-000001. */
    public static function nextCode(): string
    {
        $next = (int) static::withTrashed()->max('id') + 1;

        return 'CLI-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }
}
