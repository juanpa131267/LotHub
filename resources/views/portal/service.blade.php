{{-- Portal: avisos, preferencias de contacto y consultas o reclamos (CU-28). --}}
@extends('layouts.panel')

@section('title', 'Atención al cliente')

@section('content')
    @php
        $statusTone = ['RADICADA' => 'warning', 'EN_TRAMITE' => 'info', 'RESPONDIDA' => 'success', 'CERRADA' => 'neutral'];
        $canFile = auth()->user()->hasPermission('portal.reclamos');
    @endphp

    <div class="page-head">
        <div class="page-title">
            <span class="page-icon"><i class="ph ph-lifebuoy"></i></span>
            <div>
                <h1>Atención al cliente</h1>
                <p>Tus avisos, cómo quieres que te contactemos y tus consultas o reclamos.</p>
            </div>
        </div>
        <a href="{{ route('portal.index') }}" class="btn btn-ghost"><i class="ph ph-receipt"></i> Mi estado de cuenta</a>
    </div>

    <div class="option-grid">
        <section class="card">
            <div class="card-head"><h3>Mis avisos</h3></div>
            @if ($notices->isEmpty())
                <div class="card-body muted">No tienes avisos.</div>
            @else
                <ul class="list" style="padding:0 1.25rem">
                    @foreach ($notices as $n)
                        <li style="align-items:flex-start"><i class="ph ph-bell"></i>
                            <span class="grow">{{ $n->message }}<br><span class="cell-muted" style="font-size:.78rem">{{ $n->type_label }} · {{ $n->sent_at?->format('Y-m-d H:i') }}</span></span></li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section class="card">
            <div class="card-head"><h3>¿Cómo quieres que te contactemos?</h3></div>
            <form method="POST" action="{{ route('portal.channels.update') }}" class="card-body">
                @csrf
                @method('PUT')
                <p class="muted" style="font-size:.82rem;margin-bottom:.6rem">Solo te enviaremos recordatorios y avisos de cobro por los canales que autorices (Ley 2300 de 2023).</p>
                @foreach ($labels as $code => $label)
                    @php $row = $current[$code] ?? null; @endphp
                    <div style="display:grid;grid-template-columns:200px 1fr;gap:.6rem;align-items:center;margin-bottom:.4rem">
                        <span>
                            <input type="hidden" name="channels[{{ $code }}][enabled]" value="0">
                            <label class="check"><input type="checkbox" name="channels[{{ $code }}][enabled]" value="1" @checked($row?->isActive())> {{ $label }}</label>
                        </span>
                        <input name="channels[{{ $code }}][value]" class="input" maxlength="191" value="{{ $row?->contact_value ?? $defaults[$code] }}" aria-label="Dato de {{ $label }}">
                    </div>
                @endforeach
                <button class="btn btn-primary btn-sm" style="margin-top:.4rem"><i class="ph ph-check"></i> Guardar preferencias</button>
            </form>
        </section>
    </div>

    @if ($canFile)
        <section class="card form-card">
            <div class="card-head"><h3>Radicar una consulta o reclamo</h3></div>
            <form method="POST" action="{{ route('portal.requests.store') }}" class="card-body" novalidate>
                @csrf
                <div class="form-grid">
                    @include('crud.partials.field', ['field' => ['name' => 'request_type', 'label' => 'Tipo', 'type' => 'select', 'required' => true, 'width' => 'third', 'options' => $types, 'value' => 'CONSULTA']])
                    @include('crud.partials.field', ['field' => ['name' => 'subject', 'label' => 'Asunto', 'required' => true, 'width' => 'two-thirds', 'maxlength' => 200]])
                    @include('crud.partials.field', ['field' => ['name' => 'description', 'label' => 'Descripción', 'type' => 'textarea', 'required' => true, 'rows' => 4,
                        'help' => 'Las consultas se responden en máximo 10 días hábiles y los reclamos en 15 (Ley 1581 de 2012).']])
                </div>
                <div class="form-actions"><button class="btn btn-primary"><i class="ph ph-paper-plane-tilt"></i> Radicar</button></div>
            </form>
        </section>
    @endif

    @if ($requests->isNotEmpty())
        <section class="card">
            <div class="card-head"><h3>Mis solicitudes</h3></div>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>#</th><th>Radicada</th><th>Tipo</th><th>Asunto</th><th>Respuesta a más tardar</th><th>Estado</th></tr></thead>
                    <tbody>
                        @foreach ($requests as $r)
                            <tr>
                                <td>{{ $r->id }}</td>
                                <td class="nowrap">{{ $r->filed_at->format('Y-m-d') }}</td>
                                <td>{{ $r->type_label }}</td>
                                <td>{{ $r->subject }}
                                    @if ($r->response)<details><summary style="cursor:pointer;font-size:.85rem">Ver respuesta</summary><div style="white-space:pre-line;font-size:.88rem">{{ $r->response }}</div></details>@endif</td>
                                <td class="nowrap">{{ $r->due_date->format('Y-m-d') }}</td>
                                <td><span class="badge badge-{{ $statusTone[$r->status] }}">{{ $r->status_label }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
@endsection
