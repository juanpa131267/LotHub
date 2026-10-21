{{-- Sprint 10 · Operación en paralelo: migración de la cartera vigente y cortes de comparación. --}}
@extends('layouts.panel')

@section('title', 'Operación en paralelo')

@section('content')
    <div class="page-head">
        <div class="page-title">
            <span class="page-icon"><i class="ph ph-arrows-left-right"></i></span>
            <div>
                <h1>Operación en paralelo</h1>
                <p>Migra la cartera que hoy está en hojas de cálculo y compara cada corte de la hoja con el sistema hasta que los saldos coincidan.</p>
            </div>
        </div>
        <span class="badge badge-info" style="align-self:center">{{ $migrated }} negociaciones migradas</span>
    </div>

    <div class="option-grid">
        <section class="card option-card">
            <div class="card-head"><span class="option-num">1</span><div><h3>Migrar la cartera vigente</h3>
                <p class="muted" style="font-size:.82rem">Una fila por negociación: cliente, lote, acuerdo y lo pagado hasta hoy.</p></div></div>
            <div class="card-body">
                <ol class="steps">
                    <li><div><strong>Descarga la plantilla</strong> y copia la cartera desde la hoja actual.<br>
                        <a href="{{ route('parallel.template') }}" class="btn btn-ghost btn-sm" style="margin-top:.4rem"><i class="ph ph-microsoft-excel-logo"></i> Plantilla de migración</a></div></li>
                    <li><div><strong>Verifica que los lotes existan</strong> en el inventario y estén disponibles (créalos con la carga masiva o el plano).</div></li>
                    <li><div><strong>Carga la plantilla.</strong> Si una fila tiene un error no se migra ninguna y te mostramos qué corregir.</div></li>
                </ol>
                <form method="POST" action="{{ route('parallel.import') }}" enctype="multipart/form-data" style="display:grid;gap:.6rem">
                    @csrf
                    <input type="file" name="migration_file" class="input" style="padding:.55rem .7rem;height:auto" accept=".xlsx" required>
                    <input type="hidden" name="late_from_today" value="0">
                    <label class="check"><input type="checkbox" name="late_from_today" value="1" checked> Liquidar la mora desde hoy (no recalcular la mora anterior a la migración)</label>
                    <button class="btn btn-primary" data-loading="Migrando cartera…"><i class="ph ph-database"></i> Migrar cartera</button>
                </form>
            </div>
        </section>

        <section class="card option-card">
            <div class="card-head"><span class="option-num">2</span><div><h3>Comparar un corte</h3>
                <p class="muted" style="font-size:.82rem">Saldo de cada negociación según la hoja vs. saldo según el sistema.</p></div></div>
            <form method="POST" action="{{ route('parallel.compare') }}" enctype="multipart/form-data" class="card-body" style="display:grid;gap:.6rem">
                @csrf
                <a href="{{ route('parallel.cut-template') }}" class="btn btn-ghost btn-sm" style="justify-self:start"><i class="ph ph-microsoft-excel-logo"></i> Plantilla del corte (con las negociaciones migradas)</a>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.6rem">
                    <label class="field" style="margin:0"><span class="label">Fecha de corte</span>
                        <input type="date" name="cut_off_date" class="input" value="{{ now()->toDateString() }}" max="{{ now()->toDateString() }}" required></label>
                    <label class="field" style="margin:0"><span class="label">Tolerancia (COP)</span>
                        <input type="number" name="tolerance" class="input" value="1000" min="0" step="1" required></label>
                </div>
                <input type="file" name="cut_file" class="input" style="padding:.55rem .7rem;height:auto" accept=".xlsx,.csv" required>
                <p class="muted" style="font-size:.8rem">Carga la hoja con corte del mismo día: el saldo del sistema es el del momento de la comparación.</p>
                <button class="btn btn-primary" data-loading="Comparando…"><i class="ph ph-scales"></i> Comparar corte</button>
            </form>
        </section>
    </div>

    @if ($result && $result['errors'])
        <section class="card">
            <div class="card-head"><h3>Errores de la migración</h3><span class="badge badge-danger">{{ count($result['errors']) }}</span></div>
            <div class="table-wrap price-errors">
                <table class="table">
                    <thead><tr><th>Fila</th><th>Referencia</th><th>Qué corregir</th></tr></thead>
                    <tbody>@foreach ($result['errors'] as $e)<tr><td class="num">{{ $e['row'] }}</td><td><code>{{ $e['reference'] ?: '—' }}</code></td><td>{{ $e['message'] }}</td></tr>@endforeach</tbody>
                </table>
            </div>
        </section>
    @endif

    <section class="card">
        <div class="card-head"><h3>Cortes comparados</h3></div>
        @if ($runs->isEmpty())
            <div class="empty"><i class="ph ph-scales"></i>Aún no hay cortes.</div>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Corte</th><th>Archivo</th><th class="num">Comparadas</th><th class="num">Coinciden</th><th class="num">Diferencias</th><th>Por</th><th class="actions"></th></tr></thead>
                    <tbody>
                        @foreach ($runs as $run)
                            <tr>
                                <td class="nowrap strong">{{ $run->cut_off_date }}</td>
                                <td class="cell-muted">{{ $run->original_name }}</td>
                                <td class="num">{{ $run->compared }}</td>
                                <td class="num"><span class="badge badge-success">{{ $run->matched }}</span></td>
                                <td class="num"><span class="badge badge-{{ $run->differences ? 'danger' : 'success' }}">{{ $run->differences }}</span></td>
                                <td class="cell-muted">{{ $run->username }}</td>
                                <td class="actions"><a href="{{ route('parallel.show', $run->id) }}" class="icon-btn" title="Ver corte"><i class="ph ph-eye"></i></a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    @if ($imports->isNotEmpty())
        <section class="card">
            <div class="card-head"><h3>Migraciones</h3></div>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>#</th><th>Archivo</th><th class="num">Negociaciones</th><th>Mora</th><th>Fecha</th><th>Por</th></tr></thead>
                    <tbody>
                        @foreach ($imports as $i)
                            <tr><td>{{ $i->id }}</td><td>{{ $i->original_name }}</td><td class="num">{{ $i->rows_count }}</td>
                                <td class="cell-muted">{{ $i->late_charges_from_migration ? 'Desde la migración' : 'Desde el vencimiento' }}</td>
                                <td class="nowrap">{{ $i->imported_at }}</td><td class="cell-muted">{{ $i->username }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
@endsection
