{{-- Detalle público de un proyecto: mapa de lotes por manzana con filtros (CU-30 + CU-09 vista pública). --}}
@extends('layouts.public')

@section('title', $project->name)

@section('content')
    @php
        $money = fn ($v) => '$ '.number_format((float) $v, 0, ',', '.');
        $available = \App\Models\Catalogs\LotStatus::DISPONIBLE;
        $class = [1 => 'available', 2 => 'reserved', 3 => 'sold'];          // ids de lot_statuses
        $statusLabel = [1 => 'Disponible', 2 => 'Reservado', 3 => 'Vendido'];
        $cover = $project->coverPhoto();
        // La portada va primero en la galería; el resto en el orden definido en el panel.
        $gallery = $project->photos->sortBy(fn ($p) => $p->is($cover) ? -1 : $p->sort_order)->values();
    @endphp

    <section class="project-hero {{ $cover ? 'has-cover' : '' }}" @if ($cover) style="--cover: url('{{ $cover->url }}')" @endif>
        <div class="container">
            <a href="{{ route('public.home') }}#proyectos" class="back"><i class="ph ph-arrow-left"></i> Todos los proyectos</a>
            <h1>{{ $project->name }}</h1>
            <div class="loc"><i class="ph ph-map-pin"></i> {{ $project->address ? $project->address.' · ' : '' }}{{ $project->city->name }}, {{ $project->city->state->name }}</div>
            @if ($project->description)<p class="desc">{{ $project->description }}</p>@endif
            <div class="kpis">
                <div class="kpi"><strong>{{ $project->available_lots }}</strong><span>lotes disponibles</span></div>
                <div class="kpi"><strong>{{ $project->total_lots }}</strong><span>lotes en total</span></div>
                <div class="kpi"><strong>{{ $project->min_price ? $money($project->min_price) : '—' }}</strong><span>precio desde</span></div>
                @if ($project->total_area_m2)
                    <div class="kpi"><strong>{{ number_format($project->total_area_m2, 0, ',', '.') }} m²</strong><span>área del proyecto</span></div>
                @endif
            </div>
        </div>
    </section>

    @if ($gallery->isNotEmpty())
        <section class="section" style="padding:2.5rem 0 0">
            <div class="container">
                <div class="section-head" style="margin-bottom:1.25rem">
                    <div><div class="kicker">Galería</div><h2>Conoce el proyecto</h2></div>
                </div>
                <div class="gallery" id="gallery">
                    @foreach ($gallery->take(5) as $photo)
                        <button type="button" data-index="{{ $loop->index }}" aria-label="Ver foto {{ $loop->iteration }}{{ $photo->caption ? ': '.$photo->caption : '' }}">
                            <img src="{{ $loop->first ? $photo->url : $photo->thumb_url }}" alt="{{ $photo->caption ?? $project->name }}" loading="lazy">
                            @if ($photo->caption)<figcaption>{{ $photo->caption }}</figcaption>@endif
                            @if ($loop->last && $gallery->count() > 5)<span class="more">+{{ $gallery->count() - 5 }} fotos</span>@endif
                        </button>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    <section class="section" style="padding-top:2.5rem">
        <div class="container lots-layout">
            <div>
                <div class="lot-filters">
                    @if ($planData)
                        <div class="view-tabs" role="tablist">
                            <button type="button" class="active" data-view="plan" role="tab"><i class="ph ph-map-trifold"></i> Plano</button>
                            <button type="button" data-view="grid" role="tab"><i class="ph ph-list-bullets"></i> Lista</button>
                        </div>
                    @endif
                    <select class="select" id="f-status" aria-label="Estado">
                        <option value="" @selected($planData)>Todos los estados</option>
                        <option value="available" @selected(! $planData)>Solo disponibles</option>
                    </select>
                    @if ($project->stages->count() > 1)
                        <select class="select" id="f-stage" aria-label="Etapa">
                            <option value="">Todas las etapas</option>
                            @foreach ($project->stages as $stage)
                                <option value="{{ $stage->id }}">{{ $stage->name }}</option>
                            @endforeach
                        </select>
                    @endif
                    <input type="number" class="input" id="f-price" placeholder="Precio máximo" min="0" step="1000000" aria-label="Precio máximo">
                    <div class="legend">
                        <span style="--c:var(--brand-200)">Disponible</span>
                        <span style="--c:var(--accent-500)">Reservado</span>
                        <span style="--c:#cfd5de">Vendido</span>
                    </div>
                </div>

                @if ($planData)
                    <div id="plan-view">
                        <div id="plan"></div>
                        <p class="plan-hint"><i class="ph ph-hand-pointing"></i> Toca un lote disponible para ver su precio. Arrastra para moverte y pellizca o usa la rueda para acercar.</p>
                    </div>
                @endif

                <div id="grid-view" @if ($planData) hidden @endif>
                    {{-- Con plano, la lista se arma en el navegador al abrir la pestaña (un plano puede traer miles de lotes). --}}
                    <div id="grid-blocks"></div>
                    @forelse ($blocks as $block => $blockLots)
                        <div class="block" data-block>
                            <h4>{{ \App\Models\Commercial\Lot::groupLabel($block) }}</h4>
                            <div class="lot-grid">
                                @foreach ($blockLots as $lot)
                                    @php
                                        $payload = json_encode([
                                            'code' => "{$lot->block}-{$lot->lot_number}", 'label' => $lot->label,
                                            'area' => (float) $lot->area_m2, 'price' => (float) $lot->list_price, 'stage' => $lot->stage?->name,
                                        ]);
                                    @endphp
                                    <button type="button" class="lot {{ $class[$lot->status_id] }}"
                                            data-lot="{{ $payload }}"
                                            data-status="{{ $class[$lot->status_id] }}" data-stage="{{ $lot->stage_id }}" data-price="{{ (float) $lot->list_price }}"
                                            @disabled((int) $lot->status_id !== $available)>
                                        <strong>{{ $lot->lot_number }}</strong>
                                        <small>{{ number_format($lot->area_m2, 0, ',', '.') }} m²</small>
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @empty
                        @unless ($planData)
                            <div class="card empty"><i class="ph ph-grid-four"></i>Este proyecto aún no tiene lotes publicados.</div>
                        @endunless
                    @endforelse
                    <div class="card empty" id="no-results" hidden><i class="ph ph-funnel-x"></i>Ningún lote coincide con los filtros.</div>
                </div>
            </div>

            <aside class="lot-detail">
                <div class="card card-body" id="lot-card" hidden style="margin-bottom:1rem">
                    <span class="badge badge-success">Disponible</span>
                    <h3 style="margin-top:.75rem" id="lot-label"></h3>
                    <div class="price" id="lot-price"></div>
                    <dl>
                        <dt>Área</dt><dd id="lot-area"></dd>
                        <dt>Etapa</dt><dd id="lot-stage"></dd>
                        <dt>Valor por m²</dt><dd id="lot-m2"></dd>
                    </dl>
                    @if ($planData)
                        <button type="button" class="btn btn-ghost btn-sm btn-block" id="lot-locate" style="margin-top:1rem" hidden><i class="ph ph-crosshair"></i> Ubicar en el plano</button>
                    @endif
                </div>
                <h3 style="margin-bottom:.75rem">Solicita información</h3>
                @include('public.partials.contact-form', ['projectId' => $project->id])
            </aside>
        </div>
    </section>
@endsection

@push('scripts')
@if ($planData)
    <script src="{{ asset('js/plan-viewer.js') }}?v={{ filemtime(public_path('js/plan-viewer.js')) }}"></script>
@endif
<script>
    (() => {
        const money = new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 });
        const $ = (id) => document.getElementById(id);
        let tiles = [...document.querySelectorAll('.lot')];
        const plan = {!! $planData ? json_encode($planData, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) : 'null' !!};
        const states = {!! json_encode((object) $lotStates, JSON_HEX_TAG) !!};
        let viewer = null;
        let current = null;

        function showLot(lot) {
            current = lot;
            $('lot-card').hidden = false;
            $('lot-label').textContent = lot.label;
            $('lot-price').textContent = money.format(lot.price);
            $('lot-area').textContent = `${Number(lot.area).toLocaleString('es-CO')} m²`;
            $('lot-stage').textContent = lot.stage || '—';
            $('lot-m2').textContent = money.format(lot.price / lot.area);
            if ($('lot-locate')) $('lot-locate').hidden = !viewer || !$('plan-view').hidden;
            const msg = $('message');
            if (msg && (!msg.value || msg.dataset.auto)) { msg.value = `Me interesa el lote ${lot.label}.`; msg.dataset.auto = '1'; }
            if (window.innerWidth < 1080) $('lot-card').scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        function filters() {
            return { status: $('f-status').value, stage: $('f-stage')?.value, max: Number($('f-price').value) || Infinity };
        }

        function applyFilters() {
            const f = filters();
            tiles.forEach((l) => {
                l.hidden = (f.status && l.dataset.status !== f.status) || (f.stage && l.dataset.stage !== f.stage) || Number(l.dataset.price) > f.max;
            });
            document.querySelectorAll('[data-block]').forEach((b) => { b.hidden = !b.querySelector('.lot:not([hidden])'); });
            $('no-results').hidden = tiles.length === 0 || tiles.some((l) => !l.hidden);
            viewer?.setFilter((code, s) => (!f.status || s.status === f.status)
                && (!f.stage || String(s.stage_id) === f.stage) && !((s.price ?? 0) > f.max));
        }

        document.querySelectorAll('.lot-filters select, .lot-filters input').forEach((el) => el.addEventListener('input', applyFilters));

        function bindTiles() {
            tiles.forEach((btn) => btn.addEventListener('click', () => {
                tiles.forEach((l) => l.classList.remove('selected'));
                btn.classList.add('selected');
                showLot(JSON.parse(btn.dataset.lot));
            }));
        }
        bindTiles();

        // Lista generada a partir del plano (solo la primera vez que se abre la pestaña).
        function renderGrid() {
            if (!plan || tiles.length) return;
            const { esc } = LotHub;
            const statusClass = { available: 'available', reserved: 'reserved', sold: 'sold' };
            const groups = new Map();
            plan.lots.forEach((geo) => {
                const s = states[geo.code];
                if (!s || !statusClass[s.status]) return;
                const name = PlanViewer.groupOf(geo);
                if (!groups.has(name)) groups.set(name, []);
                groups.get(name).push({ geo, s });
            });
            const num = (n) => parseInt(n, 10) || 0;
            let html = '';
            groups.forEach((items, name) => {
                items.sort((a, b) => num(a.geo.number) - num(b.geo.number));
                html += `<div class="block" data-block><h4>${esc(name)}</h4><div class="lot-grid">` + items.map(({ geo, s }) => {
                    const lot = { code: geo.code, label: `${name} · Lote ${geo.number}`, area: s.area, price: s.price, stage: s.stage };
                    return `<button type="button" class="lot ${statusClass[s.status]}" data-lot="${esc(JSON.stringify(lot))}"
                        data-status="${statusClass[s.status]}" data-stage="${esc(s.stage_id ?? '')}" data-price="${s.price}" ${s.status === 'available' ? '' : 'disabled'}>
                        <strong>${esc(geo.number)}</strong><small>${Math.round(s.area).toLocaleString('es-CO')} m²</small></button>`;
                }).join('') + '</div></div>';
            });
            $('grid-blocks').innerHTML = html || '<div class="card empty"><i class="ph ph-grid-four"></i>Este proyecto aún no tiene lotes publicados.</div>';
            tiles = [...$('grid-blocks').querySelectorAll('.lot')];
            bindTiles();
            applyFilters();
        }

        if (plan) {
            viewer = new PlanViewer($('plan'), {
                plan, lots: states,
                onSelect(code, info, geo) {
                    showLot({ code, label: `${PlanViewer.groupOf(geo)} · Lote ${geo.number}`, area: info.area, price: info.price, stage: info.stage });
                },
            });

            document.querySelectorAll('[data-view]').forEach((tab) => tab.addEventListener('click', () => {
                const isPlan = tab.dataset.view === 'plan';
                document.querySelectorAll('[data-view]').forEach((t) => t.classList.toggle('active', t === tab));
                if (!isPlan) renderGrid();
                $('plan-view').hidden = !isPlan;
                $('grid-view').hidden = isPlan;
                if (isPlan) viewer.apply();
                if ($('lot-locate')) $('lot-locate').hidden = isPlan || !current;
            }));

            // Desde la lista: "Ubicar en el plano" cambia de vista y centra el lote.
            $('lot-locate')?.addEventListener('click', () => {
                document.querySelector('[data-view="plan"]').click();
                viewer.select(current.code);
                viewer.focus(current.code);
                $('plan').scrollIntoView({ behavior: 'smooth', block: 'center' });
            });
        }

        applyFilters();

        // Galería: visor a pantalla completa con flechas y teclado.
        const photos = @json($gallery->map(fn ($p) => ['url' => $p->url, 'caption' => $p->caption])->values());
        document.getElementById('gallery')?.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-index]');
            if (!btn) return;
            let index = Number(btn.dataset.index);
            const box = document.createElement('div');
            box.className = 'lightbox';
            box.setAttribute('role', 'dialog');
            box.innerHTML = `<button class="prev" aria-label="Anterior"><i class="ph ph-caret-left"></i></button>
                <figure><img alt=""><figcaption></figcaption></figure>
                <button class="next" aria-label="Siguiente"><i class="ph ph-caret-right"></i></button>
                <button class="close" aria-label="Cerrar"><i class="ph ph-x"></i></button>`;
            const show = () => {
                const p = photos[index];
                box.querySelector('img').src = p.url;
                box.querySelector('img').alt = p.caption || '';
                box.querySelector('figcaption').textContent = p.caption ? `${p.caption} · ${index + 1}/${photos.length}` : `${index + 1}/${photos.length}`;
            };
            const move = (d) => { index = (index + d + photos.length) % photos.length; show(); };
            const close = () => { box.remove(); document.removeEventListener('keydown', keys); };
            const keys = (ev) => ({ ArrowLeft: () => move(-1), ArrowRight: () => move(1), Escape: close })[ev.key]?.();
            box.addEventListener('click', (ev) => {
                if (ev.target.closest('.prev')) move(-1);
                else if (ev.target.closest('.next')) move(1);
                else if (ev.target.closest('.close') || ev.target === box) close();
            });
            document.addEventListener('keydown', keys);
            document.body.appendChild(box);
            show();
        });
    })();
</script>
@endpush
