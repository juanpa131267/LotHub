<?php

namespace App\Models\Commercial;

use App\Models\Security\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * US-LOT-005 Historial de precios. Lo escribe el trigger trg_lot_price_history (parche 003)
 * cada vez que cambia lots.list_price; la aplicación solo informa el origen y el usuario.
 */
class LotPriceHistory extends Model
{
    const FORM = 'FORMULARIO';
    const PER_M2 = 'POR_M2';
    const SPREADSHEET = 'PLANILLA';
    const BULK_LOAD = 'CARGA_MASIVA';

    const SOURCES = [
        self::FORM        => 'Formulario del lote',
        self::PER_M2      => 'Valor general por m²',
        self::SPREADSHEET => 'Planilla de precios',
        self::BULK_LOAD   => 'Carga masiva',
        'SISTEMA'         => 'Sistema',
    ];

    protected $table = 'lot_price_history';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'previous_price' => 'decimal:2',
            'new_price'      => 'decimal:2',
            'changed_at'     => 'datetime',
        ];
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by')->withTrashed();
    }

    /** Ejecuta $callback informando al trigger el origen del cambio y el usuario autenticado. */
    public static function recording(string $source, callable $callback): mixed
    {
        DB::statement('SET @lothub_price_source = ?, @lothub_user_id = ?', [$source, auth()->id()]);

        try {
            return $callback();
        } finally {
            DB::statement('SET @lothub_price_source = NULL, @lothub_user_id = NULL');
        }
    }
}
