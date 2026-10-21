@extends('layouts.auth')

@section('title', 'Activar cuenta')

@section('content')
    <h1>Activa tu cuenta</h1>
    <p class="lead">Hola {{ $user->display_name }}. Tu usuario es <strong>{{ $user->username }}</strong>. Crea una contraseña de mínimo 8 caracteres, con letras y números.</p>

    <form method="POST" action="{{ route('account.activate.store') }}" novalidate>
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        <div class="field">
            <label for="password">Contraseña</label>
            <input id="password" type="password" name="password" class="input @error('password') is-invalid @enderror" autocomplete="new-password" autofocus required>
            @error('password') <div class="error">{{ $message }}</div> @enderror
        </div>
        <div class="field">
            <label for="password_confirmation">Confirmar contraseña</label>
            <input id="password_confirmation" type="password" name="password_confirmation" class="input" autocomplete="new-password" required>
        </div>

        <button type="submit" class="btn btn-primary btn-lg btn-block"><i class="ph ph-check"></i> Activar cuenta</button>
    </form>
@endsection
