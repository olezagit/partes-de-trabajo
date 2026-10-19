/**
 * js/estadisticas.js — Panel de detalle por técnico (admin/estadisticas.php)
 * Al hacer clic en una fila de la tabla "Por técnico", abre un modal con el
 * desglose completo de ese técnico: KPIs, por estado, por tipo y el listado
 * de cada albarán con sus horas trabajadas.
 */
'use strict';

let peModalEl = null;

function peInit() {
    peInitTablasOrdenables();

    const tabla = document.querySelector('.pe-tabla--tecnicos');
    if (!tabla) return; // no hay técnicos en el grupo: nada que conectar

    tabla.addEventListener('click', e => {
        if (e.target.closest('thead')) return; // los títulos ordenan, no abren detalle
        const row = e.target.closest('.pe-tec-row');
        if (row) peAbrirDetalle(row.dataset.tecnicoId, row.dataset.tecnicoNombre);
    });
    // Accesibilidad: Enter/Espacio también abre el detalle (las filas llevan tabindex+role=button)
    tabla.addEventListener('keydown', e => {
        if (e.key !== 'Enter' && e.key !== ' ') return;
        const row = e.target.closest('.pe-tec-row');
        if (!row) return;
        e.preventDefault();
        peAbrirDetalle(row.dataset.tecnicoId, row.dataset.tecnicoNombre);
    });
}

/**
 * Tablas de la página principal (por técnico, por cliente): clic en el título
 * de una columna ordena por ella (otro clic invierte). El valor de cada celda
 * es su atributo data-val si lo tiene (p. ej. horas en número, no "3h 45m");
 * si no, su texto. Las celdas vacías van siempre al final.
 */
function peInitTablasOrdenables() {
    document.querySelectorAll('.pe-tabla--ordenable').forEach(tabla => {
        const ths = Array.from(tabla.querySelectorAll('thead th'));
        let colActiva = -1, dir = 'asc';

        ths.forEach((th, idx) => {
            th.querySelector('.pe-th-sort')?.addEventListener('click', () => {
                dir = (colActiva === idx && dir === 'asc') ? 'desc' : 'asc';
                colActiva = idx;
                const numerico = th.dataset.sort === 'num';
                const valor = tr => {
                    const td = tr.children[idx];
                    const v = td.dataset.val !== undefined ? td.dataset.val : td.textContent.trim();
                    if (v === '') return null;
                    if (numerico) { const n = parseFloat(String(v).replace(',', '.')); return isNaN(n) ? null : n; }
                    return String(v).toLowerCase();
                };
                const tbody = tabla.querySelector('tbody');
                const filas = Array.from(tbody.querySelectorAll('tr'));
                filas.sort((a, b) => {
                    const va = valor(a), vb = valor(b);
                    if (va === null && vb === null) return 0;
                    if (va === null) return 1;
                    if (vb === null) return -1;
                    const r = numerico ? va - vb : va.localeCompare(vb, 'es', { numeric: true, sensitivity: 'base' });
                    return dir === 'asc' ? r : -r;
                });
                filas.forEach(f => tbody.appendChild(f));

                ths.forEach((t, i) => {
                    const activa = i === idx;
                    t.querySelector('.pe-th-sort')?.classList.toggle('pe-th-sort--activo', activa);
                    const fl = t.querySelector('.pe-th-flecha');
                    if (fl) fl.textContent = activa ? (dir === 'asc' ? '▲' : '▼') : '↕';
                    t.setAttribute('aria-sort', activa ? (dir === 'asc' ? 'ascending' : 'descending') : 'none');
                });
            });
        });
    });
}

function peBuildModal() {
    peModalEl = document.createElement('div');
    peModalEl.className = 'pt-modal';
    peModalEl.id = 'pe-modal-tecnico';
    peModalEl.innerHTML = `
    <div class="pt-modal-box">
        <div class="pt-modal-hdr">
            <span id="pe-modal-titulo">📊 Detalle del técnico</span>
            <button class="pt-modal-close" id="pe-modal-close">✕</button>
        </div>
        <div class="pt-modal-body" id="pe-modal-body">
            <div class="pt-modal-loading">⏳ Cargando…</div>
        </div>
    </div>`;
    document.body.appendChild(peModalEl);
    document.getElementById('pe-modal-close').addEventListener('click', peCerrarModal);
    peModalEl.addEventListener('click', e => { if (e.target === peModalEl) peCerrarModal(); });
}

function peCerrarModal() {
    if (peModalEl) peModalEl.classList.remove('pt-modal-open');
    document.body.style.overflow = '';
}

async function peAbrirDetalle(tecnicoId, nombre) {
    if (!peModalEl) peBuildModal();
    document.getElementById('pe-modal-titulo').textContent = '📊 ' + (nombre || 'Técnico');
    peModalEl.classList.add('pt-modal-open');
    document.body.style.overflow = 'hidden';

    const body = document.getElementById('pe-modal-body');
    body.innerHTML = '<div class="pt-modal-loading">⏳ Cargando datos del técnico…</div>';

    const desde = window.PT_ESTAD_FECHA_DESDE || '';
    const hasta = window.PT_ESTAD_FECHA_HASTA || '';
    const url = `${window.PT_BASE}/index.php?pt_do=estadisticas_tecnico`
        + `&tecnico_id=${encodeURIComponent(tecnicoId)}`
        + `&fecha_desde=${encodeURIComponent(desde)}&fecha_hasta=${encodeURIComponent(hasta)}`;

    try {
        const res  = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
        const data = await res.json();
        if (!res.ok || data.error) throw new Error(data.error || 'Error cargando estadísticas');
        peRenderDetalle(data);
    } catch (err) {
        body.innerHTML = `<div class="pt-modal-error">❌ ${peEsc(err.message)}</div>`;
    }
}

// ── Definición de columnas: cómo se ordena y cómo se filtra cada una ─────────
// type: text (contiene) · date (rango desde/hasta) · select (valor exacto)
//       num (mín/máx) · bool (sí/no)
const PE_COLS = [
    { key: 'ref',       label: 'Albarán',   type: 'text',   val: a => (a.ref + ' ' + (a.ref_client || '')).toLowerCase() },
    { key: 'cliente',   label: 'Cliente',   type: 'text',   val: a => (a.cliente || '').toLowerCase() },
    { key: 'fecha',     label: 'Fecha',     type: 'date',   val: a => a.fecha_ts },
    { key: 'tipo',      label: 'Tipo',      type: 'select', val: a => a.tipo_id,  optLabel: a => a.tipo_icon + ' ' + a.tipo_label },
    { key: 'estado',    label: 'Estado',    type: 'select', val: a => a.statut,   optLabel: a => a.statut_label },
    { key: 'inicio',    label: 'Inicio',    type: 'date',   val: a => a.inicio_ts },
    { key: 'fin',       label: 'Fin',       type: 'date',   val: a => a.fin_ts },
    { key: 'horas',     label: 'Horas',     type: 'num',    val: a => a.horas },
    { key: 'firmado',   label: 'Firmado',   type: 'bool',   val: a => !!a.firmado },
    { key: 'facturado', label: 'Facturado', type: 'bool',   val: a => !!a.facturado },
];

let peState = null; // { albaranes, orden: {key, dir}, filtros: {key: valor | {desde,hasta} | {min,max}} }

function peFiltrosVacios() {
    const f = {};
    PE_COLS.forEach(c => {
        f[c.key] = (c.type === 'date') ? { desde: '', hasta: '' }
                 : (c.type === 'num')  ? { min: '', max: '' }
                 : '';
    });
    return f;
}

function peFormatHoras(h) {
    if (h === null || h === undefined || h <= 0) return '—';
    const total = Math.round(h * 60);
    const hh = Math.floor(total / 60), mm = total % 60;
    return mm > 0 ? `${hh}h ${mm}m` : `${hh}h`;
}

/** KPIs recalculados sobre las filas visibles (mismas reglas que el servidor). */
function peCalcKpis(rows) {
    const r = { total: rows.length, terminados: 0, pendientes: 0, cancelados: 0, facturados: 0, firmados: 0, horas: 0, horasN: 0 };
    rows.forEach(a => {
        if (a.statut === 3) r.terminados++;
        else if (a.statut === -1) r.cancelados++;
        else r.pendientes++;
        if (a.facturado) r.facturados++;
        if (a.firmado) r.firmados++;
        if (a.horas !== null && a.horas !== undefined) { r.horas += a.horas; r.horasN++; }
    });
    r.pct = r.total > 0 ? Math.round(r.terminados / r.total * 100) : 0;
    r.media = r.horasN > 0 ? r.horas / r.horasN : null;
    return r;
}

function peRenderKpis(rows) {
    const r = peCalcKpis(rows);
    const kpis = [
        { label: 'Asignados',     valor: r.total,               color: '#0F172A' },
        { label: 'Terminados',    valor: r.terminados,          color: '#7C3AED' },
        { label: 'Pendientes',    valor: r.pendientes,          color: '#2563EB' },
        { label: 'Cancelados',    valor: r.cancelados,          color: '#DC2626' },
        { label: '% completado',  valor: r.pct + '%',           color: '#16A34A' },
        { label: 'Facturados',    valor: r.facturados,          color: '#0EA5E9' },
        { label: 'Firmados',      valor: r.firmados,            color: '#15803D' },
        { label: 'Horas totales', valor: peFormatHoras(r.horas),  color: '#D97706' },
        { label: 'Horas / albarán (media)', valor: peFormatHoras(r.media), color: '#D97706' },
    ];
    document.getElementById('pe-tec-kpis').innerHTML = kpis.map(k => `
        <div class="pe-tec-kpi" style="border-top-color:${k.color}">
            <div class="pe-tec-kpi-valor" style="color:${k.color}">${peEsc(String(k.valor))}</div>
            <div class="pe-tec-kpi-label">${peEsc(k.label)}</div>
        </div>`).join('');
}

/** ¿La fila cumple TODOS los filtros activos? */
function peCumpleFiltros(a) {
    for (const c of PE_COLS) {
        const f = peState.filtros[c.key];
        const v = c.val(a);
        if (c.type === 'text') {
            if (f && !String(v).includes(f.toLowerCase())) return false;
        } else if (c.type === 'select') {
            if (f !== '' && String(v) !== f) return false;
        } else if (c.type === 'bool') {
            if (f === 'si' && !v) return false;
            if (f === 'no' && v) return false;
        } else if (c.type === 'date') {
            if (f.desde || f.hasta) {
                if (v === null || v === undefined) return false; // sin dato: no entra en un rango
                if (f.desde && v < new Date(f.desde + 'T00:00:00').getTime() / 1000) return false;
                if (f.hasta && v > new Date(f.hasta + 'T23:59:59').getTime() / 1000) return false;
            }
        } else if (c.type === 'num') {
            if (f.min !== '' || f.max !== '') {
                if (v === null || v === undefined) return false;
                if (f.min !== '' && v < parseFloat(f.min)) return false;
                if (f.max !== '' && v > parseFloat(f.max)) return false;
            }
        }
    }
    return true;
}

function peComparar(a, b) {
    const col = PE_COLS.find(c => c.key === peState.orden.key);
    const va = col.val(a), vb = col.val(b);
    // Los valores vacíos van siempre al final, sea cual sea el sentido
    const aNull = va === null || va === undefined, bNull = vb === null || vb === undefined;
    if (aNull && bNull) return 0;
    if (aNull) return 1;
    if (bNull) return -1;
    let r;
    if (typeof va === 'string') r = va.localeCompare(vb, 'es', { numeric: true, sensitivity: 'base' });
    else r = (va === vb) ? 0 : (va < vb ? -1 : 1);
    return peState.orden.dir === 'asc' ? r : -r;
}

function peFilaHtml(a) {
    return `
    <tr>
        <td><a href="${peEsc(a.url_dolibarr)}" target="_blank" class="pe-tec-link">${peEsc(a.ref)}</a>${a.ref_client ? `<br><span class="pe-tec-refcli">${peEsc(a.ref_client)}</span>` : ''}</td>
        <td>${peEsc(a.cliente)}</td>
        <td>${peEsc(a.fecha)}</td>
        <td>${peEsc(a.tipo_icon)} ${peEsc(a.tipo_label)}</td>
        <td><span class="pe-tec-estado" style="color:${peEsc(a.statut_color)}">${peEsc(a.statut_label)}</span></td>
        <td>${peEsc(a.horadeinicio) || '—'}</td>
        <td>${peEsc(a.horadefin) || '—'}</td>
        <td class="pe-tec-horas">${peEsc(a.horas_label)}</td>
        <td>${a.firmado ? '✍️ Sí' : '—'}</td>
        <td>${a.facturado ? '💶 Sí' : '—'}</td>
    </tr>`;
}

/** Reaplica filtros + orden: repinta filas, KPIs, contador y flechas de orden. */
function peAplicar() {
    const filas = peState.albaranes.filter(peCumpleFiltros).sort(peComparar);

    document.getElementById('pe-tec-tbody').innerHTML = filas.length
        ? filas.map(peFilaHtml).join('')
        : `<tr><td colspan="${PE_COLS.length}" class="pe-empty">Ningún albarán cumple los filtros.</td></tr>`;

    peRenderKpis(filas);

    const hayFiltros = JSON.stringify(peState.filtros) !== JSON.stringify(peFiltrosVacios());
    document.getElementById('pe-tec-contador').textContent =
        `Mostrando ${filas.length} de ${peState.albaranes.length} albaranes` + (hayFiltros ? ' (filtrados)' : '');
    document.getElementById('pe-tec-limpiar').style.display = hayFiltros ? '' : 'none';

    document.querySelectorAll('.pe-th-sort').forEach(th => {
        const activo = th.dataset.key === peState.orden.key;
        th.classList.toggle('pe-th-sort--activo', activo);
        th.setAttribute('aria-sort', activo ? (peState.orden.dir === 'asc' ? 'ascending' : 'descending') : 'none');
        th.querySelector('.pe-th-flecha').textContent = activo ? (peState.orden.dir === 'asc' ? '▲' : '▼') : '↕';
    });
}

function peFiltroHtml(c) {
    const k = c.key;
    if (c.type === 'text') {
        return `<input type="search" class="pe-f-input" data-key="${k}" placeholder="Filtrar…">`;
    }
    if (c.type === 'select') {
        const vistos = new Map();
        peState.albaranes.forEach(a => { if (!vistos.has(String(c.val(a)))) vistos.set(String(c.val(a)), c.optLabel(a)); });
        const opts = Array.from(vistos.entries()).map(([v, l]) => `<option value="${peEsc(v)}">${peEsc(l)}</option>`).join('');
        return `<select class="pe-f-input" data-key="${k}"><option value="">Todos</option>${opts}</select>`;
    }
    if (c.type === 'bool') {
        return `<select class="pe-f-input" data-key="${k}"><option value="">Todos</option><option value="si">Sí</option><option value="no">No</option></select>`;
    }
    if (c.type === 'date') {
        return `<input type="date" class="pe-f-input" data-key="${k}" data-sub="desde" title="Desde">
                <input type="date" class="pe-f-input" data-key="${k}" data-sub="hasta" title="Hasta">`;
    }
    // num
    return `<input type="number" step="0.25" min="0" class="pe-f-input pe-f-input--num" data-key="${k}" data-sub="min" placeholder="Mín h" title="Horas mínimas">
            <input type="number" step="0.25" min="0" class="pe-f-input pe-f-input--num" data-key="${k}" data-sub="max" placeholder="Máx h" title="Horas máximas">`;
}

function peRenderDetalle(data) {
    const body = document.getElementById('pe-modal-body');
    peState = {
        albaranes: data.albaranes || [],
        orden: { key: 'fecha', dir: 'desc' },
        filtros: peFiltrosVacios(),
    };

    if (peState.albaranes.length === 0) {
        body.innerHTML = '<div class="pe-tec-kpis" id="pe-tec-kpis"></div><p class="pe-empty">Sin albaranes en este rango de fechas.</p>';
        peRenderKpis([]);
        return;
    }

    body.innerHTML = `
        <div class="pe-tec-kpis" id="pe-tec-kpis"></div>
        <div class="pe-tec-toolbar">
            <span id="pe-tec-contador" class="pe-tec-contador"></span>
            <button type="button" id="pe-tec-limpiar" class="pe-tec-limpiar" style="display:none">✕ Quitar filtros</button>
        </div>
        <div class="pe-tec-tabla-wrap">
            <table class="pe-tabla pe-tabla--detalle">
                <thead>
                    <tr>${PE_COLS.map(c => `
                        <th><button type="button" class="pe-th-sort" data-key="${c.key}" aria-sort="none">${peEsc(c.label)} <span class="pe-th-flecha">↕</span></button></th>`).join('')}
                    </tr>
                    <tr class="pe-fila-filtros">${PE_COLS.map(c => `<th>${peFiltroHtml(c)}</th>`).join('')}</tr>
                </thead>
                <tbody id="pe-tec-tbody"></tbody>
            </table>
        </div>
        <p class="pe-nota">Haz clic en el título de una columna para ordenar (otra vez para invertir) y usa la fila de filtros para acotar; los totales de arriba se recalculan con lo que se ve. Las horas de un albarán se registran una sola vez al cerrarlo; si tiene varios técnicos asignados, las mismas horas cuentan para cada uno de ellos.</p>
    `;

    // Ordenar al pulsar el título de una columna
    body.querySelectorAll('.pe-th-sort').forEach(btn => btn.addEventListener('click', () => {
        const key = btn.dataset.key;
        peState.orden = (peState.orden.key === key)
            ? { key, dir: peState.orden.dir === 'asc' ? 'desc' : 'asc' }
            : { key, dir: 'asc' };
        peAplicar();
    }));

    // Filtros: se actualizan al escribir/cambiar, sin repintar la fila de filtros (no pierde el foco)
    body.querySelector('.pe-fila-filtros').addEventListener('input', e => {
        const el = e.target.closest('.pe-f-input');
        if (!el) return;
        const k = el.dataset.key, sub = el.dataset.sub;
        if (sub) peState.filtros[k][sub] = el.value; else peState.filtros[k] = el.value;
        peAplicar();
    });

    document.getElementById('pe-tec-limpiar').addEventListener('click', () => {
        peState.filtros = peFiltrosVacios();
        body.querySelectorAll('.pe-f-input').forEach(el => { el.value = ''; });
        peAplicar();
    });

    peAplicar();
}

function peEsc(str) {
    return String(str ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

document.addEventListener('DOMContentLoaded', peInit);
