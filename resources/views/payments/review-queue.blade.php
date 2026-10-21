{{-- Cola de revisión: movimientos que el motor no pudo asignar automáticamente (Sprint 6). --}}
@extends('layouts.panel')

@section('title', 'Cola de revisión')

@section('content')
    @php($money = fn ($v) => '$ '.number_format((float) $v, 0, ',', '.'))

    <div class="page-head">
        <div class="page-title">
            <span class="page-icon"><i class="ph ph-list-checks"></i></span>
            <div>
                <h1>Cola de revisión</h1>
                <p>Consignaciones sin un candidato claro. Asígnalas a la obligación correcta o descártalas si no corresponden a cartera.</p>
            </div>
        </div>
        <a href="{{ route('statements.index') }}" class="btn btn-ghost"><i class="ph ph-bank"></i> Extractos</a>
    </div>

    <section class="card">
        @if ($lines->isEmpty())
            <div class="empty"><i class="ph ph-check-circle"></i>No hay movimientos pendientes de revisión.</div>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Fecha</th><th>Banco</th><th>Descripción</th><th>Referencia</th><th class="num">Valor</th><th>Mejor candidato</th><th class="actions"></th></tr></thead>
                    <tbody>
                        @foreach ($lines as $line)
                            @php($best = $line->candidates[0] ?? null)
                            <tr>
                                <td class="nowrap">{{ $line->transaction_date->format('Y-m-d') }}</td>
                                <td class="cell-muted">{{ $line->statement->bank_name }} #{{ $line->statement_id }}</td>
                                <td>{{ $line->description ?? '—' }}</td>
                                <td><code>{{ $line->reference ?? '—' }}</code></td>
                                <td class="num nowrap strong">{{ $money($line->amount) }}</td>
                                <td>@if ($best)<span class="badge badge-{{ $best['score'] >= 50 ? 'warning' : 'neutral' }}">{{ $best['score'] }}</span>
                                    <span style="font-size:.85rem">{{ \Illuminate\Support\Str::limit($best['label'], 60) }}</span>@else<span class="cell-muted">Sin candidatos</span>@endif</td>
                                <td class="actions"><a href="{{ route('allocation.review', $line->id) }}" class="btn btn-primary btn-sm">Revisar</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="pager">{{ $lines->links('pagination::simple-default') }}</div>
        @endif
    </section>
@endsection
