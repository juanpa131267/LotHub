/**
 * LotHub — utilidades globales del panel (sin dependencias).
 *   LotHub.request(url, {method, body})  → fetch con CSRF y JSON
 *   LotHub.toast(mensaje, 'success'|'error')
 *   LotHub.confirm({title, message, confirmText, danger}) → Promise<boolean>
 *   LotHub.modal({title, html})
 *   LotHub.esc(texto) → texto seguro para insertar como HTML
 */
window.LotHub = (() => {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;

    const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => (
        { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
    ));

    async function request(url, { method = 'GET', body } = {}) {
        const res = await fetch(url, {
            method,
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: body ? JSON.stringify(body) : undefined,
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || data.ok === false) {
            throw new Error(data.message || 'No se pudo completar la operación.');
        }
        return data;
    }

    function toast(message, type = 'success') {
        let box = document.querySelector('.toasts');
        if (!box) {
            box = document.createElement('div');
            box.className = 'toasts';
            document.body.appendChild(box);
        }
        const el = document.createElement('div');
        el.className = `toast ${type}`;
        el.setAttribute('role', 'status');
        el.innerHTML = `<i class="ph-fill ${type === 'error' ? 'ph-warning-circle' : 'ph-check-circle'}"></i><span>${esc(message)}</span>`;
        box.appendChild(el);
        setTimeout(() => el.remove(), 4500);
    }

    function modal({ title, html, footer = '' }) {
        const wrap = document.createElement('div');
        wrap.className = 'modal-backdrop';
        wrap.innerHTML = `
            <div class="modal" role="dialog" aria-modal="true" aria-label="${esc(title)}">
                <div class="modal-head"><h3>${esc(title)}</h3>
                    <button class="icon-btn" data-close aria-label="Cerrar"><i class="ph ph-x"></i></button></div>
                <div class="modal-body">${html}</div>
                ${footer ? `<div class="modal-foot">${footer}</div>` : ''}
            </div>`;
        const close = () => { wrap.remove(); document.removeEventListener('keydown', onKey); };
        const onKey = (e) => e.key === 'Escape' && close();
        wrap.addEventListener('click', (e) => { if (e.target === wrap || e.target.closest('[data-close]')) close(); });
        document.addEventListener('keydown', onKey);
        document.body.appendChild(wrap);
        return { el: wrap, close };
    }

    function confirm({ title = '¿Confirmar acción?', message = '', confirmText = 'Confirmar', danger = false } = {}) {
        return new Promise((resolve) => {
            const m = modal({
                title,
                html: `<p>${esc(message)}</p>`,
                footer: `<button class="btn btn-ghost" data-close>Cancelar</button>
                         <button class="btn ${danger ? 'btn-danger' : 'btn-primary'}" data-ok>${esc(confirmText)}</button>`,
            });
            let answered = false;
            m.el.querySelector('[data-ok]').addEventListener('click', () => { answered = true; m.close(); resolve(true); });
            new MutationObserver((_, obs) => {
                if (!document.body.contains(m.el)) { obs.disconnect(); if (!answered) resolve(false); }
            }).observe(document.body, { childList: true });
            m.el.querySelector('[data-ok]').focus();
        });
    }

    // ── Comportamiento del layout ────────────────────────────────────────
    document.addEventListener('click', (e) => {
        if (e.target.closest('[data-toggle-sidebar]')) document.body.classList.toggle('sidebar-open');

        const trigger = e.target.closest('[data-dropdown]');
        document.querySelectorAll('.user-menu.open').forEach((m) => { if (!m.contains(e.target)) m.classList.remove('open'); });
        if (trigger) trigger.parentElement.classList.toggle('open');
    });

    // Mensajes flash de sesión (los deja el servidor en <body data-flash-*>).
    document.addEventListener('DOMContentLoaded', () => {
        const { flashSuccess, flashError } = document.body.dataset;
        if (flashSuccess) toast(flashSuccess, 'success');
        if (flashError) toast(flashError, 'error');

        // Archivos con data-max-bytes: se avisa antes de subir algo que el servidor rechazaría.
        document.querySelectorAll('input[type=file][data-max-bytes]').forEach((input) =>
            input.addEventListener('change', () => {
                const file = input.files[0];
                const max = Number(input.dataset.maxBytes);
                if (file && file.size > max) {
                    const mb = (n) => (n / 1048576).toLocaleString('es-CO', { maximumFractionDigits: 1 });
                    toast(`«${file.name}» pesa ${mb(file.size)} MB y el máximo es ${mb(max)} MB. Exporta solo el loteo como DXF o pide ampliar el límite.`, 'error');
                    input.value = '';
                }
            }));

        // Botones con data-loading: se desactivan al enviar su formulario (procesos que tardan).
        document.querySelectorAll('form button[data-loading]').forEach((btn) =>
            btn.form.addEventListener('submit', () => {
                btn.disabled = true;
                btn.innerHTML = `<i class="ph ph-circle-notch spin"></i> ${esc(btn.dataset.loading)}`;
            }));

        // Campos con data-uppercase se normalizan a mayúsculas mientras se escribe.
        document.querySelectorAll('[data-uppercase]').forEach((input) =>
            input.addEventListener('input', () => { input.value = input.value.toUpperCase(); }));
    });

    return { request, toast, confirm, modal, esc };
})();
