{{-- Cambio de contraseña (ProfileController). Obligatorio cuando la contraseña es temporal (US-AUTH-004). --}}
@extends('layouts.auth')

@section('title', 'Cambiar contraseña')

@section('content')
    @if ($forced)
        <h1>Crea tu contraseña</h1>
        <p class="lead">Ingresaste con una contraseña temporal. Para continuar, reemplázala por una que solo tú conozcas.</p>
    @else
        <h1>Cambiar contraseña</h1>
        <p class="lead">Mínimo 8 caracteres, con letras y números.</p>
    @endif

    <form method="POST" action="{{ route('password.change.update') }}" novalidate>
        @csrf
        @method('PUT')

        <div class="field">
            <label for="current_password">{{ $forced ? 'Contraseña temporal' : 'Contraseña actual' }}</label>
            <input id="current_password" type="password" name="current_password" class="input @error('current_password') is-invalid @enderror"
                   autocomplete="current-password" autofocus required>
            @error('current_password') <div class="error">{{ $message }}</div> @enderror
        </div>
        <div class="field">
            <label for="password">Nueva contraseña</label>
            <input id="password" type="password" name="password" class="input @error('password') is-invalid @enderror" autocomplete="new-password" required>
            @error('password') <div class="error">{{ $message }}</div> @enderror
        </div>
        <div class="field">
            <label for="password_confirmation">Confirmar nueva contraseña</label>
            <input id="password_confirmation" type="password" name="password_confirmation" class="input" autocomplete="new-password" required>
        </div>

        <button type="submit" class="btn btn-primary btn-lg btn-block"><i class="ph ph-check"></i> Guardar contraseña</button>
    </form>

    @if ($forced)
        <form method="POST" action="{{ route('logout') }}" style="margin-top:1rem;text-align:center">
            @csrf
            <button type="submit" class="btn btn-ghost btn-sm"><i class="ph ph-sign-out"></i> Cerrar sesión</button>
        </form>
    @else
        <p style="margin-top:1rem;text-align:center"><a href="{{ route('profile.show') }}"><i class="ph ph-arrow-left"></i> Volver a mi perfil</a></p>
    @endif
@endsection
