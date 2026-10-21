{{-- Revisión interna del plano de un proyecto (ProjectPlanController). --}}
@extends('layouts.panel')

@section('title', 'Plano · '.$project->name)

@section('breadcrumb')
    <a href="{{ route('projects.index') }}">Proyectos</a>
    <i class="ph ph-caret-right"></i>
    <span class="current">Plano · {{ $project->name }}</span>
@endsection

@section('content')
    @php
        $canEdit = auth()->user()->hasPermission('proyectos.editar');
        $report = $plan?->report ?? [];
        $inDb = $planData ? collect($planData['lots'])->filter(fn ($l) => isset($lotStates[$l['code']]))->count() : 0;
        $statusTone = ['LISTO' => 'success', 'PROCESANDO' => 'warning', 'ERROR' => 'danger'];
        $groups = $report['groups'] ?? collect($planData['lots'] ?? [])->countBy(fn ($l) => 'Manzana '.$l['block'])->all();
        // La diferencia media solo es representativa si al menos el 20 % de los lotes trae su área escrita.
        $areaSample = $planData && ($report['mean_area_error_pct'] ?? null) !== null
            && ($report['areas_compared'] ?? $report['lots_detected']) >= 0.2 * $report['lots_detected'];
    @endphp

    <div class="page-head">
        <div class="page-title">
            <span class="page-icon"><i class="ph ph-map-trifold"></i></span>
            <div>
                <h1>{{ $project->name }}</h1>
                <p>{{ $project->code }} · Plano interactivo: carga el plano del loteo, revisa lo que detectó el sistema y publícalo en el sitio.</p>
            </div>
        </div>
        <div class="page-actions">
            @if ($plan?->is_published && $project->status_id == \App\Models\Catalogs\ProjectStatus::EN_VENTA)
                <a href="{{ route('public.project', $project->id) }}" target="_blank" class="btn btn-ghost"><i class="ph ph-globe-hemisphere-west"></i> Ver en el sitio</a>
            @endif
            <a href="{{ route('projects.index') }}" class="btn btn-ghost"><i class="ph ph-arrow-left"></i> Proyectos</a>
        </div>
    </div>

    @include('commercial.partials.project-tabs', ['active' => 'plan'])

    @if ($errors->any())
        <div class="alert alert-error"><i class="ph ph-warning-circle"></i><div>{{ $errors->first() }}</div></div>
    @endif

    @if (! $plan)
        <div class="card card-body" style="text-align:center;padding:3rem 1.5rem">
            <i class="ph ph-map-trifold" style="font-size:3rem;color:var(--brand-200)"></i>
            <h3 style="margin-top:.75rem">Este proyecto aún no tiene plano</h3>
            <p class="muted" style="margin:.4rem auto 1.5rem;max-width:56ch">Carga el plano arquitectónico del loteo. El sistema reconstruye cada lote, sus manzanas, las vías y la orientación, y te muestra el resultado para revisarlo antes de publicarlo.</p>
            @if ($canEdit) @include('commercial.partials.plan-upload') @endif
        </div>
    @else
        <div class="plan-layout">
            <div>
                @if ($planData)
                    <div id="plan" class="review"></div>
                    <p class="plan-hint"><i class="ph ph-hand-pointing"></i> Arrastra para moverte, usa la rueda para acercar. Los lotes con borde rojo punteado tienen un área distinta a la escrita en el plano.</p>
                @elseif ($plan->status === \App\Models\Commercial\ProjectPlan::PROCESSING)
                    <div class="card card-body plan-processing" id="plan-progress" data-url="{{ route('projects.plan.status', [$project->id, $plan->id]) }}">
                        <div class="spinner-ring"><i class="ph ph-map-trifold"></i></div>
                        <h3>Procesando «{{ $plan->original_name }}»</h3>
                        <p class="muted" id="pp-step">Iniciando…</p>
                        <div class="progress" style="margin:1rem 0 .5rem"><span id="pp-bar" style="width:2%"></span></div>
                        <p class="muted" style="font-size:.8rem"><span id="pp-time">0 s</span> · Puedes salir de esta página: el procesamiento continúa y el resultado queda guardado.</p>
                    </div>
                @else
                    <div class="card card-body">
                        <span class="badge badge-{{ $statusTone[$plan->status] }}">{{ $plan->status }}</span>
                        <h3 style="margin-top:.75rem">No se pudo procesar «{{ $plan->original_name }}»</h3>
                        <p class="muted" style="margin-top:.4rem">{{ $plan->error_message }}</p>
                        <p class="muted" style="margin-top:1rem;font-size:.85rem">Revisa la guía de preparación del plano en <code>docs/PLANOS.md</code>: los lotes deben estar rotulados como <code>MANZANA-NÚMERO</code> (p. ej. <code>A-1</code>) dentro de su polígono.</p>
                    </div>
                @endif
            </div>

            <aside style="display:grid;gap:1rem">
                <section class="card">
                    <div class="card-head">
                        <h3>Versión actual</h3>
                        <span class="badge badge-{{ $plan->is_published ? 'success' : $statusTone[$plan->status] }}">{{ $plan->is_published ? 'Publicado' : ucfirst(strtolower($plan->status)) }}</span>
                    </div>
                    <div class="card-body" style="display:grid;gap:1rem">
                        <div class="muted" style="font-size:.85rem"><i class="ph ph-file"></i> {{ $plan->original_name }}<br>
                            Cargado {{ $plan->created_at->diffForHumans() }} por {{ $plan->uploader?->username }}</div>

                        @if ($planData)
                            <div class="plan-report">
                                <div><strong>{{ $report['lots_detected'] }}</strong><span>lotes detectados{{ $report['lots_declared'] ? ' de '.$report['lots_declared'].' declarados' : '' }}</span></div>
                                <div><strong>{{ $inDb }}</strong><span>ya registrados en el sistema</span></div>
                                @if ($areaSample)
                                    <div><strong>{{ $report['mean_area_error_pct'] }}%</strong><span>diferencia media de área ({{ $report['areas_compared'] ?? '' }} lotes con área escrita)</span></div>
                                @else
                                    <div><strong>—</strong><span>el plano no escribe el área de los lotes; se usa la del polígono</span></div>
                                @endif
                                <div><strong>{{ count($groups) }}</strong><span>manzanas{{ ($report['naming'] ?? 'plan') === 'generated' ? ' (generadas: lotes contiguos entre vías)' : '' }}</span></div>
                            </div>
                            @if (! empty($report['stages']))
                                <div class="muted" style="font-size:.84rem">
                                    @foreach ($report['stages'] as $label => $count)<div>{{ $label }}: <strong>{{ number_format($count, 0, ',', '.') }}</strong> lotes</div>@endforeach
                                </div>
                            @endif

                            @if (! empty($report['warnings']) || ! empty($report['area_mismatches']))
                                <div class="alert alert-info" style="margin:0"><i class="ph ph-info"></i><div style="font-size:.84rem">
                                    @foreach ($report['warnings'] as $w)<div>{{ $w }}</div>@endforeach
                                    @if (! empty($report['area_mismatches']))
                                        <div style="margin-top:.35rem"><strong>Áreas a revisar ({{ $report['area_mismatches_total'] ?? count($report['area_mismatches']) }}):</strong>
                                            @foreach ($report['area_mismatches'] as $m)
                                                {{ $m['code'] }} ({{ $m['computed'] }} vs {{ $m['written'] }} m²){{ $loop->last ? '.' : ',' }}
                                            @endforeach
                                        </div>
                                    @endif
                                </div></div>
                            @endif

                            @php $publishedOther = ! $plan->is_published ? $project->plans->firstWhere('is_published', true) : null; @endphp
                            @if ($publishedOther)
                                <div class="alert alert-info" style="margin:0"><i class="ph ph-warning"></i><div style="font-size:.84rem">
                                    El sitio muestra otra versión (cargada {{ $publishedOther->created_at->format('Y-m-d H:i') }}).
                                    Después de sincronizar los lotes, <strong>publica esta versión</strong> para que el plano del sitio use los mismos códigos.
                                </div></div>
                            @endif

                            @if ($canEdit)
                                <div style="display:flex;gap:.5rem;flex-wrap:wrap">
                                    @if ($plan->is_published)
                                        <form method="POST" action="{{ route('projects.plan.unpublish', [$project->id, $plan->id]) }}">@csrf
                                            <button class="btn btn-ghost"><i class="ph ph-eye-slash"></i> Despublicar</button></form>
                                    @else
                                        <form method="POST" action="{{ route('projects.plan.publish', [$project->id, $plan->id]) }}">@csrf
                                            <button class="btn btn-primary"><i class="ph ph-globe-hemisphere-west"></i> Publicar en el sitio</button></form>
                                    @endif
                                    <form method="POST" action="{{ route('projects.plan.reprocess', [$project->id, $plan->id]) }}">@csrf
                                        <button class="btn btn-ghost" title="Volver a procesar con el algoritmo actual"><i class="ph ph-arrows-clockwise"></i> Reprocesar</button></form>
                                </div>
                            @endif
                        @elseif ($canEdit && $plan->status === \App\Models\Commercial\ProjectPlan::FAILED)
                            <form method="POST" action="{{ route('projects.plan.reprocess', [$project->id, $plan->id]) }}">@csrf
                                <button class="btn btn-ghost"><i class="ph ph-arrows-clockwise"></i> Reintentar</button></form>
                        @endif
                    </div>
                </section>

                @if ($planData && $canEdit)
                    <section class="card">
                        <div class="card-head"><h3>Lotes desde el plano</h3></div>
                        <form method="POST" action="{{ route('projects.plan.sync', [$project->id, $plan->id]) }}" class="card-body" style="display:grid;gap:.9rem">
                            @csrf
                            <p class="muted" style="font-size:.84rem">Crea en el inventario los lotes del plano que aún no existen ({{ count($planData['lots']) - $inDb }}), con el área oficial del plano. Los lotes existentes no cambian de precio ni de estado.</p>
                            <div class="field">
                                <label for="price_per_m2">Precio por m² (COP) <span class="req">*</span></label>
                                <input id="price_per_m2" name="price_per_m2" type="number" min="1" step="1" class="input" required
                                       value="{{ old('price_per_m2', $suggestedPrice) }}">
                                @if ($suggestedPrice)<div class="help">Sugerido: promedio actual del proyecto.</div>@endif
                            </div>
                            <div class="field">
                                <label for="stage_id">Etapa</label>
                                <select id="stage_id" name="stage_id" class="select">
                                    <option value="">Sin etapa</option>
                                    @foreach ($project->stages as $stage)<option value="{{ $stage->id }}">{{ $stage->name }}</option>@endforeach
                                </select>
                            </div>
                            <label class="check"><input type="hidden" name="update_areas" value="0"><input type="checkbox" name="update_areas" value="1"> Actualizar también el área de los lotes existentes</label>
                            <button class="btn btn-primary"><i class="ph ph-grid-four"></i> Sincronizar lotes</button>
                        </form>
                    </section>
                @endif

                <section class="card card-body" id="lot-info" hidden>
                    <span class="badge" id="li-status"></span>
                    <h3 style="margin-top:.6rem" id="li-title"></h3>
                    <p class="muted" style="font-size:.85rem;margin-top:.25rem" id="li-meta"></p>
                    <a class="btn btn-ghost btn-sm" id="li-edit" style="margin-top:.75rem" hidden><i class="ph ph-pencil-simple"></i> Editar lote</a>
                </section>

                @if ($canEdit)
                    <section class="card">
                        <div class="card-head"><h3>Cargar nueva versión</h3></div>
                        <div class="card-body">@include('commercial.partials.plan-upload')</div>
                    </section>
                @endif

                @if ($project->plans->count() > 1)
                    <section class="card">
                        <div class="card-head"><h3>Historial</h3></div>
                        <ul class="list">
                            @foreach ($project->plans as $v)
                                <li>
                                    <div class="grow">
                                        <a class="title" href="{{ route('projects.plan', [$project->id, 'version' => $v->id]) }}">{{ $v->original_name }}</a>
                                        <div class="sub">{{ $v->created_at->format('Y-m-d H:i') }} · {{ $v->lots_detected ?? 0 }} lotes</div>
                                    </div>
                                    <span class="badge badge-{{ $v->is_published ? 'success' : $statusTone[$v->status] }}">{{ $v->is_published ? 'Publicado' : ucfirst(strtolower($v->status)) }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif
            </aside>
        </div>
    @endif
@endsection

@if ($planData)
    @push('scripts')
        <script src="{{ asset('js/plan-viewer.js') }}?v={{ filemtime(public_path('js/plan-viewer.js')) }}"></script>
        <script>
            (() => {
                const plan = {!! json_encode($planData, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) !!};
                const lots = {!! json_encode((object) $lotStates, JSON_HEX_TAG) !!};
                const editUrl = @json(route('lots.edit', '__ID__'));
                const canEditLots = @json(auth()->user()->hasPermission('lotes.editar'));
                const canSync = @json($canEdit);
                const money = new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 });
                const tone = { available: 'success', reserved: 'warning', sold: 'info', blocked: 'neutral', unknown: 'neutral' };
                const $ = (id) => document.getElementById(id);

                new PlanViewer($('plan'), {
                    plan, lots,
                    selectable: ['available', 'reserved', 'sold', 'blocked', 'unknown'],
                    onSelect(code, info, geo) {
                        $('lot-info').hidden = false;
                        $('li-status').className = `badge badge-${tone[info.status]}`;
                        $('li-status').textContent = PlanViewer.STATUS_LABEL[info.status];
                        $('li-title').textContent = `${PlanViewer.groupOf(geo)} · Lote ${geo.number}`;
                        const areaTxt = (geo.stage_label ? `${geo.stage_label} · ref. plano ${geo.plan_ref} · ` : '')
                            + (geo.area_label ? `${geo.area_label} m² en el plano (calculada ${geo.area} m²)` : `${geo.area} m² calculados`);
                        $('li-meta').textContent = info.id ? `${areaTxt} · ${money.format(info.price)}` : `${areaTxt} · Aún no está en el inventario${canSync ? ': usa «Sincronizar lotes»' : ''}.`;
                        $('li-edit').hidden = !(info.id && canEditLots);
                        if (info.id) $('li-edit').href = editUrl.replace('__ID__', info.id);
                    },
                });
            })();
        </script>
    @endpush
@endif

@if ($plan && $plan->status === \App\Models\Commercial\ProjectPlan::PROCESSING)
    @push('scripts')
        <script>
            (() => {
                const box = document.getElementById('plan-progress');
                const poll = async () => {
                    try {
                        const s = await LotHub.request(box.dataset.url);
                        document.getElementById('pp-step').textContent = s.step + '…';
                        document.getElementById('pp-bar').style.width = Math.max(2, s.pct) + '%';
                        document.getElementById('pp-time').textContent = s.elapsed < 60 ? `${s.elapsed} s` : `${Math.floor(s.elapsed / 60)} min ${s.elapsed % 60} s`;
                        if (s.status !== 'PROCESANDO') return location.reload();
                    } catch (e) { /* reintenta en el siguiente ciclo */ }
                    setTimeout(poll, 2000);
                };
                poll();
            })();
        </script>
    @endpush
@endif
