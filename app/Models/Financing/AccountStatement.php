<?php

namespace App\Models\Financing;

use App\Models\Security\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Estado de cuenta en PDF generado a una fecha de corte, con su huella SHA-256 (CU-24). */
class AccountStatement extends Model
{
    protected $table = 'account_statements';

    public $timestamps = false;

    protected $fillable = ['obligation_id', 'cut_off_date', 'file_path', 'file_hash', 'generated_by', 'generated_at'];

    protected function casts(): array
    {
        return [
            'cut_off_date' => 'date:Y-m-d',
            'generated_at' => 'datetime:Y-m-d H:i',
        ];
    }

    public function obligation(): BelongsTo
    {
        return $this->belongsTo(Obligation::class);
    }

    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by')->withTrashed();
    }
}
