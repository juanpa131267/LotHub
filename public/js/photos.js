/**
 * Fotos del proyecto (resources/views/commercial/photos.blade.php).
 * La carga masiva envía las fotos una por una (evita los límites de PHP por petición y permite
 * mostrar el avance); la carga individual envía además la anotación.
 */
(() => {
    const config = JSON.parse(document.getElementById('photos-config').textContent);
    const { esc } = LotHub;
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const $ = (id) => document.getElementById(id);
    const url = (name, id) => config.routes[name].replace('__ID__', id);
    let photos = config.photos;

    // ── Galería ──────────────────────────────────────────────────────────
    function render() {
        $('photo-count').textContent = `(${photos.length})`;
        $('photo-empty').hidden = photos.length > 0;
        $('photo-grid').innerHTML = photos.map((p, i) => `
            <article class="card photo-card" data-id="${p.id}">
                <div class="thumb">
                    <img src="${esc(p.thumb_url)}" alt="${esc(p.caption || 'Foto del proyecto')}" loading="lazy">
                    ${p.is_cover ? '<span class="cover-tag">Portada</span>' : ''}
                </div>
                <div class="body">
                    ${config.canEdit
                        ? `<input class="input caption" value="${esc(p.caption || '')}" maxlength="250" placeholder="Agregar anotación…" aria-label="Anotación">`
                        : `<div class="muted" style="font-size:.85rem">${esc(p.caption || 'Sin anotación')}</div>`}
                    ${config.canEdit ? `<div class="tools">
                        <span>
                            <button class="icon-btn" data-move="-1" title="Mover antes" ${i === 0 ? 'disabled' : ''}><i class="ph ph-arrow-left"></i></button>
                            <button class="icon-btn" data-move="1" title="Mover después" ${i === photos.length - 1 ? 'disabled' : ''}><i class="ph ph-arrow-right"></i></button>
                        </span>
                        <span>
                            <button class="icon-btn ${p.is_cover ? 'is-cover' : ''}" data-cover title="Usar como portada"><i class="ph${p.is_cover ? '-fill' : ''} ph-star"></i></button>
                            <a class="icon-btn" href="${esc(p.url)}" target="_blank" title="Ver en tamaño completo"><i class="ph ph-arrows-out"></i></a>
                            <button class="icon-btn danger" data-delete title="Eliminar"><i class="ph ph-trash"></i></button>
                        </span>
                    </div>` : ''}
                </div>
            </article>`).join('');
    }

    async function send(file, caption = '') {
        const body = new FormData();
        body.append('photo', file);
        if (caption) body.append('caption', caption);
        const res = await fetch(config.routes.store, { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' }, body });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || data.ok === false) {
            throw new Error(data.message || Object.values(data.errors || {})[0]?.[0] || (res.status === 413 ? 'Archivo demasiado grande.' : 'No se pudo cargar.'));
        }
        photos.push(data.photo);
        render();
    }

    if (config.canEdit) {
        const maxBytes = Number($('bulk-input').dataset.limitBytes);

        // ── 1. Carga masiva ──────────────────────────────────────────────
        async function bulk(files) {
            const queue = $('bulk-queue');
            const items = [...files].filter((f) => /\.(jpe?g|png|webp)$/i.test(f.name));
            const rows = items.map((f) => {
                const row = document.createElement('div');
                row.innerHTML = `<i class="ph ph-clock muted"></i><span class="name">${esc(f.name)}</span><span class="muted">En espera</span>`;
                queue.prepend(row);
                return row;
            });
            let ok = 0;
            for (const [i, file] of items.entries()) {
                const row = rows[i];
                const state = (icon, cls, text) => { row.innerHTML = `<i class="ph ${icon} ${cls}"></i><span class="name">${esc(file.name)}</span><span class="${cls}">${esc(text)}</span>`; };
                if (file.size > maxBytes) { state('ph-x-circle', 'err', 'Supera el tamaño máximo'); continue; }
                state('ph-circle-notch spin', 'muted', 'Subiendo…');
                try { await send(file); state('ph-check-circle', 'ok', 'Listo'); ok++; } catch (e) { state('ph-x-circle', 'err', e.message); }
            }
            if (items.length) LotHub.toast(`${ok} de ${items.length} foto(s) cargadas.`, ok === items.length ? 'success' : 'error');
            $('bulk-input').value = '';
        }

        const drop = $('bulk-drop');
        $('bulk-input').addEventListener('change', (e) => bulk(e.target.files));
        ['dragenter', 'dragover'].forEach((ev) => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.add('dragover'); }));
        ['dragleave', 'drop'].forEach((ev) => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.remove('dragover'); }));
        drop.addEventListener('drop', (e) => bulk(e.dataTransfer.files));

        // ── 2. Una por una, con anotación ─────────────────────────────────
        $('single-input').addEventListener('change', (e) => {
            const file = e.target.files[0];
            $('single-preview').classList.toggle('show', !!file);
            if (file) $('single-preview').src = URL.createObjectURL(file);
        });
        $('single-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const file = $('single-input').files[0];
            if (!file) return LotHub.toast('Elige una foto.', 'error');
            const btn = $('single-submit');
            btn.disabled = true;
            btn.innerHTML = '<i class="ph ph-circle-notch spin"></i> Subiendo…';
            try {
                await send(file, $('single-caption').value.trim());
                LotHub.toast('Foto agregada.');
                e.target.reset();
                $('single-preview').classList.remove('show');
            } catch (err) {
                LotHub.toast(err.message, 'error');
            } finally {
                btn.disabled = false;
                btn.innerHTML = '<i class="ph ph-upload-simple"></i> Agregar foto';
            }
        });

        // ── Acciones sobre cada foto ──────────────────────────────────────
        const grid = $('photo-grid');
        grid.addEventListener('change', async (e) => {
            if (!e.target.matches('.caption')) return;
            const id = e.target.closest('[data-id]').dataset.id;
            try {
                await LotHub.request(url('update', id), { method: 'PUT', body: { caption: e.target.value } });
                photos.find((p) => p.id == id).caption = e.target.value;
                LotHub.toast('Anotación guardada.');
            } catch (err) { LotHub.toast(err.message, 'error'); }
        });
        grid.addEventListener('keydown', (e) => { if (e.key === 'Enter' && e.target.matches('.caption')) e.target.blur(); });

        grid.addEventListener('click', async (e) => {
            const card = e.target.closest('[data-id]');
            if (!card) return;
            const id = Number(card.dataset.id);
            const index = photos.findIndex((p) => p.id === id);

            if (e.target.closest('[data-cover]')) {
                await LotHub.request(url('cover', id), { method: 'POST' });
                photos.forEach((p) => { p.is_cover = p.id === id; });
                render();
            } else if (e.target.closest('[data-move]')) {
                const to = index + Number(e.target.closest('[data-move]').dataset.move);
                [photos[index], photos[to]] = [photos[to], photos[index]];
                render();
                await LotHub.request(config.routes.reorder, { method: 'POST', body: { ids: photos.map((p) => p.id) } });
            } else if (e.target.closest('[data-delete]')) {
                const ok = await LotHub.confirm({ title: 'Eliminar foto', message: 'La foto se borrará de la ficha de venta. Esta acción no se puede deshacer.', confirmText: 'Eliminar', danger: true });
                if (!ok) return;
                await LotHub.request(url('destroy', id), { method: 'DELETE' });
                photos.splice(index, 1);
                render();
                LotHub.toast('Foto eliminada.');
            }
        });
    }

    render();
})();
