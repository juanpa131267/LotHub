{{-- CU-23 Tablero de cartera y mora. --}}
@extends('layouts.panel')

@section('title', 'Cartera y mora')

@section('content')
    @php
        $money = fn ($v) => '$ '.number_format((float) $v, 0, ',', '.');
        $maxBucket = max(1, $summary['buckets']->max('balance'));
        $tones = ['AL_DIA' => 'success', '1_A_30' => 'warning', '31_A_60' => 'warning', '61_A_90' => 'danger', 'MAS_DE_90' => 'danger'];
    @endphp

    <div class="page-head">
        <div class="page-title">
            <span class="page-icon"><i class="ph ph-chart-line-up"></i></span>
            <div>
                <h1>Cartera y mora</h1>
                <p>Saldo de las obligaciones activas, distribución por edades de mora y detalle por obligación (CU-23).</p>
            </div>
        </div>
        @can('cartera.exportar')
            <a href="{{ route('portfolio.export', request()->query()) }}" class="btn btn-ghost"><i class="ph ph-microsoft-excel-logo"></i> Exportar</a>
        @endcan
    </div>

    <form method="GET" class="card toolbar" style="margin-bottom:1rem">
        <label class="input-icon search"><i class="ph ph-magnifying-glass"></i>
            <input type="search" name="q" class="input" placeholder="Cliente, identificación u obligación" value="{{ $filters['q'] ?? '' }}" aria-label="Buscar"></label>
        <select name="project_id" class="select" aria-label="Proyecto">
            <option value="">Proyecto: todos</option>
            @foreach ($projects as $id => $name)<option value="{{ $id }}" @selected(($filters['project_id'] ?? '') == $id)>{{ $name }}</option>@endforeach
        </select>
        <select name="bucket" class="select" aria-label="Rango de mora">
            <option value="">Mora: todas</option>
            @foreach ($buckets as $code => [$label])<option value="{{ $code }}" @selected(($filters['bucket'] ?? '') === $code)>{{ $label }}</option>@endforeach
        </select>
        <button class="btn btn-primary"><i class="ph ph-funnel"></i> Filtrar</button>
        @if (array_filter($filters))<a href="{{ route('portfolio.index') }}" class="btn btn-ghost">Limpiar</a>@endif
    </form>

    <div class="stats">
        <div class="card stat"><div class="stat-icon"><i class="ph ph-wallet"></i></div>
            <div class="stat-value" style="font-size:1.25rem">{{ $money($summary['balance']) }}</div><div class="stat-label">saldo de {{ $summary['obligations'] }} obligaciones</div></div>
        <div class="card stat"><div class="stat-icon warn"><i class="ph ph-warning"></i></div>
            <div class="stat-value" style="font-size:1.25rem">{{ $money($summary['overdue_balance']) }}</div><div class="stat-label">saldo de {{ $summary['overdue_count'] }} obligaciones en mora</div></div>
        <div class="card stat"><div class="stat-icon info"><i class="ph ph-percent"></i></div>
            <div class="stat-value">{{ number_format($summary['overdue_ratio'], 1, ',', '.') }} %</div><div class="stat-label">índice de cartera en mora</div></div>
        <div class="card stat"><div class="stat-icon accent"><i class="ph ph-check-circle"></i></div>
            <div class="stat-value" style="font-size:1.25rem">{{ $money($summary['collected']) }}</div><div class="stat-label">recaudado en estas obligaciones</div></div>
    </div>

    <section class="card">
        <div class="card-head"><h3>Edades de mora</h3><span class="muted" style="font-size:.82rem">por la cuota más atrasada de cada obligación</span></div>
        <div class="card-body" style="display:grid;gap:.6rem">
            @foreach ($summary['buckets'] as $b)
                <a href="{{ route('portfolio.index', array_merge($filters, ['bucket' => $b['code']])) }}" class="aging-row" style="display:grid;grid-template-columns:130px 1fr 150px 70px;gap:.8rem;align-items:center;color:inherit">
                    <span>{{ $b['label'] }}</span>
                    <span style="background:var(--line);border-radius:6px;height:12px;overflow:hidden">
                        <span style="display:block;height:100%;width:{{ round(100 * $b['balance'] / $maxBucket) }}%;background:var(--{{ $tones[$b['code']] }})"></span></span>
                    <strong class="num" style="text-align:right">{{ $money($b['balance']) }}</strong>
                    <span class="muted" style="text-align:right">{{ $b['count'] }}</span>
                </a>
            @endforeach
        </div>
    </section>

    <section class="card">
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Obligación</th><th>Titular</th><th>Lote</th><th class="num">Saldo</th><th class="num">Vencido</th><th class="num">Días de mora</th><th>Próximo vencimiento</th><th class="actions"></th></tr></thead>
                <tbody>
                    @forelse ($obligations as $o)
                        <tr>
                            <td><code>{{ $o->obligation_code }}</code></td>
                            <td class="strong">{{ $o->first_name }} {{ $o->last_name }} <div class="cell-muted" style="font-size:.78rem">{{ $o->phone }}</div></td>
                            <td>{{ $o->project_name }} · Mz {{ $o->block }} Lote {{ $o->lot_number }}</td>
                            <td class="num nowrap">{{ $money($o->balance) }}</td>
                            <td class="num nowrap">{{ ($overdue[$o->obligation_id] ?? 0) > 0 ? $money($overdue[$o->obligation_id]) : '—' }}</td>
                            <td class="num"><span class="badge badge-{{ $o->max_days_overdue > 60 ? 'danger' : ($o->max_days_overdue > 0 ? 'warning' : 'success') }}">{{ $o->max_days_overdue }}</span></td>
                            <td class="nowrap">{{ $o->next_due_date ?? '—' }}</td>
                            <td class="actions">@can('acuerdos.ver')<a href="{{ route('obligations.show', $o->obligation_id) }}" class="icon-btn" title="Ver obligación"><i class="ph ph-eye"></i></a>@endcan</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="empty-row">No hay obligaciones activas con esos filtros.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="pager">{{ $obligations->links('pagination::simple-default') }}</div>
    </section>
@endsection
