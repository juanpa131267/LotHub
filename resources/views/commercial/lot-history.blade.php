{{-- Historial de precios de un lote (LotController::history, US-LOT-005). --}}
@extends('layouts.panel')

@section('title', 'Historial de precios · '.$lot->label)

@section('breadcrumb')
    <a href="{{ route('lots.index') }}">Lotes</a>
    <i class="ph ph-caret-right"></i>
    <span class="current">Historial de precios</span>
@endsection

@section('content')
    @php($money = fn ($v) => '$ '.number_format((float) $v, 0, ',', '.'))

    <div class="page-head">
        <div class="page-title">
            <span class="page-icon"><i class="ph ph-clock-counter-clockwise"></i></span>
            <div>
                <h1>Historial de precios</h1>
                <span class="editing-chip"><i class="ph ph-map-pin"></i> {{ $lot->project->name }} · {{ $lot->label }}</span>
            </div>
        </div>
        <a href="{{ route('lots.index') }}" class="btn btn-ghost"><i class="ph ph-arrow-left"></i> Lotes</a>
    </div>

    <div class="stats">
        <div class="card stat"><div class="stat-icon"><i class="ph ph-currency-circle-dollar"></i></div>
            <div class="stat-value" style="font-size:1.25rem">{{ $money($lot->list_price) }}</div><div class="stat-label">precio de lista actual</div></div>
        <div class="card stat"><div class="stat-icon info"><i class="ph ph-ruler"></i></div>
            <div class="stat-value" style="font-size:1.25rem">{{ $lot->area_m2 > 0 ? $money($lot->list_price / $lot->area_m2) : '—' }}</div><div class="stat-label">valor por m² ({{ number_format((float) $lot->area_m2, 2, ',', '.') }} m²)</div></div>
        <div class="card stat"><div class="stat-icon accent"><i class="ph ph-list-numbers"></i></div>
            <div class="stat-value">{{ $history->count() }}</div><div class="stat-label">cambios registrados</div></div>
    </div>

    <section class="card">
        @if ($history->isEmpty())
            <div class="empty"><i class="ph ph-clock"></i>El precio de este lote no ha cambiado desde que se registró.</div>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Fecha</th><th class="num">Precio anterior</th><th class="num">Precio nuevo</th><th class="num">Variación</th><th>Origen</th><th>Usuario</th></tr></thead>
                    <tbody>
                        @foreach ($history as $h)
                            @php($delta = $h->previous_price > 0 ? ($h->new_price - $h->previous_price) / $h->previous_price * 100 : null)
                            <tr>
                                <td class="nowrap">{{ $h->changed_at?->format('Y-m-d H:i') }}</td>
                                <td class="num nowrap">{{ $money($h->previous_price) }}</td>
                                <td class="num nowrap strong">{{ $money($h->new_price) }}</td>
                                <td class="num nowrap"><span class="badge badge-{{ $delta >= 0 ? 'success' : 'warning' }}">{{ $delta === null ? '—' : ($delta >= 0 ? '+' : '').number_format($delta, 1, ',', '.').' %' }}</span></td>
                                <td>{{ $sources[$h->source] ?? $h->source }}</td>
                                <td class="cell-muted">{{ $h->user?->display_name ?? 'Sistema' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endsection
