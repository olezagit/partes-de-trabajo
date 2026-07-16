/**
 * admin.js — Panel de asignación (solo grupo ADMINISTRACIÓN) + Filtros del listado
 * Se carga siempre pero solo actúa si window.PT_ES_ADMIN === true.
 */
'use strict';

// ════════════════════════════════════════════════════════════════════════════
// FILTROS DEL LISTADO
// ════════════════════════════════════════════════════════════════════════════

function initFiltros() {
    const filtroBar = document.getElementById('pt-filtro-bar');
    if (!filtroBar) return;

    filtroBar.addEventListener('change', aplicarFiltros);
    filtroBar.addEventListener('input',  aplicarFiltros);

    // Selector de empleado — recarga datos del servidor para ese técnico
    const ftEmpleado = document.getElementById('ft-empleado');
    if (ftEmpleado) {
        ftEmpleado.addEventListener('change', () => {
            const userId = parseInt(ftEmpleado.value, 10) || 0;
            if (typeof loadFromServer === 'function') {
                loadFromServer(userId);
            }
        });
    }

    // Botón limpiar — también resetea el filtro de empleado y recarga todos
    document.getElementById('pt-filtro-clear')?.addEventListener('click', () => {
        filtroBar.querySelectorAll('select, input').forEach(el => { el.value = ''; });
        if (ftEmpleado) ftEmpleado.value = '';
        aplicarFiltros();
        // Si había un filtro de empleado activo, recargar todos los partes
        if (typeof loadFromServer === 'function') loadFromServer(0);
    });
}

function aplicarFiltros() {
    const tipo   = document.getElementById('ft-tipo')?.value   || '';
    const estado = document.getElementById('ft-estado')?.value || '';
    // Buscador unificado: cliente, ref y ref_client
    const buscar = (document.getElementById('ft-buscar')?.value || '').toLowerCase().trim();

    document.querySelectorAll('#ptGrid .pt-card').forEach(card => {
        const cardTipo    = card.dataset.tipo    || '';
        const cardEstado  = card.dataset.estado  || '';
        // Buscar en los tres campos a la vez
        const cardCliente = (card.dataset.cliente || '').toLowerCase();
        const cardRef     = (card.dataset.ref     || '').toLowerCase();
        const cardRefCli  = (card.dataset.refcli  || '').toLowerCase();

        const okTipo   = !tipo   || cardTipo   === tipo;
        const okEstado = !estado || cardEstado === estado;
        const okBuscar = !buscar
            || cardCliente.includes(buscar)
            || cardRef.includes(buscar)
            || cardRefCli.includes(buscar);

        card.style.display = (okTipo && okEstado && okBuscar) ? '' : 'none';
    });

    // Actualizar contador
    const visibles = document.querySelectorAll('#ptGrid .pt-card:not([style*="none"])').length;
    const countNum = document.querySelector('.pt-count-num');
    const countLbl = document.querySelector('.pt-count-lbl');
    if (countNum) countNum.textContent = visibles;
    if (countLbl) countLbl.textContent = visibles === 1 ? 'parte' : 'partes';
}

// ════════════════════════════════════════════════════════════════════════════
// PANEL DE ASIGNACIÓN (solo ADMINISTRACIÓN)
// ════════════════════════════════════════════════════════════════════════════

let modalEl = null;
let usuariosCache = null;
let pedidosCache  = null;

function initAdmin() {
    if (!window.PT_ES_ADMIN) return;

    // Botón abrir panel de asignación
    const btnAsignar = document.getElementById('pt-btn-asignar');
    if (btnAsignar) {
        btnAsignar.addEventListener('click', abrirModal);
    }
}

async function abrirModal() {
    if (!modalEl) buildModal();
    modalEl.classList.add('pt-modal-open');
    document.body.style.overflow = 'hidden';
    await cargarDatosModal();
}

function cerrarModal() {
    if (modalEl) modalEl.classList.remove('pt-modal-open');
    document.body.style.overflow = '';
}

function buildModal() {
    modalEl = document.createElement('div');
    modalEl.className = 'pt-modal';
    modalEl.id = 'pt-modal-asignar';
    modalEl.innerHTML = `
    <div class="pt-modal-box">
        <div class="pt-modal-hdr">
            <span>👤 Asignar parte a técnico</span>
            <button class="pt-modal-close" id="pt-modal-close">✕</button>
        </div>
        <div class="pt-modal-body" id="pt-modal-body">
            <div class="pt-modal-loading">⏳ Cargando…</div>
        </div>
    </div>`;
    document.body.appendChild(modalEl);
    document.getElementById('pt-modal-close').addEventListener('click', cerrarModal);
    // Click fuera cierra
    modalEl.addEventListener('click', e => { if (e.target === modalEl) cerrarModal(); });
}

async function cargarDatosModal() {
    const body = document.getElementById('pt-modal-body');
    body.innerHTML = '<div class="pt-modal-loading">⏳ Cargando datos…</div>';

    try {
        // Cargar usuarios y pedidos en paralelo
        const [resU, resP] = await Promise.all([
            fetch(`${window.PT_BASE}/index.php?pt_do=usuarios`, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' }),
            fetch(`${window.PT_BASE}/index.php?pt_do=pedidos`,  { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' }),
        ]);
        const dataU = await resU.json();
        const dataP = await resP.json();
        usuariosCache = dataU.usuarios || [];
        pedidosCache  = dataP.pedidos  || [];
        renderModal();
    } catch (err) {
        body.innerHTML = `<div class="pt-modal-error">❌ Error cargando datos: ${err.message}</div>`;
    }
}

function buildTecnicosBadges(tecnicos, parteId) {
    if (!tecnicos.length) return '<span class="pt-asig-none">Sin asignar</span>';
    return tecnicos.map(t =>
        `<span class="pt-asig-badge">
            ${escHTML(t.nombre)}
            <button class="pt-asig-del" title="Eliminar asignación"
                data-parte="${parteId}" data-user="${t.id}">✕</button>
        </span>`
    ).join('');
}

function renderModal() {
    const body = document.getElementById('pt-modal-body');

    const optsUsuarios = usuariosCache.map(u =>
        `<option value="${u.id}">${escHTML(u.nombre)}</option>`
    ).join('');

    const filasPedidos = pedidosCache.map(p => {
        const urlDol = window.PT_BASE.replace('/custom/partes_trabajo','')
            + '/commande/card.php?id=' + p.rowid;
        return `
        <div class="pt-asig-row" data-id="${p.rowid}">
            <div class="pt-asig-info">
                <div class="pt-asig-ref">
                    ${escHTML(p.ref)}${p.ref_client ? ` <span class="pt-asig-refcli">· ${escHTML(p.ref_client)}</span>` : ''}
                    <a href="${escHTML(urlDol)}" target="_blank" class="pt-asig-link" title="Abrir en Dolibarr">🔗</a>
                </div>
                <div class="pt-asig-cliente">${escHTML(p.soc_nom)}</div>
                <div class="pt-asig-tipo">${escHTML(p.tipo_label || '')}</div>
                <div class="pt-asig-tecnicos" id="tec-${p.rowid}">${buildTecnicosBadges(p.tecnicos, p.rowid)}</div>
            </div>
            <div class="pt-asig-action">
                <select class="pt-asig-select" data-parte="${p.rowid}">
                    <option value="">— Añadir técnico —</option>
                    ${optsUsuarios}
                </select>
                <button class="pt-asig-btn" data-parte="${p.rowid}">Asignar</button>
            </div>
        </div>`;
    }).join('');

    body.innerHTML = `
    <input type="search" class="pt-asig-search" id="pt-asig-search"
        placeholder="🔍 Buscar parte o cliente…">
    <div class="pt-asig-list" id="pt-asig-list">
        ${filasPedidos || '<div class="pt-asig-none" style="padding:20px">No hay partes activos</div>'}
    </div>`;

    // Búsqueda en tiempo real
    document.getElementById('pt-asig-search')?.addEventListener('input', e => {
        const q = e.target.value.toLowerCase();
        document.querySelectorAll('.pt-asig-row').forEach(row => {
            row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
        });
    });

    // Delegar clicks en Asignar y en ✕ (eliminar)
    body.querySelector('.pt-asig-list').addEventListener('click', async e => {
        // ── Asignar ──────────────────────────────────────────────────────────
        const asigBtn = e.target.closest('.pt-asig-btn');
        if (asigBtn) {
            const parteId = parseInt(asigBtn.dataset.parte, 10);
            const sel     = body.querySelector(`.pt-asig-select[data-parte="${parteId}"]`);
            const userId  = parseInt(sel?.value, 10);
            if (!userId) { alert('Selecciona un técnico primero.'); return; }

            asigBtn.disabled = true; asigBtn.textContent = '⏳';
            try {
                const res  = await apiPost('asignar', { commande_id: parteId, user_id: userId });
                const data = await res.json();
                if (!res.ok || data.error) throw new Error(data.error || 'Error');

                // Actualizar pedidosCache
                const pc = pedidosCache.find(p => p.rowid === parteId);
                if (pc) pc.tecnicos.push({ id: userId, nombre: data.usuario });
                // Redibujar badges
                const tecDiv = body.querySelector(`#tec-${parteId}`);
                if (tecDiv) tecDiv.innerHTML = buildTecnicosBadges(pc?.tecnicos || [], parteId);

                asigBtn.textContent = '✅';
                setTimeout(() => { asigBtn.disabled = false; asigBtn.textContent = 'Asignar'; }, 2000);
                if (typeof loadFromServer === 'function') loadFromServer();
            } catch (err) {
                asigBtn.textContent = '❌';
                setTimeout(() => { asigBtn.disabled = false; asigBtn.textContent = 'Asignar'; }, 2000);
                alert('Error: ' + err.message);
            }
        }

        // ── Eliminar asignación ───────────────────────────────────────────────
        const delBtn = e.target.closest('.pt-asig-del');
        if (delBtn) {
            const parteId = parseInt(delBtn.dataset.parte, 10);
            const userId  = parseInt(delBtn.dataset.user,  10);
            if (!confirm('¿Eliminar la asignación de este técnico?')) return;

            delBtn.textContent = '⏳'; delBtn.disabled = true;
            try {
                const res  = await apiPost('desasignar', { commande_id: parteId, user_id: userId });
                const data = await res.json();
                if (!res.ok || data.error) throw new Error(data.error || 'Error');

                // Actualizar cache
                const pc = pedidosCache.find(p => p.rowid === parteId);
                if (pc) pc.tecnicos = pc.tecnicos.filter(t => t.id !== userId);
                // Redibujar badges
                const tecDiv = body.querySelector(`#tec-${parteId}`);
                if (tecDiv) tecDiv.innerHTML = buildTecnicosBadges(pc?.tecnicos || [], parteId);

                if (typeof loadFromServer === 'function') loadFromServer();
            } catch (err) {
                alert('Error al eliminar: ' + err.message);
                delBtn.textContent = '✕'; delBtn.disabled = false;
            }
        }
    });
}

async function apiPost(action, payload) {
    return fetch(`${window.PT_BASE}/index.php?pt_do=${action}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
        body: JSON.stringify(payload),
    });
}

function escHTML(str) {
    return String(str ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Init ──────────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    initFiltros();
    initAdmin();
});
