<?php

namespace App\Models\Security;

use App\Models\Commercial\Client;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;

/**
 * Cuenta de acceso (tabla users). Los datos personales viven en people (Person).
 * La contraseña se guarda en password_hash (bcrypt, RNF-01).
 */
class User extends Authenticatable
{
    use SoftDeletes;

    protected $table = 'users';

    protected $fillable = [
        'person_id', 'username', 'password_hash', 'hash_algorithm',
        'is_active', 'must_change_password', 'password_changed_at',
        'failed_attempts', 'locked_until', 'last_login_at',
    ];

    protected $hidden = ['password_hash'];

    protected function casts(): array
    {
        return [
            'is_active'            => 'boolean',
            'must_change_password' => 'boolean',
            'password_changed_at'  => 'datetime',
            'locked_until'         => 'datetime',
            'last_login_at'        => 'datetime',
        ];
    }

    /** Permisos efectivos cacheados durante la petición. */
    private ?Collection $permissionCodes = null;

    // ── Autenticación sobre el esquema propio ──────────────────────────────
    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    /** La tabla no tiene remember_token: se deshabilita "recordarme". */
    public function getRememberTokenName(): ?string
    {
        return null;
    }

    // ── Relaciones ─────────────────────────────────────────────────────────
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_user')->withPivot('assigned_at');
    }

    public function client(): ?Client
    {
        return Client::where('person_id', $this->person_id)->first();
    }

    // ── Permisos ───────────────────────────────────────────────────────────
    /** Códigos de permiso activos que el usuario recibe a través de sus roles. */
    public function permissionCodes(): Collection
    {
        return $this->permissionCodes ??= $this->roles()
            ->with(['permissions' => fn ($q) => $q->where('is_active', true)])
            ->get()
            ->flatMap(fn (Role $role) => $role->permissions->pluck('code'))
            ->unique()
            ->values();
    }

    public function isSuperUser(): bool
    {
        return $this->permissionCodes()->contains(config('lothub.super_permission'));
    }

    public function hasPermission(string $code): bool
    {
        return $this->isSuperUser() || $this->permissionCodes()->contains($code);
    }

    public function hasAnyPermission(array $codes): bool
    {
        return $this->isSuperUser() || $this->permissionCodes()->intersect($codes)->isNotEmpty();
    }

    // ── Presentación ───────────────────────────────────────────────────────
    public function getDisplayNameAttribute(): string
    {
        return $this->person?->full_name ?: $this->username;
    }

    public function getInitialsAttribute(): string
    {
        $p = $this->person;

        return mb_strtoupper(mb_substr($p?->first_name ?? $this->username, 0, 1).mb_substr($p?->last_name ?? '', 0, 1));
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    /** Guarda una contraseña definitiva elegida por el propio usuario (US-AUTH-004). */
    public function setOwnPassword(string $plain): void
    {
        $this->forceFill([
            'password_hash'        => Hash::make($plain),
            'hash_algorithm'       => 'bcrypt',
            'must_change_password' => false,
            'password_changed_at'  => now(),
            'failed_attempts'      => 0,
            'locked_until'         => null,
        ])->save();
    }
}
