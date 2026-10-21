/**
 * Buscadores con resultados remotos para formularios con muchos registros (lotes, clientes).
 *
 *   <div class="picker" data-picker="/comercial/buscar/lotes?for=reserva" data-extra="#project_id">
 *       <input type="search" class="input" placeholder="Buscar…">
 *       <select name="lot_id" class="select" required></select>
 *   </div>
 *
 * - data-extra: selector de otro campo cuyo valor viaja como parámetro (p. ej. el proyecto).
 * - El servidor responde {data: [{id, label, ...}]}; cada <option> guarda su objeto en data-item.
 * - Al elegir, el contenedor emite 'picker:select' con detail = objeto (o null).
 */
window.LotHubPickers = (() => {
    const { esc } = LotHub;

    function init(root) {
        const input = root.querySelector('input[type=search]');
        const select = root.querySelector('select');
        const extras = (root.dataset.extra || '').split(',').filter(Boolean).map((s) => document.querySelector(s));
        const selected = select.dataset.selected ? JSON.parse(select.dataset.selected) : null;
        let timer;
        let controller;

        const emit = () => {
            const opt = select.selectedOptions[0];
            root.dispatchEvent(new CustomEvent('picker:select', { detail: opt?.dataset.item ? JSON.parse(opt.dataset.item) : null }));
        };

        async function load() {
            controller?.abort();
            controller = new AbortController();
            const url = new URL(root.dataset.picker, window.location.origin);
            if (input.value.trim()) url.searchParams.set('q', input.value.trim());
            extras.forEach((el) => el?.value && url.searchParams.set(el.name, el.value));

            const keep = select.value;
            select.innerHTML = '<option value="">Buscando…</option>';
            try {
                const res = await fetch(url, { headers: { Accept: 'application/json' }, signal: controller.signal });
                const { data } = await res.json();
                const items = [...data];
                if (selected && !items.some((i) => String(i.id) === String(selected.id))) items.unshift(selected);
                select.innerHTML = `<option value="">${items.length ? '— Seleccionar —' : 'Sin resultados'}</option>` + items.map((i) =>
                    `<option value="${esc(i.id)}" data-item='${esc(JSON.stringify(i))}'>${esc(i.label)}</option>`).join('');
                const wanted = keep || (selected && String(selected.id));
                if (wanted && items.some((i) => String(i.id) === wanted)) select.value = wanted;
                emit();
            } catch (err) {
                if (err.name !== 'AbortError') select.innerHTML = '<option value="">No se pudo cargar la lista</option>';
            }
        }

        input.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(load, 260); });
        input.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); clearTimeout(timer); load(); } });
        select.addEventListener('change', emit);
        extras.forEach((el) => el?.addEventListener('change', load));
        root.reload = load;
        load();
    }

    document.addEventListener('DOMContentLoaded', () => document.querySelectorAll('[data-picker]').forEach(init));

    return { init };
})();
