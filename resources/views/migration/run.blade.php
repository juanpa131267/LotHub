{{-- Resultado de un corte de la operación en paralelo. --}}
@extends('layouts.panel')

@section('title', 'Corte '.$run->cut_off_date)

@section('breadcrumb')
    <a href="{{ route('parallel.index') }}">Operación en paralelo</a>
    <i class="ph ph-caret-right"></i>
    <span class="current">Corte {{ $run->cut_off_date }}</span>
@endsection

@section('content')
    @php
        $money = fn ($v) => $v === null ? '—' : '$ '.number_format((float) $v, 0, ',', '.');
        $tone = ['COINCIDE' => 'success', 'DIFERENCIA' => 'danger', 'NO_MIGRADA' => 'warning', 'SOLO_SISTEMA' => 'warning'];
    @endphp

    <div class="page-head">
        <div class="page-title">
            <span class="page-icon"><i class="ph ph-scales"></i></span>
            <div>
                <h1>Corte del {{ $run->cut_off_date }}</h1>
                <p>{{ $run->original_name }} · tolerancia {{ $money($run->tolerance) }} · {{ $run->matched }} de {{ $run->compared }} coinciden</p>
            </div>
        </div>
        <div class="page-actions">
            <a href="{{ route('parallel.index') }}" class="btn btn-ghost"><i class="ph ph-arrow-left"></i> Volver</a>
            <a href="{{ route('parallel.export', $run->id) }}" class="btn btn-ghost"><i class="ph ph-microsoft-excel-logo"></i> Exportar</a>
        </div>
    </div>

    @if (! $run->differences)
        <div class="alert alert-success"><i class="ph ph-seal-check"></i><div>Todas las negociaciones coinciden con la hoja en este corte.</div></div>
    @endif

    <section class="card">
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Referencia</th><th>Obligación</th><th>Titular</th><th class="num">Saldo hoja</th><th class="num">Saldo sistema</th><th class="num">Diferencia</th><th>Resultado</th></tr></thead>
                <tbody>
                    @foreach ($rows as $r)
                        <tr>
                            <td><code>{{ $r['reference'] }}</code></td>
                            <td>{{ $r['obligation'] ?? '—' }}</td>
                            <td>{{ $r['holder'] ?? '—' }}</td>
                            <td class="num nowrap">{{ $money($r['legacy']) }}</td>
                            <td class="num nowrap">{{ $money($r['system']) }}</td>
                            <td class="num nowrap {{ $r['status'] === 'DIFERENCIA' ? 'strong' : '' }}">{{ $money($r['difference']) }}</td>
                            <td><span class="badge badge-{{ $tone[$r['status']] }}">{{ $statuses[$r['status']] }}</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endsection
