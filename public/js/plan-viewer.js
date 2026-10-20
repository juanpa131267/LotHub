/**
 * Plano interactivo de un proyecto (SVG, coordenadas reales en metros).
 *
 *   const viewer = new PlanViewer(contenedor, {
 *       plan,                       // JSON generado por tools/plan-processor
 *       lots: { 'A-1': { status: 'available'|'reserved'|'sold'|'blocked', price, area, stage } },
 *       onSelect: (code, lot) => {},  // clic en un lote seleccionable
 *       selectable: ['available'],    // estados que se pueden escoger (panel interno: todos)
 *   });
 *   viewer.setFilter((code, lot) => true);  // atenúa los lotes que no cumplen
 *   viewer.select('A-1');
 *
 * Interacción: rueda o pellizco para acercar, arrastrar para mover, doble clic para acercar.
 */
class PlanViewer {
    constructor(root, { plan, lots = {}, onSelect = () => {}, selectable = ['available'] }) {
        this.root = root;
        this.plan = plan;
        this.lots = lots;
        this.onSelect = onSelect;
        this.selectable = selectable;
        this.H = plan.size[1];
        this.W = plan.size[0];
        this.view = { x: 0, y: 0, w: this.W, h: this.H };
        this.pointers = new Map();
        this.money = new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 });
        this.render();
        this.bind();
        this.fit();
    }

    // ── Construcción del SVG ─────────────────────────────────────────────
    /** "Manzana A" o "Etapa II", según cómo esté organizado el plano. */
    static groupOf(lot) {
        return lot.group_label || `Manzana ${lot.block}`;
    }

    static STATUS_LABEL = { available: 'Disponible', reserved: 'Reservado', sold: 'Vendido', blocked: 'No disponible', unknown: 'Sin publicar' };

    pts(points) {
        return points.map(([x, y]) => `${x},${(this.H - y).toFixed(2)}`).join(' ');
    }

    xy([x, y]) {
        return [x, this.H - y];
    }

    render() {
        const L = this.plan.layers;
        const ns = 'http://www.w3.org/2000/svg';
        const esc = (s) => String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

        const lines = (list, cls) => (list || []).map((p) => `<polyline class="${cls}" points="${this.pts(p)}"/>`).join('');
        const fills = (list, cls) => (list || []).map((p) => `<polygon class="${cls}" points="${this.pts(p)}"/>`).join('');
        const site = fills(L.site, 'pv-site');
        const blocks = L.blocks.map((b) => `<polygon class="pv-block" points="${this.pts(b.points)}"/>`).join('');
        const blockLabels = L.blocks.map((b) => {
            const [x, y] = this.xy(b.label_xy);
            return b.name ? `<text class="pv-block-label" x="${x}" y="${y}">Mz ${esc(b.name)}</text>` : '';
        }).join('');

        const lots = this.plan.lots.map((lot) => {
            const info = this.lots[lot.code] || { status: 'unknown' };
            const [x, y] = this.xy(lot.label_xy);
            const pick = this.selectable.includes(info.status);
            return `<g class="pv-lot is-${info.status}${pick ? ' is-pickable' : ''}${lot.check !== 'ok' ? ' has-warning' : ''}" data-code="${esc(lot.code)}" tabindex="${pick ? 0 : -1}" role="button" aria-label="Lote ${esc(lot.code)}, ${PlanViewer.STATUS_LABEL[info.status]}">
                <polygon points="${this.pts(lot.points)}"/>
                <text x="${x}" y="${y}">${esc(lot.number)}</text></g>`;
        }).join('');

        const labels = this.plan.labels.map((l) => {
            const [x, y] = this.xy(l.xy);
            return `<text class="pv-label pv-label-${l.kind}" x="${x}" y="${y}">${esc(l.text)}</text>`;
        }).join('');

        this.root.classList.add('plan-viewer');
        // Rótulos proporcionales al proyecto: 3 m en un loteo pequeño, más grandes en uno de 1 km.
        const span = Math.max(this.W, this.H);
        this.root.style.setProperty('--pv-label', Math.max(3, span / 220).toFixed(2));
        this.root.style.setProperty('--pv-block-label', Math.max(5.5, span / 120).toFixed(2));
        this.root.innerHTML = `
            <svg xmlns="${ns}" class="pv-svg" preserveAspectRatio="xMidYMid meet" aria-label="Plano del proyecto">
                <rect class="pv-ground" x="-500" y="-500" width="${this.W + 1000}" height="${this.H + 1000}"/>
                <g class="pv-context">
                    ${site}
                    ${fills(L.green, 'pv-green')}
                    ${fills(L.water, 'pv-water')}
                    ${lines(L.streams, 'pv-stream')}
                    ${blocks}
                    ${lines(L.sidewalks, 'pv-sidewalk')}
                    ${lines(L.parking, 'pv-parking')}
                    ${lines(L.roads, 'pv-road')}
                    ${lines(L.boundary, 'pv-boundary')}
                </g>
                <g class="pv-lots">${lots}</g>
                <g class="pv-labels">${blockLabels}${labels}</g>
            </svg>
            <div class="pv-compass" title="Norte" style="--north:${this.plan.north_deg}deg">
                <svg viewBox="0 0 40 40" aria-hidden="true"><circle cx="20" cy="20" r="18"/><path d="M20 5 L25 21 H15 Z" class="n"/><path d="M20 35 L15 19 H25 Z"/></svg>
                <span>N</span>
            </div>
            <div class="pv-scale"><span class="pv-scale-bar"></span><span class="pv-scale-text"></span></div>
            <div class="pv-controls">
                <button type="button" data-zoom="in" aria-label="Acercar"><i class="ph ph-plus"></i></button>
                <button type="button" data-zoom="out" aria-label="Alejar"><i class="ph ph-minus"></i></button>
                <button type="button" data-zoom="fit" aria-label="Ver todo el proyecto"><i class="ph ph-corners-out"></i></button>
            </div>
            <div class="pv-tooltip" hidden></div>`;

        this.svg = this.root.querySelector('.pv-svg');
        this.tooltip = this.root.querySelector('.pv-tooltip');
        this.scaleBar = this.root.querySelector('.pv-scale-bar');
        this.scaleText = this.root.querySelector('.pv-scale-text');
    }

    // ── Vista (zoom y desplazamiento) ────────────────────────────────────
    apply() {
        const v = this.view;
        this.svg.setAttribute('viewBox', `${v.x} ${v.y} ${v.w} ${v.h}`);
        const rect = this.svg.getBoundingClientRect();
        const pxPerM = Math.min(rect.width / v.w, rect.height / v.h) || 1;
        this.root.style.setProperty('--px-per-m', pxPerM);
        this.root.classList.toggle('show-numbers', pxPerM > 2.6);
        this.root.classList.toggle('show-details', pxPerM > 5);
        this.updateScale(pxPerM);
    }

    updateScale(pxPerM) {
        const target = 110 / pxPerM; // metros que caben en ~110 px
        const nice = [1, 2, 5, 10, 20, 25, 50, 100, 200, 500].find((n) => n >= target * 0.6) || 500;
        this.scaleBar.style.width = `${nice * pxPerM}px`;
        this.scaleText.textContent = `${nice} m`;
    }

    fit() {
        const pad = 0;
        this.view = { x: -pad, y: -pad, w: this.W + pad * 2, h: this.H + pad * 2 };
        this.apply();
    }

    zoom(factor, cx, cy) {
        const v = this.view;
        const minW = 25, maxW = Math.max(this.W, this.H) * 2.5;
        const w = Math.min(Math.max(v.w / factor, minW), maxW);
        const k = w / v.w;
        cx ??= v.x + v.w / 2;
        cy ??= v.y + v.h / 2;
        this.view = { x: cx - (cx - v.x) * k, y: cy - (cy - v.y) * k, w, h: v.h * k };
        this.apply();
    }

    toPlan(clientX, clientY) {
        const p = new DOMPoint(clientX, clientY).matrixTransform(this.svg.getScreenCTM().inverse());
        return [p.x, p.y];
    }

    focus(code) {
        const lot = this.plan.lots.find((l) => l.code === code);
        if (!lot) return;
        const [x, y] = this.xy(lot.label_xy);
        const w = Math.min(this.view.w, 80);
        const h = this.view.h * (w / this.view.w);
        this.view = { x: x - w / 2, y: y - h / 2, w, h };
        this.apply();
    }

    // ── Eventos ──────────────────────────────────────────────────────────
    bind() {
        this.root.querySelector('.pv-controls').addEventListener('click', (e) => {
            const b = e.target.closest('[data-zoom]');
            if (!b) return;
            ({ in: () => this.zoom(1.5), out: () => this.zoom(1 / 1.5), fit: () => this.fit() })[b.dataset.zoom]();
        });

        this.svg.addEventListener('wheel', (e) => {
            e.preventDefault();
            const [x, y] = this.toPlan(e.clientX, e.clientY);
            this.zoom(e.deltaY < 0 ? 1.2 : 1 / 1.2, x, y);
        }, { passive: false });

        this.svg.addEventListener('dblclick', (e) => {
            const [x, y] = this.toPlan(e.clientX, e.clientY);
            this.zoom(2, x, y);
        });

        // Arrastre con ratón / dedo y pellizco con dos dedos.
        let moved = false;
        this.svg.addEventListener('pointerdown', (e) => {
            this.pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });
            this.svg.setPointerCapture(e.pointerId);
            moved = false;
        });
        this.svg.addEventListener('pointermove', (e) => {
            const prev = this.pointers.get(e.pointerId);
            if (!prev) return this.hover(e);
            const rect = this.svg.getBoundingClientRect();
            const scale = Math.max(this.view.w / rect.width, this.view.h / rect.height);
            if (this.pointers.size === 1) {
                const dx = e.clientX - prev.x, dy = e.clientY - prev.y;
                if (Math.abs(dx) + Math.abs(dy) > 3) moved = true;
                this.view.x -= dx * scale;
                this.view.y -= dy * scale;
                this.apply();
            } else if (this.pointers.size === 2) {
                const [a, b] = [...this.pointers.values()];
                const before = Math.hypot(a.x - b.x, a.y - b.y);
                this.pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });
                const [c, d] = [...this.pointers.values()];
                const after = Math.hypot(c.x - d.x, c.y - d.y);
                const [mx, my] = this.toPlan((c.x + d.x) / 2, (c.y + d.y) / 2);
                if (before > 0) this.zoom(after / before, mx, my);
                moved = true;
                return;
            }
            this.pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });
        });
        const end = (e) => {
            this.pointers.delete(e.pointerId);
            if (!moved && e.type === 'pointerup') this.clickAt(e);
        };
        this.svg.addEventListener('pointerup', end);
        this.svg.addEventListener('pointercancel', end);
        this.svg.addEventListener('pointerleave', () => { this.tooltip.hidden = true; });

        this.svg.addEventListener('keydown', (e) => {
            const g = e.target.closest?.('.pv-lot');
            if (g && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); this.choose(g.dataset.code); }
        });

        new ResizeObserver(() => this.apply()).observe(this.root);
    }

    lotAt(e) {
        return document.elementFromPoint(e.clientX, e.clientY)?.closest('.pv-lot');
    }

    hover(e) {
        const g = this.lotAt(e);
        this.svg.querySelectorAll('.pv-lot.hover').forEach((n) => n !== g && n.classList.remove('hover'));
        if (!g) { this.tooltip.hidden = true; return; }
        g.classList.add('hover');
        const code = g.dataset.code;
        const info = this.lots[code] || { status: 'unknown' };
        const lot = this.plan.lots.find((l) => l.code === code);
        const area = info.area ?? lot.area;
        this.tooltip.innerHTML = `<strong>${PlanViewer.groupOf(lot)} · Lote ${lot.number}</strong>
            ${lot.stage_label ? `<span>${lot.stage_label}</span>` : ''}
            <span>${Number(area).toLocaleString('es-CO')} m²${info.price ? ' · ' + this.money.format(info.price) : ''}</span>
            <em class="is-${info.status}">${PlanViewer.STATUS_LABEL[info.status]}</em>`;
        const box = this.root.getBoundingClientRect();
        this.tooltip.style.left = `${e.clientX - box.left + 14}px`;
        this.tooltip.style.top = `${e.clientY - box.top + 14}px`;
        this.tooltip.hidden = false;
    }

    clickAt(e) {
        const g = this.lotAt(e);
        if (g) this.choose(g.dataset.code);
    }

    choose(code) {
        const info = this.lots[code] || { status: 'unknown' };
        if (!this.selectable.includes(info.status)) return;
        this.select(code);
        this.onSelect(code, info, this.plan.lots.find((l) => l.code === code));
    }

    select(code) {
        this.svg.querySelectorAll('.pv-lot.selected').forEach((n) => n.classList.remove('selected'));
        this.svg.querySelector(`.pv-lot[data-code="${CSS.escape(code)}"]`)?.classList.add('selected');
    }

    setFilter(predicate) {
        this.svg.querySelectorAll('.pv-lot').forEach((g) => {
            const code = g.dataset.code;
            g.classList.toggle('dimmed', !predicate(code, this.lots[code] || { status: 'unknown' }));
        });
    }
}

window.PlanViewer = PlanViewer;
