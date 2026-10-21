/**
 * Tabla genérica del CRUD. Lee su configuración del <script id="crud-config">
 * que genera resources/views/crud/index.blade.php a partir de CrudController::page().
 *
 * Tipos de columna soportados: text, strong, muted, code, number, money, date, bool, badge, json.
 */
(() => {
    const config = JSON.parse(document.getElementById('crud-config').textContent);
    const { esc } = LotHub;

    const el = {
        body:    document.getElementById('crud-body'),
        search:  document.getElementById('crud-search'),
        perPage: document.getElementById('crud-per-page'),
        info:    document.getElementById('crud-info'),
        pager:   document.getElementById('crud-pager'),
        filters: document.querySelectorAll('[data-filter]'),
    };

    const state = { q: '', page: 1, rows: [] };
    const colspan = config.columns.length + 1;
    const money = new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 });
    const number = new Intl.NumberFormat('es-CO', { maximumFractionDigits: 2 });

    const get = (obj, path) => path.split('.').reduce((o, k) => (o == null ? null : o[k]), obj);
    const url = (template, id) => template.replace('__ID__', encodeURIComponent(id));

    function cell(row, col, index) {
        const raw = get(row, col.key);
        const empty = '<span class="cell-muted">—</span>';
        if (raw === null || raw === undefined || raw === '') return `<td>${empty}</td>`;

        switch (col.type) {
            case 'strong': return `<td class="strong">${esc(raw)}</td>`;
            case 'muted':  return `<td class="cell-muted">${esc(raw)}</td>`;
            case 'code':   return `<td><code>${esc(raw)}</code></td>`;
            case 'number': return `<td class="num">${esc(number.format(Number(raw)))}</td>`;
            case 'money':  return `<td class="num nowrap">${esc(money.format(Number(raw)))}</td>`;
            case 'date':   return `<td class="nowrap">${esc(String(raw).slice(0, 10))}</td>`;
            case 'bool':   return `<td><span class="badge badge-${raw ? 'success' : 'neutral'}">${raw ? 'Sí' : 'No'}</span></td>`;
            case 'badge': {
                const tone = (col.colors || {})[raw] || 'neutral';
                return `<td><span class="badge badge-${esc(tone)}">${esc(raw)}</span></td>`;
            }
            case 'json':
                return `<td><button class="btn btn-ghost btn-sm" data-json="${index}" data-key="${esc(col.key)}"><i class="ph ph-eye"></i>Ver</button></td>`;
            default:       return `<td>${esc(raw)}</td>`;
        }
    }

    function actions(row) {
        const btns = (config.row_actions || []).map((a) =>
            `<a class="icon-btn" href="${esc(url(a.url, row.id))}" title="${esc(a.label)}" aria-label="${esc(a.label)}"><i class="ph ${esc(a.icon)}"></i></a>`);
        if (config.can.edit) {
            btns.push(`<a class="icon-btn" href="${esc(url(config.routes.edit, row.id))}" title="Editar" aria-label="Editar"><i class="ph ph-pencil-simple"></i></a>`);
        }
        if (config.can.delete) {
            btns.push(`<button class="icon-btn danger" data-delete="${esc(row.id)}" title="Eliminar" aria-label="Eliminar"><i class="ph ph-trash"></i></button>`);
        }
        return `<td class="actions">${btns.join('')}</td>`;
    }

    async function load(page = state.page) {
        state.page = page;
        el.body.innerHTML = `<tr><td colspan="${colspan}" class="loading"><i class="ph ph-circle-notch spin"></i> Cargando…</td></tr>`;

        const params = new URLSearchParams({ q: state.q, page, per_page: el.perPage.value });
        el.filters.forEach((f) => f.value && params.set(f.dataset.filter, f.value));

        try {
            const res = await LotHub.request(`${config.routes.data}?${params}`);
            state.rows = res.data;
            el.body.innerHTML = res.data.length
                ? res.data.map((row, i) => `<tr>${config.columns.map((c) => cell(row, c, i)).join('')}${actions(row)}</tr>`).join('')
                : `<tr><td colspan="${colspan}" class="empty-row"><i class="ph ph-magnifying-glass"></i> No se encontraron registros.</td></tr>`;
            el.info.textContent = res.total ? `Mostrando ${res.from}–${res.to} de ${res.total}` : 'Sin resultados';
            renderPager(res.page, res.last_page);
        } catch (err) {
            el.body.innerHTML = `<tr><td colspan="${colspan}" class="empty-row">${esc(err.message)}</td></tr>`;
        }
    }

    function renderPager(current, last) {
        if (last <= 1) { el.pager.innerHTML = ''; return; }
        const pages = [...new Set([1, current - 1, current, current + 1, last])].filter((p) => p >= 1 && p <= last).sort((a, b) => a - b);
        let html = `<button data-page="${current - 1}" ${current === 1 ? 'disabled' : ''} aria-label="Anterior"><i class="ph ph-caret-left"></i></button>`;
        pages.forEach((p, i) => {
            if (i && p - pages[i - 1] > 1) html += '<button disabled>…</button>';
            html += `<button data-page="${p}" class="${p === current ? 'active' : ''}">${p}</button>`;
        });
        html += `<button data-page="${current + 1}" ${current === last ? 'disabled' : ''} aria-label="Siguiente"><i class="ph ph-caret-right"></i></button>`;
        el.pager.innerHTML = html;
    }

    async function remove(id) {
        const ok = await LotHub.confirm({
            title: `Eliminar ${config.singular}`,
            message: config.trash
                ? 'El registro se enviará a la papelera y podrás restaurarlo después.'
                : 'Esta acción no se puede deshacer.',
            confirmText: 'Eliminar',
            danger: true,
        });
        if (!ok) return;
        try {
            const res = await LotHub.request(url(config.routes.destroy, id), { method: 'DELETE' });
            LotHub.toast(res.message);
            load();
        } catch (err) {
            LotHub.toast(err.message, 'error');
        }
    }

    // ── Eventos ──────────────────────────────────────────────────────────
    let debounce;
    el.search.addEventListener('input', () => {
        clearTimeout(debounce);
        debounce = setTimeout(() => { state.q = el.search.value.trim(); load(1); }, 280);
    });
    el.perPage.addEventListener('change', () => load(1));
    el.filters.forEach((f) => f.addEventListener('change', () => load(1)));
    el.pager.addEventListener('click', (e) => {
        const b = e.target.closest('[data-page]');
        if (b && !b.disabled) load(Number(b.dataset.page));
    });
    // Botones del encabezado que deben respetar la búsqueda y los filtros actuales (p. ej. Exportar).
    document.querySelectorAll('[data-keep-filters]').forEach((link) => link.addEventListener('click', () => {
        const target = new URL(link.href, window.location.origin);
        target.search = '';
        if (state.q) target.searchParams.set('q', state.q);
        el.filters.forEach((f) => f.value && target.searchParams.set(f.dataset.filter, f.value));
        link.href = target.toString();
    }));
    el.body.addEventListener('click', (e) => {
        const del = e.target.closest('[data-delete]');
        if (del) return remove(del.dataset.delete);

        const json = e.target.closest('[data-json]');
        if (json) {
            const value = get(state.rows[json.dataset.json], json.dataset.key);
            LotHub.modal({ title: 'Detalle del registro', html: `<pre>${esc(JSON.stringify(value, null, 2))}</pre>` });
        }
    });

    load(1);
})();
