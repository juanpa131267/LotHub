@extends('layouts.auth')

@section('title', 'Nueva contraseña')

@section('content')
    <h1>Crea una nueva contraseña</h1>
    <p class="lead">Mínimo 8 caracteres, con letras y números. El enlace solo puede usarse una vez.</p>

    <form method="POST" action="{{ route('password.update') }}" novalidate>
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        <div class="field">
            <label for="password">Nueva contraseña</label>
            <input id="password" type="password" name="password" class="input @error('password') is-invalid @enderror" autocomplete="new-password" autofocus required>
            @error('password') <div class="error">{{ $message }}</div> @enderror
        </div>
        <div class="field">
            <label for="password_confirmation">Confirmar contraseña</label>
            <input id="password_confirmation" type="password" name="password_confirmation" class="input" autocomplete="new-password" required>
        </div>

        <button type="submit" class="btn btn-primary btn-lg btn-block"><i class="ph ph-check"></i> Guardar contraseña</button>
    </form>
@endsection
