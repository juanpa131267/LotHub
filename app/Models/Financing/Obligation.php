<?php

namespace App\Models\Financing;

use App\Models\Catalogs\ObligationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Obligación generada por un acuerdo de pago (1:1, RN-11). Su saldo no se almacena: se
 * calcula en las vistas v_installment_balance y v_obligation_balance.
 */
class Obligation extends Model
{
    protected $table = 'obligations';

    const UPDATED_AT = null;

    protected $fillable = ['obligation_code', 'agreement_id', 'status_id', 'opened_at', 'closed_at'];

    protected function casts(): array
    {
        return [
            'opened_at' => 'date:Y-m-d',
            'closed_at' => 'date:Y-m-d',
        ];
    }

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(PaymentAgreement::class, 'agreement_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(ObligationStatus::class, 'status_id');
    }

    public function installments(): HasMany
    {
        return $this->hasMany(Installment::class)->orderBy('installment_number');
    }

    public function isActive(): bool
    {
        return (int) $this->status_id === ObligationStatus::ACTIVA;
    }

    /** Cuotas con saldo, mora y estado calculado (v_installment_balance), en orden. */
    public function balances(): Collection
    {
        return DB::table('v_installment_balance')->where('obligation_id', $this->id)->orderBy('installment_number')->get();
    }

    /** Consolidado de la obligación calculado sobre sus cuotas (vale también si no está ACTIVA). */
    public function summary(): object
    {
        $rows = $this->balances();

        return (object) [
            'total_due'            => (float) $rows->sum('amount_due'),
            'total_paid'           => (float) $rows->sum('amount_paid'),
            'balance'              => (float) $rows->sum(fn ($r) => max(0, (float) $r->balance)),
            'overdue_installments' => $rows->where('days_overdue', '>', 0)->count(),
            'max_days_overdue'     => (int) $rows->max('days_overdue'),
            'next_due_date'        => $rows->first(fn ($r) => (float) $r->balance > 0)?->due_date,
        ];
    }

    /** Siguiente código consecutivo: OBL-000001. */
    public static function nextCode(): string
    {
        return 'OBL-'.str_pad((string) ((int) static::max('id') + 1), 6, '0', STR_PAD_LEFT);
    }
}
