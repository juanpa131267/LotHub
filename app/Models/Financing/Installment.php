<?php

namespace App\Models\Financing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Cuota del plan de pagos. La número 0 es la cuota inicial (RN-12). */
class Installment extends Model
{
    protected $table = 'installments';

    public $timestamps = false;

    protected $fillable = ['obligation_id', 'installment_number', 'due_date', 'principal_amount', 'interest_amount'];

    protected function casts(): array
    {
        return [
            'due_date'         => 'date:Y-m-d',
            'principal_amount' => 'decimal:2',
            'interest_amount'  => 'decimal:2',
        ];
    }

    public function obligation(): BelongsTo
    {
        return $this->belongsTo(Obligation::class);
    }
}
