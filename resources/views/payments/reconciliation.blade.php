{{-- CU-22 Conciliación de la pasarela por periodo. --}}
@extends('layouts.panel')

@section('title', 'Conciliación de la pasarela')

@section('content')
    @php $money = fn ($v) => '$ '.number_format((float) $v, 0, ',', '.'); @endphp

    <div class="page-head">
        <div class="page-title">
            <span class="page-icon"><i class="ph ph-scales"></i></span>
            <div>
                <h1>Conciliación de la pasarela</h1>
                <p>Compara las transacciones de Wompi con los abonos registrados y resuelve cada diferencia.</p>
            </div>
        </div>
        <form method="GET" class="page-actions" style="gap:.4rem">
            <input type="date" name="desde" class="input" value="{{ $from->toDateString() }}" aria-label="Desde" style="width:auto">
            <input type="date" name="hasta" class="input" value="{{ $to->toDateString() }}" aria-label="Hasta" style="width:auto">
            <button class="btn btn-ghost"><i class="ph ph-funnel"></i> Ver periodo</button>
        </form>
    </div>

    <div class="stats">
        <div class="card stat"><div class="stat-icon accent"><i class="ph ph-check-circle"></i></div>
            <div class="stat-value">{{ $totals['approved'] }}</div><div class="stat-label">aprobadas · {{ $money($totals['approved_amount']) }}</div></div>
        <div class="card stat"><div class="stat-icon warn"><i class="ph ph-x-circle"></i></div>
            <div class="stat-value">{{ $totals['rejected'] }}</div><div class="stat-label">rechazadas o fallidas</div></div>
        <div class="card stat"><div class="stat-icon info"><i class="ph ph-hourglass"></i></div>
            <div class="stat-value">{{ $totals['pending'] }}</div><div class="stat-label">pendientes</div></div>
        <div class="card stat"><div class="stat-icon"><i class="ph ph-clock-countdown"></i></div>
            <div class="stat-value">{{ $totals['expired'] }}</div><div class="stat-label">expiradas</div></div>
    </div>

    @if ($clean)
        <div class="card empty"><i class="ph ph-seal-check"></i>Conciliación completa: no hay diferencias entre {{ $from->toDateString() }} y {{ $to->toDateString() }} (CU-22 2a).</div>
    @endif

    @php
        $reviewForm = fn (string $type, int $id) => '<form method="POST" action="'.route('reconciliation.review', [$type, $id]).'" style="display:flex;gap:.3rem">'
            .csrf_field().'<input name="notes" class="input" style="height:32px;width:170px" placeholder="Observación" minlength="5" required>'
            .'<button class="btn btn-ghost btn-sm">Revisada</button></form>';
    @endphp

    @if ($report['approved_without_payment']->isNotEmpty())
        <section class="card">
            <div class="card-head"><h3>Transacciones aprobadas sin abono</h3><span class="badge badge-danger">{{ $report['approved_without_payment']->count() }}</span></div>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Fecha</th><th>Referencia</th><th>Obligación</th><th>Cliente</th><th class="num">Solicitado</th><th class="num">Informado</th><th>Nota</th><th class="actions"></th></tr></thead>
                    <tbody>
                        @foreach ($report['approved_without_payment'] as $tx)
                            <tr>
                                <td class="nowrap">{{ $tx->requested_at->format('Y-m-d H:i') }}</td>
                                <td><code>{{ $tx->internal_reference }}</code><div class="cell-muted" style="font-size:.78rem">{{ $tx->external_reference }}</div></td>
                                <td>{{ $tx->obligation->obligation_code }}</td>
                                <td>{{ $tx->client->person->full_name }}</td>
                                <td class="num nowrap">{{ $money($tx->amount) }}</td>
                                <td class="num nowrap {{ $tx->hasAmountMismatch() ? 'strong' : '' }}" @if ($tx->hasAmountMismatch()) style="color:var(--danger)" @endif>{{ $tx->reported_amount !== null ? $money($tx->reported_amount) : '—' }}</td>
                                <td class="cell-muted" style="font-size:.8rem">{{ $tx->hasAmountMismatch() ? 'Diferencia de valor' : ($tx->review_notes ?? '') }}</td>
                                <td class="actions" style="display:flex;gap:.3rem;flex-wrap:wrap;justify-content:flex-end">
                                    <form method="POST" action="{{ route('reconciliation.reprocess', $tx->id) }}">@csrf<button class="btn btn-ghost btn-sm"><i class="ph ph-arrows-clockwise"></i> Reprocesar</button></form>
                                    @if ($tx->hasAmountMismatch())
                                        <form method="POST" action="{{ route('reconciliation.apply-reported', $tx->id) }}">@csrf<button class="btn btn-primary btn-sm">Aplicar valor informado</button></form>
                                    @endif
                                    {!! $reviewForm('transaccion', $tx->id) !!}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    @if ($report['payments_without_approval']->isNotEmpty())
        <section class="card">
            <div class="card-head"><h3>Abonos de pasarela sin transacción aprobada</h3><span class="badge badge-danger">{{ $report['payments_without_approval']->count() }}</span></div>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Fecha</th><th>Abono</th><th>Obligación</th><th>Pagador</th><th class="num">Valor</th><th>Situación</th><th class="actions"></th></tr></thead>
                    <tbody>
                        @foreach ($report['payments_without_approval'] as $p)
                            <tr>
                                <td class="nowrap">{{ $p->payment_date->format('Y-m-d') }}</td>
                                <td><a href="{{ route('payments.show', $p->id) }}">#{{ $p->id }}</a></td>
                                <td>{{ $p->obligation->obligation_code }}</td>
                                <td>{{ $p->payer->person->full_name }}</td>
                                <td class="num nowrap">{{ $money($p->amount) }}</td>
                                <td class="cell-muted" style="font-size:.82rem">{{ $p->gatewayTransaction?->review_notes ?? 'La transacción no está aprobada' }}</td>
                                <td class="actions" style="display:flex;gap:.3rem;justify-content:flex-end">
                                    <a href="{{ route('payments.show', $p->id) }}" class="btn btn-danger btn-sm">Reversar</a>
                                    @if ($p->gateway_transaction_id) {!! $reviewForm('transaccion', $p->gateway_transaction_id) !!} @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    @if ($report['stale_pending']->isNotEmpty())
        <section class="card">
            <div class="card-head"><h3>Pendientes fuera de tiempo</h3><span class="badge badge-warning">{{ $report['stale_pending']->count() }}</span></div>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Fecha</th><th>Referencia</th><th>Obligación</th><th>Cliente</th><th class="num">Valor</th><th class="actions"></th></tr></thead>
                    <tbody>
                        @foreach ($report['stale_pending'] as $tx)
                            <tr>
                                <td class="nowrap">{{ $tx->requested_at->format('Y-m-d H:i') }}</td>
                                <td><code>{{ $tx->internal_reference }}</code></td>
                                <td>{{ $tx->obligation->obligation_code }}</td>
                                <td>{{ $tx->client->person->full_name }}</td>
                                <td class="num nowrap">{{ $money($tx->amount) }}</td>
                                <td class="actions" style="display:flex;gap:.3rem;justify-content:flex-end">
                                    <form method="POST" action="{{ route('reconciliation.reprocess', $tx->id) }}">@csrf<button class="btn btn-ghost btn-sm"><i class="ph ph-magnifying-glass"></i> Consultar en Wompi</button></form>
                                    {!! $reviewForm('transaccion', $tx->id) !!}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    @if ($report['notifications']->isNotEmpty())
        <section class="card">
            <div class="card-head"><h3>Notificaciones sin procesar</h3><span class="badge badge-warning">{{ $report['notifications']->count() }}</span></div>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Recibida</th><th>Evento</th><th>Referencia</th><th>Id Wompi</th><th>Motivo</th><th class="actions"></th></tr></thead>
                    <tbody>
                        @foreach ($report['notifications'] as $n)
                            <tr>
                                <td class="nowrap">{{ $n->received_at->format('Y-m-d H:i') }}</td>
                                <td>{{ $n->event_type }}</td>
                                <td><code>{{ data_get($n->payload, 'data.transaction.reference', '—') }}</code></td>
                                <td class="cell-muted">{{ $n->external_reference ?? '—' }}</td>
                                <td><span class="badge badge-{{ $n->signature_valid ? 'warning' : 'danger' }}">{{ $n->signature_valid ? 'Referencia desconocida' : 'Firma inválida' }}</span></td>
                                <td class="actions">{!! $reviewForm('notificacion', $n->id) !!}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
@endsection
