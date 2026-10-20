<?php

namespace App\Models\Catalogs;

use Illuminate\Database\Eloquent\Model;

/** Periodicidad de las cuotas con su intervalo en meses. */
class PaymentFrequency extends Model
{
    protected $table = 'payment_frequencies';

    public $timestamps = false;

    protected $fillable = ['code', 'name', 'months_interval'];
}
