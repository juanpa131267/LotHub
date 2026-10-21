<?php

namespace App\Models\Payments;

use App\Models\Financing\Obligation;
use App\Models\Security\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Movimiento crédito de un extracto con el resultado del motor de asignación. */
class BankStatementLine extends Model
{
    const PENDING = 'PENDIENTE';
    const ASSIGNED = 'ASIGNADA';
    const REVIEW = 'REVISION';
    const DISCARDED = 'DESCARTADA';
    const DUPLICATE = 'DUPLICADA';

    const STATUSES = [
        self::PENDING   => 'Pendiente',
        self::ASSIGNED  => 'Asignada',
        self::REVIEW    => 'En revisión',
        self::DISCARDED => 'Descartada',
        self::DUPLICATE => 'Duplicada',
    ];

    protected $table = 'bank_statement_lines';

    public $timestamps = false;

    protected $fillable = [
        'statement_id', 'line_number', 'transaction_date', 'description', 'reference', 'payer_document', 'amount',
        'status', 'assignment_mode', 'matched_obligation_id', 'score', 'candidates', 'payment_id',
        'reviewed_by', 'reviewed_at', 'review_notes',
    ];

    protected function casts(): array
    {
        return [
            'transaction_date' => 'date:Y-m-d',
            'amount'           => 'decimal:2',
            'candidates'       => 'array',
            'reviewed_at'      => 'datetime:Y-m-d H:i',
        ];
    }

    public function statement(): BelongsTo
    {
        return $this->belongsTo(BankStatement::class, 'statement_id');
    }

    public function obligation(): BelongsTo
    {
        return $this->belongsTo(Obligation::class, 'matched_obligation_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by')->withTrashed();
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
