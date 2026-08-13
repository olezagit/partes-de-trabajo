/**
 * admin.js — Panel de asignación (solo grupo ADMINISTRACIÓN) + Filtros del listado
 * Se carga siempre pero solo actúa si window.PT_ES_ADMIN === true.
 */
'use strict';

// ════════════════════════════════════════════════════════════════════════════
// FILTROS DEL LISTADO
// ════════════════════════════════════════════════════════════════════════════

let tiposSeleccionados = new Set(); // Tipos marcados en el popover de checkboxes

function initFiltros() {
    const filtroBar = document.getElementById('pt-filtro-bar');
    if (!filtroBar) return;

    filtroBar.addEventListener('change', aplicarFiltros);
    filtroBar.addEventListener('input',  aplicarFiltros);

    // Campos de fecha: se conectan también de forma directa (no solo por delegación
    // desde el formulario) porque en algunos navegadores/dispositivos el evento
    // 'change'/'input' de un <input type="date"> no dispara fiablemente al padre,
    // sobre todo si el usuario escribe la fecha por partes (día/mes/año) en vez de
    // usar el selector nativo. 'blur' actúa como red de seguridad final.
    const ftFechaDesde = document.getElementById('ft-fecha-desde');
    const ftFechaHasta = document.getElementById('ft-fecha-hasta');
    [ftFechaDesde, ftFechaHasta].forEach(input => {
        if (!input) return;
        ['change', 'input', 'blur'].forEach(evt => {
            input.addEventListener(evt, aplicarFiltros);
        });
    });

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
    document.getElementById('pt-filtro-clear')?.addEventListener('click', limpiarTodosFiltros);

    initFiltroTipo();
    initSelect2Filtros();

    // Aplicar el filtro una vez al conectar, para que el rango de fechas por
    // defecto (1 de enero → hoy) actúe desde el primer render, sin esperar a
    // que el usuario toque ningún control.
    aplicarFiltros();
}

// ── Filtro Tipo: popover con checkboxes (selección múltiple) ────────────────

function initFiltroTipo() {
    const btn   = document.getElementById('ft-tipo-btn');
    const panel = document.getElementById('ft-tipo-panel');
    if (!btn || !panel) return;

    btn.addEventListener('click', e => {
        e.stopPropagation();
        panel.classList.toggle('pt-filtro-tipo-panel--open');
        btn.classList.toggle('pt-filtro-tipo-btn--open');
    });

    panel.addEventListener('change', e => {
        const chk = e.target.closest('.ft-tipo-check');
        if (!chk) return;
        if (chk.checked) tiposSeleccionados.add(chk.value);
        else tiposSeleccionados.delete(chk.value);
        actualizarBotonTipo();
        aplicarFiltros();
    });

    // No cerrar el popover al marcar/desmarcar dentro de él
    panel.addEventListener('click', e => e.stopPropagation());

    // Cerrar al tocar fuera
    document.addEventListener('click', () => {
        panel.classList.remove('pt-filtro-tipo-panel--open');
        btn.classList.remove('pt-filtro-tipo-btn--open');
    });
}

function actualizarBotonTipo() {
    const label = document.getElementById('ft-tipo-btn-label');
    if (!label) return;
    label.textContent = tiposSeleccionados.size > 0
        ? `🏷 Tipo (${tiposSeleccionados.size})`
        : '🏷 Tipo';
}

function limpiarFiltroTipo() {
    tiposSeleccionados.clear();
    document.querySelectorAll('.ft-tipo-check').forEach(chk => { chk.checked = false; });
    actualizarBotonTipo();
}

// ── Select2: combobox buscables para Tercero/Localidad ──────────────────────
// Autoalojado (no CDN) para que funcione también sin conexión. Si por lo que
// sea jQuery/Select2 no están disponibles, los <select> siguen funcionando
// igual que antes (con el picker nativo del navegador), solo sin la búsqueda.

function initSelect2Filtros() {
    if (typeof jQuery === 'undefined' || !jQuery.fn || !jQuery.fn.select2) return;
    const $ = jQuery;

    $('#ft-tercero').select2({
        placeholder: '🏢 Tercero',
        allowClear: true,
        width: 'resolve',
        language: 'es',
        dropdownAutoWidth: true,
    });
    $('#ft-localidad').select2({
        placeholder: '📍 Localidad',
        allowClear: true,
        width: 'resolve',
        language: 'es',
        dropdownAutoWidth: true,
    });
}

function limpiarTodosFiltros() {
    const filtroBar = document.getElementById('pt-filtro-bar');
    const ftEmpleado = document.getElementById('ft-empleado');
    filtroBar?.querySelectorAll('select, input').forEach(el => { el.value = ''; });

    // Select2 mantiene su propio estado visual: no basta con vaciar el <select>
    // nativo, hay que avisarle también a través de jQuery para que se sincronice.
    if (typeof jQuery !== 'undefined' && jQuery.fn && jQuery.fn.select2) {
        jQuery('#ft-tercero').val(null).trigger('change');
        jQuery('#ft-localidad').val(null).trigger('change');
    }

    limpiarFiltroTipo();

    // ft-orden no tiene opción vacía (solo asc/desc): fijar explícitamente el
    // valor por defecto, o quedaría sin ninguna opción visualmente seleccionada.
    const ftOrden = document.getElementById('ft-orden');
    if (ftOrden) ftOrden.value = 'desc';
    if (ftEmpleado) ftEmpleado.value = '';
    aplicarFiltros();
    // Si había un filtro de empleado activo, recargar todos los partes
    if (typeof loadFromServer === 'function') loadFromServer(0);
}

/**
 * Reordena físicamente las fichas del grid según su fecha (data-fecha, formato
 * ISO YYYY-MM-DD), ascendente o descendente. Se ejecuta antes de aplicar la
 * visibilidad de los filtros, así que afecta también a las fichas ocultas
 * (si luego se muestran, ya están en el orden correcto).
 */
function ordenarPorFecha(orden) {
    const grid = document.getElementById('ptGrid');
    if (!grid) return;
    const cards = Array.from(grid.querySelectorAll('.pt-card'));
    if (cards.length === 0) return;

    cards.sort((a, b) => {
        const fa = a.dataset.fecha || '';
        const fb = b.dataset.fecha || '';
        if (fa === fb) return 0;
        return orden === 'asc' ? (fa < fb ? -1 : 1) : (fa > fb ? -1 : 1);
    });
    // Reinsertar en el nuevo orden (appendChild sobre un nodo ya presente lo mueve, no lo duplica)
    cards.forEach(card => grid.appendChild(card));
}

function aplicarFiltros() {
    // Ordenar primero por fecha (Asc/Desc) reordenando el DOM; el filtrado de
    // visibilidad de abajo se aplica después, sobre el nuevo orden.
    ordenarPorFecha(document.getElementById('ft-orden')?.value || 'desc');

    const estado    = document.getElementById('ft-estado')?.value    || '';
    const tercero   = document.getElementById('ft-tercero')?.value   || '';
    const localidad = document.getElementById('ft-localidad')?.value || '';
    // Buscador unificado: cliente, ref y ref_client
    const buscar = (document.getElementById('ft-buscar')?.value || '').toLowerCase().trim();
    // Rango de fechas (formato ISO YYYY-MM-DD, comparable como texto)
    const fechaDesde = document.getElementById('ft-fecha-desde')?.value || '';
    const fechaHasta = document.getElementById('ft-fecha-hasta')?.value || '';

    document.querySelectorAll('#ptGrid .pt-card').forEach(card => {
        const cardTipo      = card.dataset.tipo      || '';
        const cardEstado    = card.dataset.estado    || '';
        const cardCliente   = card.dataset.cliente   || '';
        const cardLocalidad = card.dataset.localidad || '';
        // Buscar en los tres campos a la vez
        const cardClienteLower = cardCliente.toLowerCase();
        const cardRef     = (card.dataset.ref     || '').toLowerCase();
        const cardRefCli  = (card.dataset.refcli  || '').toLowerCase();
        const cardFecha   = card.dataset.fecha    || '';

        // Tipo: si no hay ninguno marcado, no se filtra por tipo (se ven todos);
        // si hay varios marcados, basta con que coincida con UNO de ellos.
        const okTipo      = tiposSeleccionados.size === 0 || tiposSeleccionados.has(cardTipo);
        const okEstado    = !estado    || cardEstado    === estado;
        const okTercero   = !tercero   || cardCliente   === tercero;
        const okLocalidad = !localidad || cardLocalidad === localidad;
        const okBuscar = !buscar
            || cardClienteLower.includes(buscar)
            || cardRef.includes(buscar)
            || cardRefCli.includes(buscar);
        const okFecha  = (!cardFecha)
            || ((!fechaDesde || cardFecha >= fechaDesde) && (!fechaHasta || cardFecha <= fechaHasta));

        card.style.display = (okTipo && okEstado && okTercero && okLocalidad && okBuscar && okFecha) ? '' : 'none';
    });

    // Actualizar contador
    const total    = document.querySelectorAll('#ptGrid .pt-card').length;
    const visibles = document.querySelectorAll('#ptGrid .pt-card:not([style*="none"])').length;
    const countNum = document.querySelector('.pt-count-num');
    const countLbl = document.querySelector('.pt-count-lbl');
    if (countNum) countNum.textContent = visibles;
    if (countLbl) countLbl.textContent = visibles === 1 ? 'parte' : 'partes';

    // Si HAY partes cargados pero los filtros los ocultan todos, avisar con
    // claridad (en vez de dejar la pantalla vacía sin explicación, que es
    // fácil de confundir con "el filtro no funciona" o "no hay datos").
    mostrarAvisoSinResultados(total > 0 && visibles === 0);
}

function mostrarAvisoSinResultados(mostrar) {
    const grid = document.getElementById('ptGrid');
    if (!grid) return;
    let aviso = document.getElementById('pt-filtro-sin-resultados');

    if (!mostrar) {
        aviso?.remove();
        return;
    }
    if (aviso) return; // ya está visible, no duplicar

    aviso = document.createElement('div');
    aviso.id = 'pt-filtro-sin-resultados';
    aviso.className = 'pt-filtro-sin-resultados';
    aviso.innerHTML = `
        <span class="pt-filtro-sin-resultados-ico">🔍</span>
        <span class="pt-filtro-sin-resultados-txt">Ningún parte coincide con los filtros actuales (revisa también el rango de fechas).</span>
        <button type="button" class="pt-filtro-sin-resultados-btn" id="pt-filtro-sin-resultados-btn">Quitar filtros</button>`;
    grid.parentNode.insertBefore(aviso, grid.nextSibling);
    document.getElementById('pt-filtro-sin-resultados-btn')
        ?.addEventListener('click', limpiarTodosFiltros);
}

// ════════════════════════════════════════════════════════════════════════════
// PANEL DE ASIGNACIÓN (solo ADMINISTRACIÓN)
// ════════════════════════════════════════════════════════════════════════════

let modalEl = null;
let usuariosCache = null;
let pedidosCache  = null;
let seleccionados = new Set(); // IDs de pedidos marcados para asignación en bloque

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
            <span>👤 Asignar partes a técnico</span>
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
    seleccionados.clear(); // cada apertura del panel empieza con la selección vacía

    try {
        // Cargar usuarios y pedidos en paralelo
        const [resU, resP] = await Promise.all([
            fetch(`${window.PT_BASE}/index.php?pt_do=usuarios`, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' }),
            fetch(`${window.PT_BASE}/index.php?pt_do=pedidos`,  { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' }),
        ]);
        const dataU = await resU.json();
        const dataP = await resP.json();
        if (dataU.error) throw new Error(dataU.error);
        if (dataP.error) throw new Error(dataP.error);

        usuariosCache = dataU.usuarios || [];
        pedidosCache  = dataP.pedidos  || [];

        // Guardar copia local para poder abrir el panel sin conexión
        if (typeof PtDB !== 'undefined') {
            PtDB.setMeta('admin_usuarios_cache', usuariosCache).catch(() => {});
            PtDB.setMeta('admin_pedidos_cache',  pedidosCache).catch(() => {});
        }

        renderModal();
    } catch (err) {
        // Sin conexión (u otro fallo de red): recuperar la última copia guardada localmente
        const cache = await cargarDesdeCacheLocal();
        if (cache) {
            usuariosCache = cache.usuarios;
            pedidosCache  = cache.pedidos;
            renderModal();
            const nota = document.createElement('div');
            nota.className = 'pt-asig-offline-note';
            nota.innerHTML = '📴 <strong>Sin conexión</strong> — mostrando la última lista guardada. '
                + 'Las asignaciones se guardarán en el dispositivo y se enviarán al recuperar la red.';
            body.prepend(nota);
        } else {
            body.innerHTML = `<div class="pt-modal-error">❌ Error cargando datos: ${err.message}</div>`;
        }
    }
}

async function cargarDesdeCacheLocal() {
    if (typeof PtDB === 'undefined') return null;
    try {
        const usuarios = await PtDB.getMeta('admin_usuarios_cache');
        const pedidos  = await PtDB.getMeta('admin_pedidos_cache');
        if (!usuarios || !pedidos) return null;
        return { usuarios, pedidos };
    } catch {
        return null;
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
        const marcado = seleccionados.has(p.rowid);
        return `
        <div class="pt-asig-row${marcado ? ' pt-asig-row--sel' : ''}" data-id="${p.rowid}">
            <label class="pt-asig-checkwrap" title="Seleccionar para asignación en bloque">
                <input type="checkbox" class="pt-asig-check" data-parte="${p.rowid}"${marcado ? ' checked' : ''}>
            </label>
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
    <div class="pt-asig-layout">
        <div class="pt-asig-selected-col" id="pt-asig-selected-col">
            <div class="pt-asig-sel-hdr">
                <span>✅ Seleccionados</span>
                <span class="pt-asig-sel-count" id="pt-asig-sel-count">0</span>
            </div>
            <div class="pt-asig-sel-list" id="pt-asig-sel-list">
                <div class="pt-asig-sel-empty">Marca partes en la lista para añadirlos aquí.</div>
            </div>
            <div class="pt-asig-bulk">
                <select class="pt-asig-bulk-select" id="pt-asig-bulk-select">
                    <option value="">— Técnico —</option>
                    ${optsUsuarios}
                </select>
                <button class="pt-asig-bulk-btn" id="pt-asig-bulk-btn" disabled>Asignar seleccionados</button>
                <button type="button" class="pt-asig-bulk-clear" id="pt-asig-bulk-clear" title="Vaciar selección">Vaciar</button>
            </div>
        </div>
        <div class="pt-asig-main">
            <div class="pt-asig-toolbar">
                <input type="search" class="pt-asig-search" id="pt-asig-search"
                    placeholder="🔍 Buscar parte o cliente…">
                <button type="button" class="pt-asig-selall" id="pt-asig-selall">Seleccionar visibles</button>
            </div>
            <div class="pt-asig-list" id="pt-asig-list">
                ${filasPedidos || '<div class="pt-asig-none" style="padding:20px">No hay partes activos</div>'}
            </div>
        </div>
    </div>`;

    const listEl = document.getElementById('pt-asig-list');

    // Búsqueda en tiempo real (la selección no se pierde al filtrar: se guarda por ID, no por fila visible)
    document.getElementById('pt-asig-search')?.addEventListener('input', e => {
        const q = e.target.value.toLowerCase();
        document.querySelectorAll('.pt-asig-row').forEach(row => {
            row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
        });
    });

    // Marcar/desmarcar una fila individual
    listEl.addEventListener('change', e => {
        const chk = e.target.closest('.pt-asig-check');
        if (!chk) return;
        const parteId = parseInt(chk.dataset.parte, 10);
        if (chk.checked) seleccionados.add(parteId); else seleccionados.delete(parteId);
        chk.closest('.pt-asig-row')?.classList.toggle('pt-asig-row--sel', chk.checked);
        renderSeleccionados();
    });

    // Tocar el área de info de la fila también selecciona (más cómodo en móvil),
    // sin interferir con el enlace a Dolibarr ni con los controles de asignación individual.
    listEl.addEventListener('click', e => {
        if (e.target.closest('.pt-asig-link, .pt-asig-check, .pt-asig-select, .pt-asig-btn, .pt-asig-del')) return;
        const infoArea = e.target.closest('.pt-asig-info');
        if (!infoArea) return;
        const chk = infoArea.closest('.pt-asig-row')?.querySelector('.pt-asig-check');
        if (chk) { chk.checked = !chk.checked; chk.dispatchEvent(new Event('change', { bubbles: true })); }
    });

    // Seleccionar todos los partes actualmente visibles (respeta el filtro de búsqueda activo)
    document.getElementById('pt-asig-selall')?.addEventListener('click', () => {
        listEl.querySelectorAll('.pt-asig-row').forEach(row => {
            if (row.style.display === 'none') return;
            const parteId = parseInt(row.dataset.id, 10);
            seleccionados.add(parteId);
            row.classList.add('pt-asig-row--sel');
            const chk = row.querySelector('.pt-asig-check');
            if (chk) chk.checked = true;
        });
        renderSeleccionados();
    });

    // Vaciar la selección completa
    document.getElementById('pt-asig-bulk-clear')?.addEventListener('click', () => {
        seleccionados.clear();
        listEl.querySelectorAll('.pt-asig-check').forEach(chk => { chk.checked = false; });
        listEl.querySelectorAll('.pt-asig-row--sel').forEach(row => row.classList.remove('pt-asig-row--sel'));
        renderSeleccionados();
    });

    // Quitar un parte concreto desde la columna de seleccionados
    document.getElementById('pt-asig-sel-list')?.addEventListener('click', e => {
        const rm = e.target.closest('.pt-asig-sel-del');
        if (!rm) return;
        const parteId = parseInt(rm.dataset.parte, 10);
        seleccionados.delete(parteId);
        const row = listEl.querySelector(`.pt-asig-row[data-id="${parteId}"]`);
        if (row) {
            row.classList.remove('pt-asig-row--sel');
            const chk = row.querySelector('.pt-asig-check');
            if (chk) chk.checked = false;
        }
        renderSeleccionados();
    });

    // Habilitar/deshabilitar el botón de asignación en bloque según técnico elegido
    document.getElementById('pt-asig-bulk-select')?.addEventListener('change', renderSeleccionados);

    // Asignar todos los seleccionados al técnico elegido
    document.getElementById('pt-asig-bulk-btn')?.addEventListener('click', bulkAsignar);

    // Delegar clicks en Asignar y en ✕ (eliminar) — asignación/desasignación individual por fila
    listEl.addEventListener('click', async e => {
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

    // Estado inicial del panel de seleccionados (contador, botón, etc.)
    renderSeleccionados();
}

// ════════════════════════════════════════════════════════════════════════════
// COLUMNA "SELECCIONADOS" + ASIGNACIÓN EN BLOQUE
// ════════════════════════════════════════════════════════════════════════════

/**
 * Redibuja la columna de "Seleccionados" (contador, chips, estado del botón)
 * a partir del Set `seleccionados`. No toca el listado filtrable.
 */
function renderSeleccionados() {
    const listEl  = document.getElementById('pt-asig-sel-list');
    const countEl = document.getElementById('pt-asig-sel-count');
    const btn     = document.getElementById('pt-asig-bulk-btn');
    if (!listEl || !countEl) return;

    countEl.textContent = seleccionados.size;

    if (seleccionados.size === 0) {
        listEl.innerHTML = '<div class="pt-asig-sel-empty">Marca partes en la lista para añadirlos aquí.</div>';
    } else {
        listEl.innerHTML = Array.from(seleccionados).map(id => {
            const p = (pedidosCache || []).find(x => x.rowid === id);
            if (!p) return '';
            return `<div class="pt-asig-chip" data-parte="${id}">
                <span class="pt-asig-chip-ref">${escHTML(p.ref)}</span>
                <span class="pt-asig-chip-cli">${escHTML(p.soc_nom)}</span>
                <button type="button" class="pt-asig-sel-del" data-parte="${id}" title="Quitar de la selección">✕</button>
            </div>`;
        }).join('');
    }

    const bulkSelect = document.getElementById('pt-asig-bulk-select');
    const tieneTecnico = !!(bulkSelect && bulkSelect.value);
    if (btn) {
        btn.disabled = seleccionados.size === 0 || !tieneTecnico;
        btn.textContent = seleccionados.size > 0
            ? `Asignar a ${seleccionados.size} seleccionado${seleccionados.size > 1 ? 's' : ''}`
            : 'Asignar seleccionados';
    }
}

/** Limpia visualmente la selección (checkboxes, resaltado de filas y Set). */
function limpiarSeleccionUI() {
    seleccionados.clear();
    const listEl = document.getElementById('pt-asig-list');
    listEl?.querySelectorAll('.pt-asig-check').forEach(chk => { chk.checked = false; });
    listEl?.querySelectorAll('.pt-asig-row--sel').forEach(row => row.classList.remove('pt-asig-row--sel'));
    renderSeleccionados();
}

/** Añade (sin duplicar) un técnico a la caché local de un pedido. */
function aplicarAsignacionLocal(parteId, userId, nombreTecnico) {
    const pc = (pedidosCache || []).find(p => p.rowid === parteId);
    if (!pc) return;
    if (!pc.tecnicos.some(t => t.id === userId)) {
        pc.tecnicos.push({ id: userId, nombre: nombreTecnico });
    }
}

/**
 * Asigna todos los partes marcados en `seleccionados` al técnico elegido
 * en el desplegable de la columna de seleccionados. Funciona también sin
 * conexión: en ese caso encola cada asignación como acción offline (igual
 * que el resto del módulo) para enviarla en cuanto vuelva la red.
 */
async function bulkAsignar() {
    const bulkSelect = document.getElementById('pt-asig-bulk-select');
    const userId = parseInt(bulkSelect?.value, 10);
    const btn = document.getElementById('pt-asig-bulk-btn');
    if (!userId) { alert('Selecciona un técnico primero.'); return; }

    const ids = Array.from(seleccionados);
    if (ids.length === 0) return;

    const tecnico   = (usuariosCache || []).find(u => u.id === userId);
    const nombreTec = tecnico ? tecnico.nombre : ('Usuario ' + userId);
    const offline   = !navigator.onLine;

    const originalText = btn.textContent;
    btn.disabled = true;
    btn.textContent = '⏳ Asignando…';

    let ok = 0, fail = 0;

    if (offline) {
        // Sin conexión: guardar cada asignación en IndexedDB para sincronizar después.
        for (const parteId of ids) {
            try {
                if (typeof window.ptSaveAccionOffline === 'function') {
                    await window.ptSaveAccionOffline(
                        'asignar', parteId, { commande_id: parteId, user_id: userId },
                        `${window.PT_BASE}/index.php?pt_do=asignar`
                    );
                } else if (typeof PtDB !== 'undefined') {
                    await PtDB.saveAccion({
                        tipo: 'asignar', parte_id: parteId,
                        payload: { commande_id: parteId, user_id: userId },
                        url: `${window.PT_BASE}/index.php?pt_do=asignar`, method: 'POST'
                    });
                }
                aplicarAsignacionLocal(parteId, userId, nombreTec + ' ⏳');
                ok++;
            } catch {
                fail++;
            }
        }
    } else {
        // Con conexión: enviar todas las asignaciones en paralelo.
        const resultados = await Promise.allSettled(ids.map(parteId =>
            apiPost('asignar', { commande_id: parteId, user_id: userId }).then(async res => {
                const data = await res.json();
                if (!res.ok || data.error) throw new Error(data.error || 'Error');
                return { parteId, nombre: data.usuario || nombreTec };
            })
        ));
        resultados.forEach((r, i) => {
            if (r.status === 'fulfilled') {
                aplicarAsignacionLocal(r.value.parteId, userId, r.value.nombre);
                ok++;
            } else {
                fail++;
            }
        });
    }

    // Redibujar las insignias de técnico de cada fila afectada
    ids.forEach(parteId => {
        const pc = (pedidosCache || []).find(p => p.rowid === parteId);
        const tecDiv = document.getElementById(`tec-${parteId}`);
        if (tecDiv) tecDiv.innerHTML = buildTecnicosBadges(pc?.tecnicos || [], parteId);
    });

    limpiarSeleccionUI();
    btn.disabled = false;
    btn.textContent = originalText;

    const notificar = typeof window.showToast === 'function'
        ? (msg, type) => window.showToast(msg, type)
        : (msg) => alert(msg);

    if (offline) {
        notificar(`💾 ${ok} parte(s) guardados para asignar a ${nombreTec} en cuanto haya conexión.`, 'info');
    } else if (fail === 0) {
        notificar(`✅ ${ok} parte(s) asignado(s) a ${nombreTec}`, 'success');
    } else {
        notificar(`⚠️ ${ok} asignado(s), ${fail} con error. Revisa e inténtalo de nuevo.`, 'error');
    }

    if (typeof loadFromServer === 'function') loadFromServer();
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
