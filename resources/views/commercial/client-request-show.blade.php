{{-- CU-29 Atender una consulta o reclamo. --}}
@extends('layouts.panel')

@section('title', 'Solicitud #'.$request->id)

@section('breadcrumb')
    <a href="{{ route('client-requests.index') }}">Consultas y reclamos</a>
    <i class="ph ph-caret-right"></i>
    <span class="current">#{{ $request->id }}</span>
@endsection

@section('content')
    @php
        $tone = ['RADICADA' => 'warning', 'EN_TRAMITE' => 'info', 'RESPONDIDA' => 'success', 'CERRADA' => 'neutral'][$request->status];
        $canAttend = auth()->user()->hasPermission('reclamos.atender');
    @endphp

    <div class="page-head">
        <div class="page-title">
            <span class="page-icon"><i class="ph ph-chat-centered-text"></i></span>
            <div>
                <h1>{{ $request->subject }} <span class="badge badge-{{ $tone }}" style="vertical-align:middle">{{ $request->status_label }}</span></h1>
                <p>{{ $request->type_label }} de {{ $request->client->person->full_name }} ({{ $request->client->client_code }}) · radicada el {{ $request->filed_at->format('Y-m-d H:i') }}</p>
            </div>
        </div>
        <a href="{{ route('client-requests.index') }}" class="btn btn-ghost"><i class="ph ph-arrow-left"></i> Volver</a>
    </div>

    @if ($request->isOverdue())
        <div class="alert alert-error"><i class="ph ph-warning-circle"></i><div>El plazo legal venció el {{ $request->due_date->format('Y-m-d') }} (RN-24).</div></div>
    @elseif ($request->isOpen())
        <div class="alert alert-info"><i class="ph ph-clock"></i><div>Debe responderse a más tardar el <strong>{{ $request->due_date->format('Y-m-d') }}</strong>
            ({{ \App\Models\Commercial\ClientRequest::businessDaysFor($request->request_type) }} días hábiles, Ley 1581 de 2012).</div></div>
    @endif

    <section class="card">
        <div class="card-head"><h3>Solicitud</h3></div>
        <div class="card-body" style="white-space:pre-line">{{ $request->description }}</div>
    </section>

    @if ($request->response)
        <section class="card">
            <div class="card-head"><h3>Respuesta</h3><span class="muted" style="font-size:.82rem">{{ $request->answeredBy?->display_name }} · {{ $request->answered_at?->format('Y-m-d H:i') }}</span></div>
            <div class="card-body" style="white-space:pre-line">{{ $request->response }}</div>
        </section>
    @endif

    @if ($canAttend)
        @if ($request->isOpen())
            <section class="card form-card">
                <div class="card-head"><h3>Responder</h3>
                    @if ($request->status === 'RADICADA')
                        <form method="POST" action="{{ route('client-requests.process', $request->id) }}">@csrf<button class="btn btn-ghost btn-sm">Marcar en trámite</button></form>
                    @endif
                </div>
                <form method="POST" action="{{ route('client-requests.answer', $request->id) }}" class="card-body">
                    @csrf
                    <div class="field">
                        <label for="response">Respuesta al titular <span class="req">*</span></label>
                        <textarea id="response" name="response" rows="6" class="textarea @error('response') is-invalid @enderror" minlength="20" required>{{ old('response') }}</textarea>
                        @error('response') <div class="error">{{ $message }}</div> @enderror
                        <div class="help">Se envía al correo del titular y queda visible en su portal.</div>
                    </div>
                    <button class="btn btn-primary"><i class="ph ph-paper-plane-tilt"></i> Enviar respuesta</button>
                </form>
            </section>
        @elseif ($request->status === 'RESPONDIDA')
            <form method="POST" action="{{ route('client-requests.close', $request->id) }}">@csrf<button class="btn btn-ghost"><i class="ph ph-check-square"></i> Cerrar solicitud</button></form>
        @endif
    @endif
@endsection
