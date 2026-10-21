{{-- Canales de contacto autorizados por el cliente (Ley 2300 de 2023). --}}
@extends('layouts.panel')

@section('title', 'Canales de contacto · '.$client->person->full_name)

@section('breadcrumb')
    <a href="{{ route('clients.index') }}">Clientes</a>
    <i class="ph ph-caret-right"></i>
    <span class="current">Canales de contacto</span>
@endsection

@section('content')
    <div class="page-head">
        <div class="page-title">
            <span class="page-icon"><i class="ph ph-chats-circle"></i></span>
            <div>
                <h1>Canales de contacto</h1>
                <span class="editing-chip"><i class="ph ph-user"></i> {{ $client->client_code }} · {{ $client->person->full_name }}</span>
            </div>
        </div>
        <a href="{{ route('clients.index') }}" class="btn btn-ghost"><i class="ph ph-arrow-left"></i> Clientes</a>
    </div>

    @if (! $consent)
        <div class="alert alert-error"><i class="ph ph-warning-circle"></i>
            <div>Sin autorización de tratamiento de datos vigente no se puede habilitar ningún canal ni enviar comunicaciones (RN-22).</div></div>
    @endif

    <form method="POST" action="{{ route('clients.channels.update', $client->id) }}" class="card form-card">
        @csrf
        @method('PUT')
        <div class="card-body">
            <p class="muted" style="font-size:.88rem;margin-bottom:1rem">Las comunicaciones de cobro solo se envían por los canales que el titular autorice,
                de lunes a viernes de 7 a. m. a 7 p. m. y sábados de 8 a. m. a 3 p. m., nunca domingos ni festivos, máximo una vez al día
                y por un solo canal en la semana (Ley 2300 de 2023). Al revocar un canal se conserva la fecha de la autorización.</p>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Canal</th><th>Dato de contacto</th><th>Estado</th></tr></thead>
                    <tbody>
                        @foreach ($labels as $code => $label)
                            @php $row = $current[$code] ?? null; $active = $row?->isActive(); @endphp
                            <tr>
                                <td>
                                    <input type="hidden" name="channels[{{ $code }}][enabled]" value="0">
                                    <label class="check"><input type="checkbox" name="channels[{{ $code }}][enabled]" value="1" @checked(old("channels.$code.enabled", $active)) @disabled(! $consent)> {{ $label }}</label>
                                </td>
                                <td><input name="channels[{{ $code }}][value]" class="input" maxlength="191" value="{{ old("channels.$code.value", $row?->contact_value ?? $defaults[$code]) }}"
                                           placeholder="{{ $code === 'EMAIL' ? 'correo@ejemplo.com' : ($code === 'PORTAL' ? 'usuario del portal' : '300 000 0000') }}"></td>
                                <td class="cell-muted" style="font-size:.82rem">
                                    @if ($active) <span class="badge badge-success">Autorizado</span> desde {{ $row->authorized_at->format('Y-m-d') }}
                                    @elseif ($row) <span class="badge badge-neutral">Revocado</span> el {{ $row->revoked_at->format('Y-m-d') }}
                                    @else — @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="form-actions">
                <button class="btn btn-primary" @disabled(! $consent)><i class="ph ph-check"></i> Guardar canales</button>
            </div>
        </div>
    </form>
@endsection
