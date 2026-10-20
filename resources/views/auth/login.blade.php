@extends('layouts.auth')

@section('title', 'Ingresar')

@section('content')
    <h1>Bienvenido de nuevo</h1>
    <p class="lead">Ingresa con tu usuario para continuar.</p>

    <form method="POST" action="{{ route('login.submit') }}" novalidate>
        @csrf
        <div class="field">
            <label for="username">Usuario</label>
            <div class="input-icon">
                <i class="ph ph-user"></i>
                <input id="username" name="username" class="input @error('username') is-invalid @enderror"
                       value="{{ old('username') }}" autocomplete="username" autofocus required>
            </div>
            @error('username') <div class="error">{{ $message }}</div> @enderror
        </div>

        <div class="field">
            <label for="password">Contraseña</label>
            <div class="input-icon">
                <i class="ph ph-lock-simple"></i>
                <input id="password" type="password" name="password" class="input @error('password') is-invalid @enderror"
                       autocomplete="current-password" required>
            </div>
            @error('password') <div class="error">{{ $message }}</div> @enderror
        </div>

        <div class="auth-links">
            <span></span>
            <a href="{{ route('password.request') }}">¿Olvidaste tu contraseña?</a>
        </div>

        <button type="submit" class="btn btn-primary btn-lg btn-block"><i class="ph ph-sign-in"></i> Ingresar</button>
    </form>
@endsection
