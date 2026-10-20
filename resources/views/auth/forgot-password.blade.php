@extends('layouts.auth')

@section('title', 'Recuperar contraseña')

@section('content')
    <h1>Recupera tu acceso</h1>
    <p class="lead">Escribe tu usuario o correo y te enviaremos un enlace válido por {{ config('lothub.auth.reset_token_minutes') }} minutos.</p>

    <form method="POST" action="{{ route('password.email') }}" novalidate>
        @csrf
        <div class="field">
            <label for="login">Usuario o correo electrónico</label>
            <div class="input-icon">
                <i class="ph ph-envelope-simple"></i>
                <input id="login" name="login" class="input @error('login') is-invalid @enderror" value="{{ old('login') }}" autofocus required>
            </div>
            @error('login') <div class="error">{{ $message }}</div> @enderror
        </div>

        <button type="submit" class="btn btn-primary btn-lg btn-block"><i class="ph ph-paper-plane-tilt"></i> Enviar enlace</button>
    </form>

    <p class="auth-foot" style="margin-top:1.25rem"><a href="{{ route('login') }}">Volver a ingresar</a></p>
@endsection
