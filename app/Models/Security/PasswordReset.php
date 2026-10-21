<?php

namespace App\Models\Security;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Token de un solo uso (se guarda solo su SHA-256). RN-25.
 * purpose: RECUPERACION (olvidé mi contraseña) o ACTIVACION (primer acceso al portal).
 */
class PasswordReset extends Model
{
    const RECOVERY = 'RECUPERACION';
    const ACTIVATION = 'ACTIVACION';

    protected $table = 'password_resets';

    const UPDATED_AT = null;

    protected $fillable = ['user_id', 'purpose', 'token_hash', 'expires_at', 'used_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Crea el token y devuelve el valor en claro (solo viaja en el enlace del correo). */
    public static function issue(User $user, string $purpose, int $minutes): string
    {
        $token = Str::random(64);

        static::create([
            'user_id'    => $user->id,
            'purpose'    => $purpose,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addMinutes($minutes),
        ]);

        return $token;
    }

    public static function findValid(string $token, string $purpose): ?self
    {
        return static::with('user')
            ->where('token_hash', hash('sha256', $token))
            ->where('purpose', $purpose)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first();
    }
}
