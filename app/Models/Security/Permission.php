<?php

namespace App\Models\Security;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Permiso con código "modulo.accion" (p. ej. lotes.editar). El prefijo antes
 * del primer punto se usa para agrupar los permisos en la interfaz.
 */
class Permission extends Model
{
    protected $table = 'permissions';

    public $timestamps = false;

    protected $fillable = ['code', 'description', 'is_active'];

    protected $appends = ['module'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'permission_role');
    }

    public function getModuleAttribute(): string
    {
        return explode('.', $this->code)[0];
    }
}
