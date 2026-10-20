{{-- Precios de los lotes (ProjectPriceController): valor general por m² o planilla de Excel. --}}
@extends('layouts.panel')

@section('title', 'Precios · '.$project->name)

@section('breadcrumb')
    <a href="{{ route('projects.index') }}">Proyectos</a>
    <i class="ph ph-caret-right"></i>
    <span class="current">Precios · {{ $project->name }}</span>
@endsection

@section('content')
    @php
        $canEdit = auth()->user()->hasPermission('lotes.editar');
        $money = fn ($v) => $v === null ? '—' : '$ '.number_format((float) $v, 0, ',', '.');
        $hasLots = ($summary['total'] ?? 0) > 0;
    @endphp

    <div class="page-head">
        <div class="page-title">
            <span class="page-icon"><i class="ph ph-currency-circle-dollar"></i></span>
            <div>
                <h1>{{ $project->name }}</h1>
                <p>{{ $project->code }} · Precio de lista de cada lote. Usa un valor general por m² o asigna cada precio en una planilla de Excel (p. ej. los lotes junto al lago valen más que los del centro).</p>
            </div>
        </div>
        <a href="{{ route('projects.index') }}" class="btn btn-ghost"><i class="ph ph-arrow-left"></i> Proyectos</a>
    </div>

    @include('commercial.partials.project-tabs', ['active' => 'prices'])

    @if ($errors->any())
        <div class="alert alert-error"><i class="ph ph-warning-circle"></i><div>{{ $errors->first() }}</div></div>
    @endif

    @if (! $hasLots)
        <div class="card card-body" style="text-align:center;padding:3rem 1.5rem">
            <i class="ph ph-grid-four" style="font-size:3rem;color:var(--brand-200)"></i>
            <h3 style="margin-top:.75rem">El proyecto aún no tiene lotes</h3>
            <p class="muted" style="margin:.4rem auto 1.25rem;max-width:56ch">Carga el plano y usa «Sincronizar lotes» para crearlos a partir del boceto; después podrás asignarles precio aquí.</p>
            <a href="{{ route('projects.plan', $project->id) }}" class="btn btn-primary"><i class="ph ph-map-trifold"></i> Ir al plano</a>
        </div>
    @else
        <div class="stats">
            <div class="card stat"><div class="stat-icon"><i class="ph ph-grid-four"></i></div>
                <div class="stat-value">{{ number_format($summary['total'], 0, ',', '.') }}</div><div class="stat-label">lotes ({{ number_format($summary['available'], 0, ',', '.') }} disponibles)</div></div>
            <div class="card stat"><div class="stat-icon accent"><i class="ph ph-arrows-down-up"></i></div>
                <div class="stat-value" style="font-size:1.25rem">{{ $money($summary['min_price']) }}</div><div class="stat-label">a {{ $money($summary['max_price']) }}</div></div>
            <div class="card stat"><div class="stat-icon info"><i class="ph ph-chart-bar"></i></div>
                <div class="stat-value" style="font-size:1.25rem">{{ $money($summary['avg_price']) }}</div><div class="stat-label">precio promedio</div></div>
            <div class="card stat"><div class="stat-icon"><i class="ph ph-ruler"></i></div>
                <div class="stat-value" style="font-size:1.25rem">{{ $money($summary['avg_m2']) }}</div><div class="stat-label">valor promedio por m²</div></div>
        </div>

        <div class="option-grid">
            <section class="card option-card">
                <div class="card-head"><span class="option-num">1</span><div><h3>Automático: precio general por m²</h3>
                    <p class="muted" style="font-size:.82rem">Precio = área del lote × valor por m², redondeado al millar.</p></div></div>
                @if ($canEdit)
                    <form method="POST" action="{{ route('projects.prices.perM2', $project->id) }}" class="card-body" id="m2-form">
                        @csrf
                        <div class="field">
                            <label for="price_per_m2">Valor por m² (COP) <span class="req">*</span></label>
                            <input id="price_per_m2" name="price_per_m2" type="number" min="1" step="1" class="input" required
                                   value="{{ old('price_per_m2', $summary['avg_m2'] ? round($summary['avg_m2'], -3) : '') }}">
                        </div>
                        <div class="field">
                            <label for="scope">Aplicar a</label>
                            <select id="scope" name="scope" class="select">
                                @foreach ($scopes as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                            </select>
                            <div class="help">Los lotes vendidos nunca cambian: su valor real está en la negociación.</div>
                        </div>
                        <button class="btn btn-primary"><i class="ph ph-calculator"></i> Aplicar precio por m²</button>
                    </form>
                @else
                    <div class="card-body muted">Necesitas el permiso <code>lotes.editar</code> para cambiar precios.</div>
                @endif
            </section>

            <section class="card option-card">
                <div class="card-head"><span class="option-num">2</span><div><h3>Planilla de Excel, lote por lote</h3>
                    <p class="muted" style="font-size:.82rem">Para precios distintos según la ubicación de cada lote en el plano.</p></div></div>
                <div class="card-body">
                    <ol class="steps">
                        <li><div><strong>Descarga la planilla</strong> con los {{ number_format($summary['total'], 0, ',', '.') }} lotes del proyecto (código MANZANA-LOTE, área, etapa y precio actual).<br>
                            <a href="{{ route('projects.prices.template', $project->id) }}" class="btn btn-ghost btn-sm" style="margin-top:.5rem"><i class="ph ph-microsoft-excel-logo"></i> Descargar planilla .xlsx</a></div></li>
                        <li><div><strong>Llena la columna «Precio nuevo»</strong> (resaltada en verde). Usa el <a href="{{ route('projects.plan', $project->id) }}">plano</a> para ubicar cada lote; las celdas vacías no cambian.</div></li>
                        <li><div><strong>Cárgala aquí.</strong> Si alguna fila tiene un error no se aplica ningún cambio y te mostramos qué corregir.</div></li>
                    </ol>
                    @if ($canEdit)
                        <form method="POST" action="{{ route('projects.prices.import', $project->id) }}" enctype="multipart/form-data" style="display:grid;gap:.6rem">
                            @csrf
                            <input type="file" name="prices_file" class="input" style="padding:.55rem .7rem;height:auto" accept=".xlsx" required>
                            <button class="btn btn-primary" data-loading="Aplicando precios…"><i class="ph ph-upload-simple"></i> Cargar planilla</button>
                        </form>
                    @endif
                </div>
            </section>
        </div>

        @if ($importResult)
            <section class="card">
                <div class="card-head">
                    <h3>Resultado de la última planilla</h3>
                    <span class="badge badge-{{ $importResult['errors'] ? 'danger' : 'success' }}">{{ $importResult['errors'] ? count($importResult['errors']).' error(es)' : 'Aplicada' }}</span>
                </div>
                <div class="card-body">
                    <div class="plan-report" style="margin-bottom:{{ $importResult['errors'] ? '1rem' : '0' }}">
                        <div><strong>{{ $importResult['updated'] }}</strong><span>precios actualizados</span></div>
                        <div><strong>{{ $importResult['unchanged'] }}</strong><span>sin cambio</span></div>
                        <div><strong>{{ $importResult['blank'] }}</strong><span>en blanco (no cambian)</span></div>
                        <div><strong>{{ $importResult['sold'] }}</strong><span>vendidos (no cambian)</span></div>
                    </div>
                    @if ($importResult['errors'])
                        <div class="table-wrap price-errors">
                            <table class="table">
                                <thead><tr><th>Fila</th><th>Código</th><th>Qué corregir</th></tr></thead>
                                <tbody>
                                    @foreach ($importResult['errors'] as $err)
                                        <tr><td class="num">{{ $err['row'] }}</td><td><code>{{ $err['code'] ?: '—' }}</code></td><td>{{ $err['message'] }}</td></tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </section>
        @endif
    @endif
@endsection

@push('scripts')
<script>
    document.getElementById('m2-form')?.addEventListener('submit', async (e) => {
        if (e.target.dataset.confirmed) return;
        e.preventDefault();
        const scope = e.target.scope.selectedOptions[0].textContent.toLowerCase();
        const value = Number(e.target.price_per_m2.value).toLocaleString('es-CO');
        const ok = await LotHub.confirm({
            title: 'Aplicar precio por m²',
            message: `Se recalculará el precio de ${scope} con $${value} por m². Los precios asignados uno a uno se reemplazarán.`,
            confirmText: 'Aplicar',
        });
        if (ok) { e.target.dataset.confirmed = '1'; e.target.submit(); }
    });
</script>
@endpush
