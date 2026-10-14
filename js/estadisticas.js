/**
 * js/estadisticas.js — Panel de detalle por técnico (admin/estadisticas.php)
 * Al hacer clic en una fila de la tabla "Por técnico", abre un modal con el
 * desglose completo de ese técnico: KPIs, por estado, por tipo y el listado
 * de cada albarán con sus horas trabajadas.
 */
'use strict';

let peModalEl = null;

function peInit() {
    const tabla = document.querySelector('.pe-tabla--tecnicos');
    if (!tabla) return; // no hay técnicos en el grupo: nada que conectar

    tabla.addEventListener('click', e => {
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

function peRenderDetalle(data) {
    const body = document.getElementById('pe-modal-body');
    const r = data.resumen;

    const kpis = [
        { label: 'Asignados',     valor: r.total,                 color: '#0F172A' },
        { label: 'Terminados',    valor: r.terminados,             color: '#7C3AED' },
        { label: 'Pendientes',    valor: r.pendientes,             color: '#2563EB' },
        { label: 'Cancelados',    valor: r.cancelados,             color: '#DC2626' },
        { label: '% completado',  valor: r.pct_completado + '%',   color: '#16A34A' },
        { label: 'Facturados',    valor: r.facturados,             color: '#0EA5E9' },
        { label: 'Firmados',      valor: r.firmados,               color: '#15803D' },
        { label: 'Horas totales', valor: r.horas_label,            color: '#D97706' },
        { label: 'Horas / albarán (media)', valor: r.horas_promedio_label, color: '#D97706' },
    ];

    const kpisHtml = kpis.map(k => `
        <div class="pe-tec-kpi" style="border-top-color:${k.color}">
            <div class="pe-tec-kpi-valor" style="color:${k.color}">${peEsc(String(k.valor))}</div>
            <div class="pe-tec-kpi-label">${peEsc(k.label)}</div>
        </div>`).join('');

    const filasHtml = (data.albaranes || []).length === 0
        ? '<p class="pe-empty">Sin albaranes en este rango de fechas.</p>'
        : `<table class="pe-tabla pe-tabla--detalle">
            <tr>
                <th>Albarán</th><th>Cliente</th><th>Fecha</th><th>Tipo</th><th>Estado</th>
                <th>Inicio</th><th>Fin</th><th>Horas</th><th>Firmado</th>
            </tr>
            ${data.albaranes.map(a => `
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
            </tr>`).join('')}
        </table>`;

    body.innerHTML = `
        <div class="pe-tec-kpis">${kpisHtml}</div>
        <div class="pe-tec-tabla-wrap">${filasHtml}</div>
        <p class="pe-nota">Las horas de un albarán se registran una sola vez al cerrarlo; si tiene varios técnicos asignados, las mismas horas cuentan para cada uno de ellos.</p>
    `;
}

function peEsc(str) {
    return String(str ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

document.addEventListener('DOMContentLoaded', peInit);
