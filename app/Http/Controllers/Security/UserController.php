<?php

namespace App\Http\Controllers\Security;

use App\Http\Controllers\Crud\CrudController;
use App\Models\Catalogs\IdentificationType;
use App\Models\Security\Person;
use App\Models\Security\Role;
use App\Models\Security\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * CU-04 Gestionar usuarios. Un mismo formulario crea/edita la persona (people)
 * y su cuenta (users), y asigna roles. Si ya existe una persona con la misma
 * identificación (p. ej. un cliente) se reutiliza en lugar de duplicarla.
 */
class UserController extends CrudController
{
    const MODEL = User::class;
    const ROUTE = 'users';
    const PERMISSION = 'usuarios';

    protected array $with = ['person', 'roles'];
    protected array $searchable = ['username', 'person.first_name', 'person.last_name', 'person.identification_number', 'person.email'];

    protected function meta(): array
    {
        return [
            'title'    => 'Usuarios',
            'subtitle' => 'Cuentas de acceso del personal y de clientes con portal.',
            'icon'     => 'ph-users-three',
            'singular' => 'usuario',
        ];
    }

    protected function columns(): array
    {
        return [
            ['key' => 'username', 'label' => 'Usuario', 'type' => 'strong'],
            ['key' => 'person.full_name', 'label' => 'Nombre'],
            ['key' => 'person.email', 'label' => 'Correo', 'type' => 'muted'],
            ['key' => 'role_names', 'label' => 'Roles', 'type' => 'muted'],
            ['key' => 'account_status', 'label' => 'Estado', 'type' => 'badge',
             'colors' => ['Activo' => 'success', 'Inactivo' => 'neutral', 'Bloqueado' => 'danger']],
        ];
    }

    protected function filters(): array
    {
        return [
            ['name' => 'is_active', 'label' => 'Estado', 'options' => [1 => 'Activos', 0 => 'Inactivos']],
        ];
    }

    protected function row(Model $user): array
    {
        return $user->toArray() + [
            'role_names'     => $user->roles->pluck('name')->implode(', ') ?: '—',
            'account_status' => $user->isLocked() ? 'Bloqueado' : ($user->is_active ? 'Activo' : 'Inactivo'),
        ];
    }

    protected function recordLabel(Model $user): string
    {
        return $user->username;
    }

    protected function fields(?Model $user): array
    {
        $p = $user?->person;

        return [
            ['type' => 'section', 'label' => 'Datos personales'],
            ['name' => 'identification_type_id', 'label' => 'Tipo de identificación', 'type' => 'select', 'required' => true,
             'width' => 'third', 'options' => IdentificationType::pluck('name', 'id')->all(), 'value' => $p?->identification_type_id],
            ['name' => 'identification_number', 'label' => 'Número de identificación', 'required' => true, 'width' => 'third', 'value' => $p?->identification_number],
            ['name' => 'phone', 'label' => 'Teléfono', 'width' => 'third', 'value' => $p?->phone],
            ['name' => 'first_name', 'label' => 'Nombres', 'required' => true, 'width' => 'half', 'value' => $p?->first_name],
            ['name' => 'last_name', 'label' => 'Apellidos', 'required' => true, 'width' => 'half', 'value' => $p?->last_name],
            ['name' => 'email', 'label' => 'Correo electrónico', 'type' => 'email', 'required' => true, 'value' => $p?->email,
             'help' => 'Se usa para recuperar la contraseña.'],

            ['type' => 'section', 'label' => 'Cuenta de acceso'],
            ['name' => 'username', 'label' => 'Nombre de usuario', 'required' => true, 'width' => 'half'],
            ['name' => 'password', 'label' => 'Contraseña', 'type' => 'password', 'required' => ! $user, 'width' => 'half',
             'help' => $user ? 'Déjala vacía para conservar la actual.' : 'Mínimo 8 caracteres, con letras y números.'],
            ['name' => 'is_active', 'label' => 'Estado', 'type' => 'checkbox', 'checkbox_label' => 'Cuenta activa', 'default' => true],
            ['name' => 'roles', 'label' => 'Roles', 'type' => 'checkboxes', 'options' => Role::orderBy('name')->pluck('name', 'id')->all(),
             'value' => $user?->roles->pluck('id')->all() ?? []],
        ];
    }

    protected function rules(?Model $user): array
    {
        // Al crear se reutiliza la persona con la misma identificación, si existe.
        $personId = $user?->person_id ?? $this->existingPerson()?->id;

        return [
            'identification_type_id' => ['required', 'exists:identification_types,id'],
            'identification_number'  => ['required', 'string', 'max:25'],
            'first_name'             => ['required', 'string', 'max:100'],
            'last_name'              => ['required', 'string', 'max:100'],
            'email'                  => ['required', 'email', 'max:191', Rule::unique('people', 'email')->ignore($personId)],
            'phone'                  => ['nullable', 'string', 'max:20'],
            'username'               => ['required', 'string', 'max:191', 'alpha_dash', Rule::unique('users')->ignore($user)],
            'password'               => [$user ? 'nullable' : 'required', Password::min(8)->letters()->numbers()],
            'is_active'              => ['boolean'],
            'roles'                  => ['nullable', 'array'],
            'roles.*'                => ['exists:roles,id'],
        ];
    }

    protected function save(array $data, ?Model $user): Model
    {
        $person = $user?->person ?? $this->existingPerson() ?? new Person;

        if (! $user && $person->exists && $person->user()->exists()) {
            abort(back()->withInput()->with('error', 'Esa persona ya tiene una cuenta de usuario.'));
        }

        $person->fill(collect($data)->only(['identification_type_id', 'identification_number', 'first_name', 'last_name', 'email', 'phone'])->all())->save();

        $user ??= new User(['hash_algorithm' => 'bcrypt']);
        $user->fill([
            'person_id' => $person->id,
            'username'  => $data['username'],
            'is_active' => $data['is_active'] ?? false,
        ]);

        if (! empty($data['password'])) {
            $user->password_hash = Hash::make($data['password']);
        }
        $user->save();

        $this->syncRoles($user, array_map('intval', $data['roles'] ?? []));

        return $user;
    }

    protected function deleteBlockedReason(Model $user): ?string
    {
        if ($user->is(auth()->user())) {
            return 'No puedes eliminar tu propia cuenta.';
        }

        if ($user->roles->contains('name', 'ADMINISTRADOR')
            && Role::where('name', 'ADMINISTRADOR')->first()->users()->count() <= 1) {
            return 'Es el único administrador del sistema.';
        }

        return null;
    }

    /** Sincroniza roles y deja el cambio en auditoría (RN-27: cambios de rol). */
    private function syncRoles(User $user, array $roleIds): void
    {
        $before = $user->roles()->pluck('roles.id')->sort()->values()->all();
        $user->roles()->sync($roleIds);
        sort($roleIds);

        if ($before !== $roleIds) {
            AuditLogger::log('ASIGNAR_ROLES', 'role_user', $user->id, ['roles' => $before], ['roles' => $roleIds]);
        }
    }

    private function existingPerson(): ?Person
    {
        return Person::where('identification_type_id', request('identification_type_id'))
            ->where('identification_number', request('identification_number'))
            ->first();
    }
}
