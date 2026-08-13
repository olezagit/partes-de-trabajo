/**
 * app.js — Lógica PWA principal de Partes de Trabajo
 *
 * Responsabilidades:
 *  1. Registrar el Service Worker
 *  2. Mostrar banner de estado de red (online/offline)
 *  3. Mostrar contador de acciones pendientes
 *  4. Cuando hay red: cargar datos frescos de la API y guardarlos en IDB
 *  5. Cuando no hay red: renderizar desde IDB
 *  6. Intentar sincronizar acciones pendientes al recuperar conexión
 *  7. Mostrar prompt de instalación (Add to Home Screen)
 */

'use strict';

// ── Configuración ─────────────────────────────────────────────────────────────
const PT_API_URL = window.PT_API_URL || (window.PT_BASE + '/index.php?pt_do=partes');
const PT_BASE    = window.PT_BASE    || '/custom/partes_trabajo';

// ── Tipos de parte — clave numérica igual que ef.tipo_albaran en BD ──────────
// Sincronizado con el array $tipos de index.php y api/partes.php
const TIPOS = {
    1: { label: 'Parte de trabajo', color: '#2563EB', bg: '#DBEAFE', icon: '🔧' },
    2: { label: 'Puesta en marcha', color: '#16A34A', bg: '#DCFCE7', icon: '▶️' },
    3: { label: 'Avería',           color: '#DC2626', bg: '#FEE2E2', icon: '⚠️' },
    4: { label: 'Mantenimiento',    color: '#D97706', bg: '#FEF3C7', icon: '🛠️' },
};

// Todos los estados posibles de llx_commande
// "En proceso" no es un fk_statut propio — es un estado visual calculado:
// statut=1 (Validado) + firma del técnico SÍ + firma del cliente NO (ver p.en_proceso)
const STATUS_LABELS = {
    '-1': { label: 'Cancelado', color: '#DC2626' },
    '0':  { label: 'Borrador',  color: '#94A3B8' },
    '1':  { label: 'Validado',  color: '#2563EB' },
    '2':  { label: 'En proceso', color: '#16A34A' },
    '3':  { label: 'Terminado', color: '#7C3AED' },
};
const EN_PROCESO_LABEL = { label: 'En proceso', color: '#16A34A' };

// ════════════════════════════════════════════════════════════════════════════════
// 1. Service Worker
// ════════════════════════════════════════════════════════════════════════════════

async function registerSW() {
    // SW desactivado: el dominio ya tiene otro SW activo (Dolibarr u otro modulo)
    // que puede entrar en conflicto. El modo offline usa solo IndexedDB.
    if (!('serviceWorker' in navigator)) return;

    // Desregistrar cualquier SW previo de este modulo para evitar conflictos
    try {
        const registrations = await navigator.serviceWorker.getRegistrations();
        for (const reg of registrations) {
            if (reg.scope && reg.scope.includes('partes_trabajo')) {
                await reg.unregister();
                console.log('[PT] SW anterior desregistrado:', reg.scope);
            }
        }
    } catch (err) {
        console.warn('[PT] No se pudo desregistrar SW:', err);
    }
}

// ════════════════════════════════════════════════════════════════════════════════
// 2. Banner de estado de red
// ════════════════════════════════════════════════════════════════════════════════

function showBanner(state) {
    let banner = document.getElementById('pt-net-banner');
    if (!banner) {
        banner = document.createElement('div');
        banner.id = 'pt-net-banner';
        document.body.prepend(banner);
    }

    if (state === 'offline') {
        banner.className = 'pt-banner pt-banner--offline';
        banner.innerHTML = '📴 <strong>Sin conexión</strong> — Mostrando datos guardados. Las acciones se enviarán al recuperar la red.';
        banner.style.display = 'flex';
    } else if (state === 'online') {
        banner.className = 'pt-banner pt-banner--online';
        banner.innerHTML = '✅ <strong>Conexión restaurada</strong> — Sincronizando datos…';
        banner.style.display = 'flex';
        setTimeout(() => { banner.style.display = 'none'; }, 3500);
    } else {
        banner.style.display = 'none';
    }
}

function updateNetworkStatus() {
    if (!navigator.onLine) {
        showBanner('offline');
        loadFromCache();
    } else {
        showBanner('hide');
    }
}

window.addEventListener('online', () => {
    showBanner('online');
    loadFromServer();      // refrescar datos
    flushPendingActions(); // sincronizar acciones pendientes
});

window.addEventListener('offline', () => {
    showBanner('offline');
});

// ════════════════════════════════════════════════════════════════════════════════
// 3. Contador de acciones pendientes
// ════════════════════════════════════════════════════════════════════════════════

async function updatePendingBadge() {
    const count  = await PtDB.countAccionesPendientes();
    let   badge  = document.getElementById('pt-pending-badge');

    if (count === 0) {
        if (badge) badge.style.display = 'none';
        return;
    }

    if (!badge) {
        badge = document.createElement('div');
        badge.id = 'pt-pending-badge';
        badge.className = 'pt-pending-badge';
        document.querySelector('.pt-header-inner')?.appendChild(badge);
    }

    badge.style.display = 'flex';
    badge.innerHTML = `<span class="pt-pb-icon">🔄</span> <span>${count} pendiente${count > 1 ? 's' : ''}</span>`;
    badge.title = `${count} acción(es) sin sincronizar`;
}

// ════════════════════════════════════════════════════════════════════════════════
// 4 & 5. Carga de datos: servidor o caché
// ════════════════════════════════════════════════════════════════════════════════

async function loadFromServer(userFilter = 0) {
    try {
        // Construir URL con user_filter si se especifica
        const suffix = userFilter > 0 ? '&user_filter=' + userFilter : '';
        const urls = [
            PT_API_URL + suffix,
            window.PT_BASE + '/ajax.php?action=partes' + suffix,
        ];
        let res = null;
        for (const url of urls) {
            try {
                const r = await fetch(url, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                    cache: 'no-store'
                });
                if (r.ok) { res = r; break; }
                console.warn('[PT] ' + url + ' → HTTP ' + r.status);
            } catch(e) {
                console.warn('[PT] Falló ' + url + ':', e.message);
            }
        }
        if (!res) throw new Error('Todas las URLs fallaron');

        const data = await res.json();

        if (data.error) throw new Error(data.error);

        // Guardar en IndexedDB
        await PtDB.savePartes(data.partes || []);

        renderPartes(data.partes || [], false);
        updateLastSync();

        // Reaplicar filtros activos: renderPartes reconstruye el grid entero
        // y resetea el display de las fichas, por lo que el filtro visual se
        // pierde aunque el texto siga escrito en la caja de búsqueda.
        if (typeof window.aplicarFiltros === 'function') window.aplicarFiltros();

    } catch (err) {
        console.warn('[PT] Error al cargar del servidor:', err);
        // Fallback a caché
        loadFromCache();
    }
}

async function loadFromCache() {
    const partes = await PtDB.getPartes();
    if (partes.length > 0) {
        renderPartes(partes, true);
    } else {
        renderEmpty(true);
    }
    if (typeof window.aplicarFiltros === 'function') window.aplicarFiltros();
}

async function refreshFromCache() {
    const partes = await PtDB.getPartes();
    renderPartes(partes, !navigator.onLine);
    if (typeof window.aplicarFiltros === 'function') window.aplicarFiltros();
}

async function updateLastSync() {
    const ts = await PtDB.getMeta('lastSync');
    const el = document.getElementById('pt-last-sync');
    if (el && ts) {
        const d = new Date(ts);
        el.textContent = `Actualizado: ${d.toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit' })}`;
    }
}

// ════════════════════════════════════════════════════════════════════════════════
// Renderizado de fichas (modo dinámico desde JS, para funcionar offline)
// ════════════════════════════════════════════════════════════════════════════════

function renderPartes(partes, fromCache) {
    const grid = document.getElementById('ptGrid');
    const emptyEl = document.getElementById('ptEmpty');
    if (!grid) return;

    // Actualizar contador en cabecera
    const countNum = document.querySelector('.pt-count-num');
    const countLbl = document.querySelector('.pt-count-lbl');
    if (countNum) countNum.textContent = partes.length;
    if (countLbl) countLbl.textContent = partes.length === 1 ? 'parte' : 'partes';

    if (partes.length === 0) {
        grid.innerHTML = '';
        grid.style.display = 'none';
        if (emptyEl) emptyEl.style.display = 'flex';
        return;
    }

    if (emptyEl) emptyEl.style.display = 'none';
    grid.style.display = '';

    grid.innerHTML = partes.map(p => buildCardHTML(p)).join('');

    // Indicador de datos de caché
    const cacheNote = document.getElementById('pt-cache-note');
    if (cacheNote) cacheNote.style.display = fromCache ? 'block' : 'none';

    // Reconectar filtros
    wireFilters();
}

function buildCardHTML(p) {
    // La API ya resuelve tipo_label/tipo_color/tipo_bg/tipo_icon.
    // Si los datos vienen de IndexedDB (guardados por savePartes) también los tienen.
    // Fallback al mapa local TIPOS por si el objeto viene de un caché antiguo.
    const tipoId   = parseInt(p.tipo_albaran, 10) || 0;
    const tipoFb   = TIPOS[tipoId] || { label: 'Tipo ' + tipoId, color: '#6B7280', bg: '#F3F4F6', icon: '📄' };
    const tipoInfo = {
        label: p.tipo_label || tipoFb.label,
        color: p.tipo_color || tipoFb.color,
        bg:    p.tipo_bg    || tipoFb.bg,
        icon:  p.tipo_icon  || tipoFb.icon,
    };

    const stInfo = p.en_proceso
        ? EN_PROCESO_LABEL
        : (STATUS_LABELS[String(p.statut)] || { label: 'Estado ' + p.statut, color: '#6B7280' });
    const statutVisual = p.en_proceso ? 2 : p.statut;

    // Dirección: sede del pedido si existe, si no la central del cliente
    const direccion = p.dep_address || p.soc_address || '';
    const zip       = p.dep_address ? (p.dep_zip  || '') : (p.soc_zip  || '');
    const town      = p.dep_address ? (p.dep_town || '') : (p.soc_town || '');
    const localidad = (zip + ' ' + town).trim();

    // date_commande viene como timestamp Unix (segundos) desde la API
    const fecha = p.date_commande
        ? new Date(p.date_commande * 1000).toLocaleDateString('es-ES')
        : '—';
    const fechaISO = p.date_commande
        ? new Date(p.date_commande * 1000).toISOString().slice(0, 10)
        : '';

    const url = p.url || `/commande/card.php?id=${p.rowid}`;

    return `
    <div class="pt-card" role="button" tabindex="0" data-tipo="${escapeHTML(tipoInfo.label)}" data-estado="${escapeHTML(stInfo.label)}" data-statut="${statutVisual}" data-cliente="${escapeHTML(p.soc_nom||"")}" data-ref="${escapeHTML(p.ref||"")}" data-refcli="${escapeHTML(p.ref_client||"")}" data-fecha="${fechaISO}" data-direccion="${escapeHTML(direccion)}" data-localidad="${escapeHTML(localidad)}" data-id="${p.rowid}" data-firmado="${p.firmado ? '1' : '0'}" data-url="${escapeHTML(url)}">
      <div class="pt-card-top">
        <span class="pt-tipo-badge" style="color:${tipoInfo.color};background:${tipoInfo.bg}">
          ${tipoInfo.icon} ${escapeHTML(tipoInfo.label)}
        </span>
        <span class="pt-status-dot" style="background:${stInfo.color}" title="${escapeHTML(stInfo.label)}"></span>
        ${p.firmado ? '<span class="pt-firmado-badge">✍ Firmado</span>' : ''}
      </div>
      <div class="pt-card-ref">${escapeHTML(p.ref)}</div>
      ${p.ref_client ? `<div class="pt-card-refcli">Ref. cliente: ${escapeHTML(p.ref_client)}</div>` : ""}
      <div class="pt-card-cliente">
        <span class="pt-card-icon">🏢</span>
        <span>${escapeHTML(p.soc_nom || '')}</span>
      </div>
      ${direccion ? `<div class="pt-card-dir"><span class="pt-card-icon">📍</span><span>${escapeHTML(direccion)}</span></div>` : ''}
      ${localidad ? `<div class="pt-card-loc"><span class="pt-loc-text">${escapeHTML(localidad)}</span></div>` : ''}
      <div class="pt-card-footer">
        <span class="pt-fecha">📅 ${fecha}</span>
        <span class="pt-status-label" style="color:${stInfo.color}">${escapeHTML(stInfo.label)}</span>
      </div>
      <div class="pt-card-arrow">→</div>
    </div>`;
}

function renderEmpty(fromCache) {
    const grid    = document.getElementById('ptGrid');
    const emptyEl = document.getElementById('ptEmpty');
    if (grid)    { grid.innerHTML = ''; grid.style.display = 'none'; }
    if (emptyEl) {
        emptyEl.style.display = 'flex';
        if (fromCache) {
            const sub = emptyEl.querySelector('.pt-empty-sub');
            if (sub) sub.textContent = 'No hay datos guardados. Conéctate a internet para cargar tus partes.';
        }
    }
}

// ════════════════════════════════════════════════════════════════════════════════
// 6. Sincronización de acciones pendientes
// ════════════════════════════════════════════════════════════════════════════════

async function flushPendingActions() {
    const pendientes = await PtDB.getAccionesPendientes();
    if (pendientes.length === 0) return;

    showToast(`🔄 Sincronizando ${pendientes.length} acción(es) pendiente(s)…`, 'info');

    for (const accion of pendientes) {
        try {
            await PtDB.updateAccionEstado(accion.id, 'enviando');

            const res = await fetch(accion.url, {
                method:  accion.method || 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body:    typeof accion.payload === 'string' ? accion.payload : JSON.stringify(accion.payload)
            });

            if (res.ok) {
                await PtDB.updateAccionEstado(accion.id, 'ok');
                await PtDB.deleteAccion(accion.id);
                showToast('✅ Acción sincronizada correctamente', 'success');
            } else {
                await PtDB.updateAccionEstado(accion.id, 'error');
            }
        } catch {
            await PtDB.updateAccionEstado(accion.id, 'pendiente');
        }
    }

    await updatePendingBadge();
    // Recargar datos frescos tras sincronizar
    loadFromServer();
}

/**
 * Guardar una acción para sincronizar después.
 * Llamar desde cualquier parte del código que haga cambios en un parte.
 */
window.ptSaveAccionOffline = async function(tipo, parteId, payload, url, method = 'POST') {
    await PtDB.saveAccion({ tipo, parte_id: parteId, payload, url, method });
    await updatePendingBadge();
    showToast('💾 Guardado localmente. Se enviará al recuperar la conexión.', 'info');
};

// ════════════════════════════════════════════════════════════════════════════════
// 7. Prompt de instalación (Add to Home Screen)
// ════════════════════════════════════════════════════════════════════════════════

let deferredInstallPrompt = null;

window.addEventListener('beforeinstallprompt', e => {
    e.preventDefault();
    deferredInstallPrompt = e;

    // Mostrar botón de instalación si no está instalado
    const already = localStorage.getItem('pt-installed');
    if (!already) showInstallBanner();
});

window.addEventListener('appinstalled', () => {
    localStorage.setItem('pt-installed', '1');
    hideInstallBanner();
    showToast('📱 App instalada. Ahora funciona offline.', 'success');
});

function showInstallBanner() {
    if (document.getElementById('pt-install-banner')) return;

    const el = document.createElement('div');
    el.id = 'pt-install-banner';
    el.className = 'pt-install-banner';
    el.innerHTML = `
        <div class="pt-ib-content">
            <span class="pt-ib-icon">📲</span>
            <div>
                <strong>Instalar en tu móvil</strong>
                <p>Accede offline y sin abrir el navegador</p>
            </div>
        </div>
        <div class="pt-ib-actions">
            <button id="pt-ib-install" class="pt-ib-btn pt-ib-btn--primary">Instalar</button>
            <button id="pt-ib-dismiss" class="pt-ib-btn">Ahora no</button>
        </div>`;

    document.body.appendChild(el);
    // Animar entrada
    requestAnimationFrame(() => el.classList.add('pt-install-banner--visible'));

    document.getElementById('pt-ib-install').addEventListener('click', async () => {
        if (!deferredInstallPrompt) return;
        deferredInstallPrompt.prompt();
        const { outcome } = await deferredInstallPrompt.userChoice;
        if (outcome === 'accepted') localStorage.setItem('pt-installed', '1');
        deferredInstallPrompt = null;
        hideInstallBanner();
    });

    document.getElementById('pt-ib-dismiss').addEventListener('click', () => {
        localStorage.setItem('pt-install-dismissed', Date.now());
        hideInstallBanner();
    });
}

function hideInstallBanner() {
    const el = document.getElementById('pt-install-banner');
    if (!el) return;
    el.classList.remove('pt-install-banner--visible');
    setTimeout(() => el.remove(), 350);
}

// ════════════════════════════════════════════════════════════════════════════════
// Filtros de tipo
// ════════════════════════════════════════════════════════════════════════════════

function wireFilters() {
    const btns  = document.querySelectorAll('.pt-filter-btn');
    const cards = document.querySelectorAll('.pt-card');
    btns.forEach(btn => {
        btn.addEventListener('click', () => {
            btns.forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            const f = btn.dataset.filter;
            cards.forEach(c => {
                c.style.display = (f === 'all' || c.dataset.tipo === f) ? '' : 'none';
            });
        });
    });
}

// ════════════════════════════════════════════════════════════════════════════════
// Toast notifications
// ════════════════════════════════════════════════════════════════════════════════

function showToast(msg, type = 'info', duration = 4000) {
    let container = document.getElementById('pt-toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'pt-toast-container';
        document.body.appendChild(container);
    }

    const toast = document.createElement('div');
    toast.className = `pt-toast pt-toast--${type}`;
    toast.textContent = msg;
    container.appendChild(toast);

    requestAnimationFrame(() => toast.classList.add('pt-toast--visible'));

    setTimeout(() => {
        toast.classList.remove('pt-toast--visible');
        setTimeout(() => toast.remove(), 300);
    }, duration);
}

// ════════════════════════════════════════════════════════════════════════════════
// Utilidades
// ════════════════════════════════════════════════════════════════════════════════

function escapeHTML(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

// ════════════════════════════════════════════════════════════════════════════════
// Init
// ════════════════════════════════════════════════════════════════════════════════

document.addEventListener('DOMContentLoaded', async () => {
    await registerSW();

    if (navigator.onLine) {
        await loadFromServer();
        await flushPendingActions();
    } else {
        showBanner('offline');
        await loadFromCache();
    }

    await updatePendingBadge();
    wireFilters();

    // Recargar al volver a la pestaña (por si estaba en segundo plano)
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden && navigator.onLine) loadFromServer();
    });
});
