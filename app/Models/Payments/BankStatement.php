<?php

namespace App\Models\Payments;

use App\Models\Catalogs\PaymentMethod;
use App\Models\Security\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Extracto bancario cargado para la asignación automática de pagos (parche 005). */
class BankStatement extends Model
{
    protected $table = 'bank_statements';

    public $timestamps = false;

    protected $fillable = [
        'bank_name', 'account_label', 'original_name', 'file_hash', 'storage_path', 'period_start', 'period_end',
        'lines_count', 'credits_total', 'payment_method_id', 'uploaded_by', 'uploaded_at',
    ];

    protected function casts(): array
    {
        return [
            'period_start'  => 'date:Y-m-d',
            'period_end'    => 'date:Y-m-d',
            'credits_total' => 'decimal:2',
            'uploaded_at'   => 'datetime:Y-m-d H:i',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BankStatementLine::class, 'statement_id')->orderBy('line_number');
    }

    public function method(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by')->withTrashed();
    }
}
