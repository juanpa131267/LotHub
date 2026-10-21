{{-- Acceso del cliente al portal (ClientPortalController, US-CLI-003). --}}
@extends('layouts.panel')

@section('title', 'Portal · '.$client->person->full_name)

@section('breadcrumb')
    <a href="{{ route('clients.index') }}">Clientes</a>
    <i class="ph ph-caret-right"></i>
    <span class="current">Acceso al portal</span>
@endsection

@section('content')
    @php
        $p = $client->person;
        $tone = ['Activo' => 'success', 'Invitado' => 'warning', 'Inactivo' => 'neutral', 'Sin acceso' => 'neutral'][$status];
    @endphp

    <div class="page-head">
        <div class="page-title">
            <span class="page-icon"><i class="ph ph-door-open"></i></span>
            <div>
                <h1>Acceso al portal</h1>
                <span class="editing-chip"><i class="ph ph-user"></i> {{ $client->client_code }} · {{ $p->full_name }}</span>
            </div>
        </div>
        <a href="{{ route('clients.index') }}" class="btn btn-ghost"><i class="ph ph-arrow-left"></i> Clientes</a>
    </div>

    <div class="option-grid">
        <section class="card">
            <div class="card-head"><h3>Estado</h3><span class="badge badge-{{ $tone }}">{{ $status }}</span></div>
            <div class="card-body">
                <ul class="list">
                    <li><span class="grow muted">Correo</span><strong>{{ $p->email ?? 'Sin registrar' }}</strong></li>
                    <li><span class="grow muted">Autorización de datos</span>
                        <span class="badge badge-{{ $consent ? 'success' : 'danger' }}">{{ $consent ? 'Vigente' : 'Sin autorización' }}</span></li>
                    <li><span class="grow muted">Usuario</span><span>{{ $user?->username ?? '—' }}</span></li>
                    <li><span class="grow muted">Último ingreso</span><span>{{ $user?->last_login_at?->format('Y-m-d H:i') ?? '—' }}</span></li>
                </ul>
            </div>
        </section>

        <section class="card">
            <div class="card-head"><h3>Acciones</h3></div>
            <div class="card-body">
                @if (! $consent)
                    <div class="alert alert-error"><i class="ph ph-warning-circle"></i>
                        <div>Sin autorización de tratamiento de datos vigente no se puede crear el acceso (RN-22). Regístrala en la ficha del cliente.</div></div>
                @elseif (! $p->email)
                    <div class="alert alert-error"><i class="ph ph-warning-circle"></i><div>Registra el correo del cliente para enviarle el enlace.</div></div>
                @endif

                <p class="muted" style="font-size:.88rem;margin-bottom:1rem">
                    El cliente recibe un enlace de un solo uso para crear su contraseña; vence en {{ config('lothub.auth.activation_hours') }} horas.
                    Reenviarlo invalida los enlaces anteriores.
                </p>

                <div style="display:flex;gap:.6rem;flex-wrap:wrap">
                    @if ($consent && $p->email && $status !== 'Activo')
                        <form method="POST" action="{{ route('clients.portal.invite', $client->id) }}">
                            @csrf
                            <button class="btn btn-primary"><i class="ph ph-paper-plane-tilt"></i>
                                {{ $status === 'Sin acceso' ? 'Enviar invitación' : ($status === 'Inactivo' ? 'Reactivar y enviar enlace' : 'Reenviar enlace') }}</button>
                        </form>
                    @endif
                    @if (in_array($status, ['Activo', 'Invitado']))
                        <form method="POST" action="{{ route('clients.portal.revoke', $client->id) }}">
                            @csrf
                            <button class="btn btn-danger"><i class="ph ph-prohibit"></i> Desactivar acceso</button>
                        </form>
                    @endif
                </div>
            </div>
        </section>
    </div>
@endsection
