{{-- Perfil del usuario autenticado (US-AUTH-007). --}}
@extends('layouts.panel')

@section('title', 'Mi perfil')

@section('content')
    @php($p = $user->person)

    <div class="page-head">
        <div class="page-title">
            <span class="page-icon"><i class="ph ph-user-circle"></i></span>
            <div>
                <h1>Mi perfil</h1>
                <p>Tus datos de contacto y la seguridad de tu cuenta.</p>
            </div>
        </div>
    </div>

    <div class="option-grid">
        <section class="card">
            <div class="card-head"><h3>Cuenta</h3></div>
            <div class="card-body">
                <ul class="list">
                    <li><span class="grow muted">Usuario</span><strong>{{ $user->username }}</strong></li>
                    <li><span class="grow muted">Nombre</span><strong>{{ $p->full_name }}</strong></li>
                    <li><span class="grow muted">Identificación</span><span>{{ $p->identificationType?->code }} {{ $p->identification_number }}</span></li>
                    <li><span class="grow muted">Roles</span><span>{{ $user->roles->pluck('name')->map(fn ($r) => str_replace('_', ' ', $r))->implode(', ') ?: '—' }}</span></li>
                    <li><span class="grow muted">Último ingreso</span><span>{{ $user->last_login_at?->format('Y-m-d H:i') ?? '—' }}</span></li>
                    <li><span class="grow muted">Contraseña cambiada</span><span>{{ $user->password_changed_at?->format('Y-m-d H:i') ?? 'Nunca' }}</span></li>
                </ul>
                <a href="{{ route('password.change') }}" class="btn btn-ghost" style="margin-top:1rem"><i class="ph ph-lock-key"></i> Cambiar contraseña</a>
                <p class="muted" style="font-size:.82rem;margin-top:.75rem">El nombre y la identificación los actualiza el administrador o tu asesor.</p>
            </div>
        </section>

        <section class="card">
            <div class="card-head"><h3>Datos de contacto</h3></div>
            <form method="POST" action="{{ route('profile.update') }}" class="card-body" novalidate>
                @csrf
                @method('PUT')
                <div class="form-grid">
                    @include('crud.partials.field', ['field' => ['name' => 'email', 'label' => 'Correo electrónico', 'type' => 'email', 'required' => true,
                        'value' => $p->email, 'help' => 'Se usa para recuperar la contraseña.']])
                    @include('crud.partials.field', ['field' => ['name' => 'phone', 'label' => 'Teléfono', 'width' => 'half', 'value' => $p->phone]])
                    @include('crud.partials.field', ['field' => ['name' => 'city_id', 'label' => 'Municipio', 'type' => 'select', 'width' => 'half',
                        'options' => $cities, 'value' => $p->city_id]])
                    @include('crud.partials.field', ['field' => ['name' => 'address', 'label' => 'Dirección', 'value' => $p->address]])
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary"><i class="ph ph-check"></i> Guardar</button>
                </div>
            </form>
        </section>
    </div>
@endsection
