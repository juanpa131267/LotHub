<?php

namespace App\Models\Commercial;

use App\Models\Catalogs\FinancingModality;
use App\Models\Catalogs\SaleStatus;
use App\Models\Documents\Document;
use App\Models\Security\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Negociación (venta) de un lote — CU-12, RF-09. Los triggers garantizan RN-05 (lote
 * DISPONIBLE o reservado por la misma reserva; pasa a VENDIDO y la reserva a CONVERTIDA)
 * y active_lot_id, RN-06 (una sola negociación VIGENTE o PAGADA por lote).
 */
class Sale extends Model
{
    protected $table = 'sales';

    protected $fillable = [
        'sale_code', 'lot_id', 'reservation_id', 'advisor_id', 'sale_date', 'agreed_price',
        'financing_modality_id', 'status_id', 'cancellation_reason', 'approved_by',
    ];

    protected function casts(): array
    {
        return [
            'sale_date'    => 'date:Y-m-d',
            'agreed_price' => 'decimal:2',
        ];
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function advisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'advisor_id')->withTrashed();
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by')->withTrashed();
    }

    public function modality(): BelongsTo
    {
        return $this->belongsTo(FinancingModality::class, 'financing_modality_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(SaleStatus::class, 'status_id');
    }

    /** Compradores con su participación (RN-07). */
    public function clients(): BelongsToMany
    {
        return $this->belongsToMany(Client::class, 'sale_clients')
            ->withPivot('is_primary', 'ownership_pct')
            ->orderByPivot('is_primary', 'desc');
    }

    public function primaryClient(): ?Client
    {
        return $this->clients->firstWhere('pivot.is_primary', true);
    }

    public function cancellationRequests(): HasMany
    {
        return $this->hasMany(SaleCancellationRequest::class)->latest('requested_at')->latest('id');
    }

    public function pendingCancellation(): HasOne
    {
        return $this->hasOne(SaleCancellationRequest::class)->where('status', SaleCancellationRequest::PENDING);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class)->latest('id');
    }

    public function isActive(): bool
    {
        return (int) $this->status_id === SaleStatus::VIGENTE;
    }

    /** Siguiente código consecutivo: VTA-000001. */
    public static function nextCode(): string
    {
        return 'VTA-'.str_pad((string) ((int) static::max('id') + 1), 6, '0', STR_PAD_LEFT);
    }
}
