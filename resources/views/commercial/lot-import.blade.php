{{-- Carga masiva de lotes (LotImportController, US-LOT-004). --}}
@extends('layouts.panel')

@section('title', 'Carga masiva de lotes')

@section('breadcrumb')
    <a href="{{ route('lots.index') }}">Lotes</a>
    <i class="ph ph-caret-right"></i>
    <span class="current">Carga masiva</span>
@endsection

@section('content')
    <div class="page-head">
        <div class="page-title">
            <span class="page-icon"><i class="ph ph-upload-simple"></i></span>
            <div>
                <h1>Carga masiva de lotes</h1>
                <p>Registra muchos lotes a la vez desde una plantilla de Excel. Si el proyecto tiene plano, también puedes crearlos con «Sincronizar lotes».</p>
            </div>
        </div>
        <a href="{{ route('lots.index') }}" class="btn btn-ghost"><i class="ph ph-arrow-left"></i> Lotes</a>
    </div>

    @if ($errors->any())
        <div class="alert alert-error"><i class="ph ph-warning-circle"></i><div>{{ $errors->first() }}</div></div>
    @endif

    <section class="card form-card">
        <div class="card-body">
            <ol class="steps">
                <li><div><strong>Elige el proyecto y descarga su plantilla.</strong> Trae las etapas válidas y una fila de ejemplo.</div></li>
                <li><div><strong>Llena una fila por lote:</strong> manzana, número, etapa (opcional), área y precio de lista. Máximo {{ number_format($maxRows, 0, ',', '.') }} lotes por archivo.</div></li>
                <li><div><strong>Cárgala aquí.</strong> Si alguna fila tiene un error no se crea ningún lote y te mostramos qué corregir. Los lotes nuevos quedan DISPONIBLES.</div></li>
            </ol>

            <form method="POST" action="{{ route('lots.import.store') }}" enctype="multipart/form-data" class="form-grid" id="import-form">
                @csrf
                <div class="field w-full">
                    <label for="project_id">Proyecto <span class="req">*</span></label>
                    <select id="project_id" name="project_id" class="select" required>
                        <option value="">— Seleccionar —</option>
                        @foreach ($projects as $project)
                            <option value="{{ $project->id }}" @selected(old('project_id', $selected) == $project->id)>{{ $project->name }} ({{ $project->code }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="field w-full">
                    <a href="#" id="template-link" class="btn btn-ghost btn-sm" data-base="{{ route('lots.import.template') }}"><i class="ph ph-microsoft-excel-logo"></i> Descargar plantilla del proyecto</a>
                </div>
                <div class="field w-full">
                    <label for="lots_file">Plantilla diligenciada (.xlsx) <span class="req">*</span></label>
                    <input type="file" id="lots_file" name="lots_file" class="input" style="padding:.55rem .7rem;height:auto" accept=".xlsx" required>
                </div>
                <div class="form-actions w-full">
                    <button class="btn btn-primary" data-loading="Cargando lotes…"><i class="ph ph-upload-simple"></i> Cargar lotes</button>
                </div>
            </form>
        </div>
    </section>

    @if ($result)
        <section class="card">
            <div class="card-head">
                <h3>Resultado de la última carga</h3>
                <span class="badge badge-{{ $result['errors'] ? 'danger' : 'success' }}">{{ $result['errors'] ? count($result['errors']).' error(es)' : 'Aplicada' }}</span>
            </div>
            <div class="card-body">
                <div class="plan-report" style="margin-bottom:{{ $result['errors'] ? '1rem' : '0' }}">
                    <div><strong>{{ $result['created'] }}</strong><span>lotes creados</span></div>
                    <div><strong>{{ $result['skipped'] }}</strong><span>filas de ejemplo omitidas</span></div>
                </div>
                @if ($result['errors'])
                    <div class="table-wrap price-errors">
                        <table class="table">
                            <thead><tr><th>Fila</th><th>Lote</th><th>Qué corregir</th></tr></thead>
                            <tbody>
                                @foreach ($result['errors'] as $err)
                                    <tr><td class="num">{{ $err['row'] }}</td><td><code>{{ $err['code'] ?: '—' }}</code></td><td>{{ $err['message'] }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </section>
    @endif
@endsection

@push('scripts')
<script>
    (() => {
        const select = document.getElementById('project_id');
        const link = document.getElementById('template-link');
        link.addEventListener('click', (e) => {
            e.preventDefault();
            if (!select.value) { LotHub.toast('Elige primero el proyecto.', 'error'); select.focus(); return; }
            window.location = `${link.dataset.base}?project_id=${encodeURIComponent(select.value)}`;
        });
    })();
</script>
@endpush
