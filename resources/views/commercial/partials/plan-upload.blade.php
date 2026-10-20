{{-- Formulario de carga del plano. Variables: $project, $acceptsDwg. --}}
<form method="POST" action="{{ route('projects.plan.store', $project->id) }}" enctype="multipart/form-data" style="display:grid;gap:.75rem;text-align:left">
    @csrf
    <input type="file" name="plan_file" class="input" style="padding:.55rem .7rem;height:auto" accept="{{ $acceptsDwg ? '.dwg,.dxf' : '.dxf' }}" data-max-bytes="{{ \App\Services\Plans\PlanService::maxUploadBytes() }}" required>
    <div class="muted" style="font-size:.8rem">
        {{ $acceptsDwg ? 'Formatos: DWG o DXF' : 'Formato: DXF (en AutoCAD: Guardar como → DXF)' }} · máx. {{ \App\Services\Plans\PlanService::maxUploadMb() }} MB.
        Se procesa en segundo plano: unos segundos para un loteo pequeño, hasta un par de minutos para uno de miles de lotes.
    </div>
    <button class="btn btn-primary" data-loading="Subiendo plano…"><i class="ph ph-upload-simple"></i> Cargar y procesar</button>
</form>
