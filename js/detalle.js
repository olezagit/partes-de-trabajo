/**
 * detalle.js — Panel de detalle a pantalla completa v2
 * Diseño:
 *   [ Llamar ]  [ Ver ruta ]          ← botones fijos arriba
 *   ▼ Detalles del albarán            ← ref, estado, fecha
 *   ▼ Datos del cliente               ← nombre, dir, localidad, tel1, tel2
 *   ▼ Tarea 1 (línea de albarán)      ← cada línea es su propio desplegable
 *   ▼ Tarea 2
 *   [ Anotaciones ]  [ Guardar ]      ← textarea + botón guardar nota
 */

'use strict';

const PtDetalle = (() => {

    let currentId  = null;
    let isOpen     = false;
    let overlay, panel;

    // ══════════════════════════════════════════════════════════════════════════
    // DOM — se crea una sola vez
    // ══════════════════════════════════════════════════════════════════════════
    function buildDOM() {
        if (document.getElementById('pd-overlay')) {
            overlay = document.getElementById('pd-overlay');
            panel   = document.getElementById('pd-panel');
            return;
        }
        overlay = document.createElement('div');
        overlay.id        = 'pd-overlay';
        overlay.className = 'pd-overlay';
        // Overlay click NO cierra — evita pérdida de progreso accidental
        // overlay.addEventListener('click', close);

        panel = document.createElement('div');
        panel.id        = 'pd-panel';
        panel.className = 'pd-panel';
        panel.setAttribute('role', 'dialog');
        panel.setAttribute('aria-modal', 'true');

        document.body.append(overlay, panel);

        // Escape NO cierra — evita pérdida de progreso accidental
        // Solo se puede cerrar con el botón X
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Abrir / Cerrar
    // ══════════════════════════════════════════════════════════════════════════
    async function open(parteId) {
        buildDOM();
        currentId = parteId;
        isOpen    = true;

        panel.innerHTML = skeletonHTML();
        overlay.classList.add('pd-open');
        panel.classList.add('pd-open');
        document.body.style.overflow = 'hidden';

        panel.querySelector('.pd-close-btn')?.addEventListener('click', close);

        try {
            const data = await fetchDetalle(parteId);
            if (currentId !== parteId) return;
            await PtDB.setMeta('detalle_' + parteId, JSON.stringify(data));
            render(data);
        } catch (err) {
            renderError(err.message || 'Error desconocido');
        }
    }

    function close() {
        isOpen = false;
        overlay.classList.remove('pd-open');
        panel.classList.remove('pd-open');
        document.body.style.overflow = '';
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Fetch de datos
    // ══════════════════════════════════════════════════════════════════════════
    async function fetchDetalle(parteId) {
        if (navigator.onLine) {
            // Intentar las dos URLs posibles en orden
            const urls = [
                `${window.PT_BASE}/index.php?pt_do=detalle&id=${parteId}`,
                `${window.PT_BASE}/ajax.php?action=detalle&id=${parteId}`,
            ];
            for (const url of urls) {
                try {
                    const res = await fetch(url, {
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        credentials: 'same-origin'
                    });
                    if (!res.ok) {
                        console.warn('[PT Detalle] ' + url + ' → HTTP ' + res.status);
                        continue;
                    }
                    const data = await res.json();
                    if (data.error) throw new Error(data.error);
                    console.log('[PT Detalle] Cargado desde:', url);
                    return data;
                } catch (err) {
                    console.warn('[PT Detalle] Falló ' + url + ':', err.message);
                }
            }
        }
        const cached = await PtDB.getMeta('detalle_' + parteId);
        if (cached) return JSON.parse(cached);
        throw new Error('Sin conexión y sin datos guardados para este parte.');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Render principal
    // ══════════════════════════════════════════════════════════════════════════
    function render(d) {
        const p  = d.parte;
        const cl = d.cliente;
        const ob = d.obra;
        const co = d.contacto;

        // ── Teléfono y ruta: el PHP ya resuelve la prioridad en d.contacto ────
        // co.telefono  = contacto asignado → sede → central (resuelto en PHP)
        // co.maps_query = dirección para Maps (resuelto en PHP)
        const telLlamar = (co.telefono || '').trim();
        const mapsURL   = co.maps_query
            ? 'https://www.google.com/maps/dir/?api=1&destination=' + co.maps_query
            : '';

        // Para el subtítulo del botón Maps y secciones de dirección
        const ctPrincipal   = d.contactos && d.contactos.length ? d.contactos[0] : null;
        const locContacto   = ctPrincipal ? [ctPrincipal.zip, ctPrincipal.town].filter(Boolean).join(' ') : '';

        // ── Fechas ────────────────────────────────────────────────────────────
        const fmt = ts => ts
            ? new Date(ts * 1000).toLocaleDateString('es-ES', { day: '2-digit', month: '2-digit', year: 'numeric' })
            : '—';

        // ── Estado ────────────────────────────────────────────────────────────
        // "En proceso" no es un fk_statut propio: se calcula cuando el pedido
        // está Validado (1), ya tiene firma del técnico y aún no la del cliente.
        const estadoMap = {
            '-1': { label: 'Cancelado', color: '#DC2626' },
            '0':  { label: 'Borrador',  color: '#94A3B8' },
            '1':  { label: 'Validado',  color: '#2563EB' },
            '2':  { label: 'En proceso', color: '#16A34A' },
            '3':  { label: 'Terminado', color: '#7C3AED' },
        };
        const enProcesoDetalle = (p.statut === 1 && !!p.firma_tecnico && !p.firma_cliente);
        const estado = enProcesoDetalle
            ? { label: 'En proceso', color: '#16A34A' }
            : (estadoMap[String(p.statut)] || { label: 'Estado ' + p.statut, color: '#6B7280' });

        // ── Localidad cliente ─────────────────────────────────────────────────
        const localidadCliente = [cl.zip, cl.town].filter(Boolean).join(' ');
        const localidadObra    = [ob.zip, ob.town].filter(Boolean).join(' ');

        panel.innerHTML = `
        <div class="pd-header">
            <div class="pd-drag-bar"><span></span></div>
            <div class="pd-header-top">
                <span class="pd-tipo-pill" style="color:${p.tipo_color};background:${p.tipo_bg}">
                    ${p.tipo_icon} ${escH(p.tipo_label)}
                </span>
                <button class="pd-close-btn" aria-label="Cerrar">✕</button>
            </div>
            <div class="pd-header-ref">${escH(p.ref)}</div>
        </div>

        <!-- Enlace a Dolibarr (solo admins) — primero en el orden visual -->
        ${window.PT_ES_ADMIN ? `
        <a href="${escH(p.url_dolibarr)}" target="_blank" rel="noopener" class="pd-dolibarr-bar">
            <span class="pd-dolibarr-bar-ico">🔗</span>
            <span class="pd-dolibarr-bar-txt">Abrir en Dolibarr</span>
            <span class="pd-dolibarr-bar-arrow">→</span>
        </a>` : ''}

        <!-- ① Botones de acción fijos -->
        <div class="pd-action-bar">
            ${telLlamar
                ? `<a href="tel:${escH(telLlamar.replace(/\s/g,''))}" class="pd-btn pd-btn--call">
                        <span class="pd-btn-ico">📞</span>
                        <span class="pd-btn-txt">Llamar<small>${escH(telLlamar)}</small></span>
                   </a>`
                : `<button class="pd-btn pd-btn--disabled" disabled>
                        <span class="pd-btn-ico">📵</span>
                        <span class="pd-btn-txt">Sin teléfono</span>
                   </button>`
            }
            ${mapsURL
                ? `<a href="${escH(mapsURL)}" target="_blank" rel="noopener" class="pd-btn pd-btn--maps">
                        <span class="pd-btn-ico">🧭</span>
                        <span class="pd-btn-txt">Ver ruta<small>${escH(locContacto || localidadObra || localidadCliente || '')}</small></span>
                   </a>`
                : `<button class="pd-btn pd-btn--disabled" disabled>
                        <span class="pd-btn-ico">🗺️</span>
                        <span class="pd-btn-txt">Sin dirección</span>
                   </button>`
            }
        </div>

        <!-- Cuerpo scrollable -->
        <div class="pd-body">

            <!-- ② Detalles del albarán -->
            <div class="pd-section">
                <button class="pd-section-hdr open" data-target="sec-albaran">
                    <span class="pd-section-ico" style="background:#EFF6FF">📋</span>
                    <span class="pd-section-title">Detalles del albarán</span>
                    <span class="pd-chevron">▾</span>
                </button>
                <div class="pd-section-body open" id="sec-albaran">
                    <div class="pd-field-row">
                        <span class="pd-field-lbl">Referencia</span>
                        <span class="pd-field-val">${escH(p.ref)}</span>
                    </div>
                    ${p.ref_client ? `
                    <div class="pd-field-row">
                        <span class="pd-field-lbl">Ref. cliente</span>
                        <span class="pd-field-val pd-refcli">${escH(p.ref_client)}</span>
                    </div>` : ''}
                    <div class="pd-field-row">
                        <span class="pd-field-lbl">Estado</span>
                        <span class="pd-field-val">
                            <span class="pd-estado-badge" style="color:${estado.color};border-color:${estado.color}20;background:${estado.color}10">
                                ${escH(estado.label)}
                            </span>
                        </span>
                    </div>
                    <div class="pd-field-row">
                        <span class="pd-field-lbl">Fecha</span>
                        <span class="pd-field-val">${fmt(p.date_commande)}</span>
                    </div>
                    ${p.date_livraison ? `
                    <div class="pd-field-row">
                        <span class="pd-field-lbl">Entrega</span>
                        <span class="pd-field-val">${fmt(p.date_livraison)}</span>
                    </div>` : ''}
                </div>
            </div>

            <!-- ③ Datos del cliente -->
            <div class="pd-section">
                <button class="pd-section-hdr" data-target="sec-cliente">
                    <span class="pd-section-ico" style="background:#F0FDF4">🏢</span>
                    <span class="pd-section-title">${escH(cl.nom)}</span>
                    <span class="pd-chevron">▾</span>
                </button>
                <div class="pd-section-body" id="sec-cliente">
                    ${renderClienteBody(d, ob, cl, localidadObra, localidadCliente)}
                </div>
            </div>

            <!-- ④ Firma del técnico -->
            <div class="pd-section" id="sec-wrap-firma-tecnico">
                <button class="pd-section-hdr" data-target="sec-firma-tecnico">
                    <span class="pd-section-ico" style="background:#EFF6FF">🔧</span>
                    <span class="pd-section-title">Firma del técnico</span>
                    <span class="pd-firma-badge" id="badge-firma-tecnico"></span>
                    <span class="pd-chevron">▾</span>
                </button>
                <div class="pd-section-body" id="sec-firma-tecnico">
                    <label class="pd-firma-label">Firma</label>
                    <div class="pd-canvas-wrap" id="wrap-canvas-tecnico">
                        <canvas id="pd-canvas-tecnico" class="pd-canvas"></canvas>
                        <div class="pd-firma-locked" id="lock-tecnico" style="display:none">
                            <span class="pd-lock-icon">🔒</span>
                            <span class="pd-lock-txt">Firma bloqueada</span>
                        </div>
                    </div>
                    <div class="pd-firma-actions" id="actions-firma-tecnico" style="display:none">
                        <button type="button" class="pd-firma-btn pd-firma-btn--edit" data-target="tecnico">✏️ Editar</button>
                        <button type="button" class="pd-firma-btn pd-firma-btn--del"  data-target="tecnico">🗑 Eliminar</button>
                    </div>
                    <button type="button" class="pd-firma-confirm" id="confirm-firma-tecnico"
                        data-target="tecnico" style="display:none">✅ Confirmar firma</button>
                </div>
            </div>

            <!-- ⑤ Contenedor de tareas (desplegable padre) -->
            <div class="pd-section" id="sec-wrap-tareas">
                <button class="pd-section-hdr open" data-target="sec-tareas-body">
                    <span class="pd-section-ico" style="background:#FFF7ED">📋</span>
                    <span class="pd-section-title">
                        Tareas
                        <span class="pd-tareas-subtitulo" id="pd-tareas-subtitulo">
                            ${d.lineas.length > 0
                                ? `0 / ${d.lineas.length} completadas`
                                : 'Sin tareas'}
                        </span>
                    </span>
                    <span class="pd-chevron">▾</span>
                </button>
                <div class="pd-section-body open pd-tareas-body" id="sec-tareas-body">
                    ${d.lineas.length > 0
                        ? `<div class="pd-tareas-progress-wrap">
                               <div class="pd-tareas-progress-bar" id="pd-tareas-progress-bar"></div>
                           </div>
                           ${d.lineas.map((ln, i) => tareaHTML(ln, i, p.tipo_albaran)).join('')}`
                        : `<div class="pd-tareas-empty">
                               <span>📝</span>
                               <span>Sin tareas registradas en este parte</span>
                           </div>`
                    }
                </div>
            </div>

            <!-- ⑤.5 Fotos del trabajo -->
            <div class="pd-section" id="sec-wrap-fotos">
                <button class="pd-section-hdr" data-target="sec-fotos">
                    <span class="pd-section-ico" style="background:#FEF3C7">📷</span>
                    <span class="pd-section-title">
                        Fotos del trabajo
                        <span class="pd-fotos-subtitulo" id="pd-fotos-subtitulo">${(p.fotos||[]).filter(Boolean).length} / 10</span>
                    </span>
                    <span class="pd-chevron">▾</span>
                </button>
                <div class="pd-section-body" id="sec-fotos">
                    <div class="pd-fotos-grid" id="pd-fotos-grid">
                        ${fotosSlotsHTML(p.fotos)}
                    </div>
                    <div class="pd-fotos-hint">Toca un hueco vacío para añadir una foto (cámara o galería). Máximo 10 fotos.</div>
                    <input type="file" id="pd-foto-input" accept="image/*" style="display:none">
                </div>
            </div>

            <!-- ⑥ Anotaciones -->
            <div class="pd-section pd-section--nota">
                <button class="pd-section-hdr" data-target="sec-nota">
                    <span class="pd-section-ico" style="background:#F5F3FF">✏️</span>
                    <span class="pd-section-title">Anotaciones</span>
                    <span class="pd-chevron">▾</span>
                </button>
                <div class="pd-section-body" id="sec-nota">
                    <div class="pd-notas-lista" id="pd-notas-lista">
                        ${notasListHTML(d.notas)}
                    </div>
                    ${p.note_public ? `
                    <details class="pd-notas-legacy">
                        <summary>Ver historial anterior</summary>
                        <div class="pd-nota-previa">${escH(p.note_public)}</div>
                    </details>` : ''}
                    <textarea class="pd-textarea" id="pd-nota-input" rows="4"
                        placeholder="Escribe aquí tu anotación sobre este parte…"></textarea>
                    <button class="pd-save-btn" id="pd-nota-save" data-id="${p.rowid}">
                        💾 Guardar anotación
                    </button>
                    <div class="pd-nota-feedback" id="pd-nota-feedback"></div>
                </div>
            </div>


            <!-- ⑦ Firma del cliente -->
            <div class="pd-section" id="sec-wrap-firma-cliente">
                <button class="pd-section-hdr" data-target="sec-firma-cliente">
                    <span class="pd-section-ico" style="background:#FFF7ED">✍️</span>
                    <span class="pd-section-title">Firma del cliente</span>
                    <span class="pd-firma-badge" id="badge-firma-cliente"></span>
                    <span class="pd-chevron">▾</span>
                </button>
                <div class="pd-section-body" id="sec-firma-cliente">
                    ${p.nombre_firmante ? `<div class="pd-firma-info-existing">
                        <div class="pd-firma-info-row"><span class="pd-field-lbl">Nombre</span><span class="pd-field-val">${escH(p.nombre_firmante)}</span></div>
                        <div class="pd-firma-info-row"><span class="pd-field-lbl">DNI</span><span class="pd-field-val">${escH(p.dni_firmante||'—')}</span></div>
                        ${p.fecha_firma ? `<div class="pd-firma-info-row"><span class="pd-field-lbl">Fecha</span><span class="pd-field-val">${escH(p.fecha_firma)}</span></div>` : ''}
                    </div>` : ''}
                    <div class="pd-firma-fields">
                        <label class="pd-firma-label">Nombre completo</label>
                        <input type="text" id="pd-nombre-cliente" class="pd-firma-input"
                            placeholder="Nombre y apellidos del firmante"
                            value="${escH(p.nombre_firmante||'')}">
                        <label class="pd-firma-label">DNI / NIE</label>
                        <input type="text" id="pd-dni-cliente" class="pd-firma-input"
                            placeholder="00000000A" value="${escH(p.dni_firmante||'')}">
                    </div>
                    <label class="pd-firma-label">Firma</label>
                    <div class="pd-canvas-wrap" id="wrap-canvas-cliente">
                        <canvas id="pd-canvas-cliente" class="pd-canvas"></canvas>
                        <div class="pd-firma-locked" id="lock-cliente" style="display:none">
                            <span class="pd-lock-icon">🔒</span>
                            <span class="pd-lock-txt">Firma bloqueada</span>
                        </div>
                    </div>
                    <div class="pd-firma-actions" id="actions-firma-cliente" style="display:none">
                        <button type="button" class="pd-firma-btn pd-firma-btn--edit" data-target="cliente">✏️ Editar</button>
                        <button type="button" class="pd-firma-btn pd-firma-btn--del"  data-target="cliente">🗑 Eliminar</button>
                    </div>
                    <button type="button" class="pd-firma-confirm" id="confirm-firma-cliente"
                        data-target="cliente" style="display:none">✅ Confirmar firma</button>
                </div>
            </div>

            <!-- ⑧ Horas de inicio y fin del servicio -->
            <div class="pd-section">
                <button class="pd-section-hdr" data-target="sec-horas">
                    <span class="pd-section-ico" style="background:#F0F9FF">⏱️</span>
                    <span class="pd-section-title">Horario del servicio</span>
                    <span class="pd-chevron">▾</span>
                </button>
                <div class="pd-section-body" id="sec-horas">
                    <div class="pd-firma-fields">
                        <label class="pd-firma-label">Hora de inicio</label>
                        <input type="datetime-local" id="pd-hora-inicio" class="pd-firma-input"
                            value="${escH(p.horadeinicio||'')}">
                        <label class="pd-firma-label">Hora de fin</label>
                        <input type="datetime-local" id="pd-hora-fin" class="pd-firma-input"
                            value="${escH(p.horadefin||'')}">
                    </div>
                </div>
            </div>

            <!-- Botón Terminar -->
            <button class="pd-terminar-btn" id="pd-terminar" data-id="${p.rowid}" disabled>
                ⏳ Completa las tareas y firmas
            </button>
            <div class="pd-nota-feedback" id="pd-terminar-feedback" style="margin-bottom:8px"></div>

        </div>`; // fin pd-body

        // Eventos
        panel.querySelector('.pd-close-btn').addEventListener('click', close);
        wireCollapses();
        wireTareas(d.lineas, p.rowid, p.tipo_albaran);
        wireFotos(p.rowid, p);
        wireNota(p.rowid);
        wireNotas(p.rowid);
        wireFirmas(p.rowid, p);

    }
    // ══════════════════════════════════════════════════════════════════════════
    // HTML helpers
    // ══════════════════════════════════════════════════════════════════════════
    function renderClienteBody(d, ob, cl, localidadObra, localidadCliente) {
        let html = '';
        const co = d.contacto; // objeto contacto resuelto por PHP (tiene maps_address)

        // ── Dirección de la obra ─────────────────────────────────────────────
        // Usar la misma dirección que el botón "Ver ruta" (resuelta por PHP)
        const dirObra = co && co.maps_address ? co.maps_address : (
            [ob.address, ob.zip, ob.town].filter(Boolean).join(', ') ||
            [cl.address, cl.zip, cl.town].filter(Boolean).join(', ')
        );
        html += '<div class="pd-field-group-title">📍 Dirección de la obra</div>';
        if (dirObra) html += row('Dirección', escH(dirObra));
        html += '<div class="pd-field-group-sep"></div>';

        // ── Contactos asignados ──────────────────────────────────────────────
        if (d.contactos && d.contactos.length > 0) {
            const plural = d.contactos.length > 1;
            html += '<div class="pd-field-group-title">👤 Contacto' + (plural ? 's' : '') + ' asignado' + (plural ? 's' : '') + '</div>';
            d.contactos.forEach(ct => {
                html += '<div class="pd-contacto-card">';
                html += '<div class="pd-contacto-nombre">' + escH(ct.nombre || '');
                if (ct.poste) html += ' <span class="pd-contacto-poste">' + escH(ct.poste) + '</span>';
                html += '</div>';
                // Todos los teléfonos en orden: trabajo → móvil → personal
                if (ct.phone_pro) html += '<div class="pd-contacto-tel">' + phoneLink(ct.phone_pro) + ' <span class="pd-tel-tipo">Trabajo</span></div>';
                if (ct.phone_mob) html += '<div class="pd-contacto-tel">' + phoneLink(ct.phone_mob) + ' <span class="pd-tel-tipo">Móvil</span></div>';
                if (ct.phone_per) html += '<div class="pd-contacto-tel">' + phoneLink(ct.phone_per) + ' <span class="pd-tel-tipo">Personal</span></div>';
                html += '</div>';
            });
            html += '<div class="pd-field-group-sep"></div>';
        }

        // ── Sede de la obra (teléfono) ────────────────────────────────────────
        if (ob.phone) {
            html += '<div class="pd-field-group-title">📞 Teléfono sede</div>';
            html += row('Teléfono', phoneLink(ob.phone));
            html += '<div class="pd-field-group-sep"></div>';
        }

        // ── Central del cliente ───────────────────────────────────────────────
        html += '<div class="pd-field-group-title">🏢 ' + escH(cl.nom) + ' (central)</div>';
        if (cl.address)       html += row('Dirección',   escH(cl.address));
        if (localidadCliente) html += row('Localidad',   escH(localidadCliente));
        if (cl.phone)         html += row('Teléfono',    phoneLink(cl.phone));
        if (cl.phone_mobile)  html += row('Móvil',       phoneLink(cl.phone_mobile));
        if (cl.phone2)        html += row('Teléfono 2',  phoneLink(cl.phone2));
        if (cl.phone3)        html += row('Teléfono 3',  phoneLink(cl.phone3));
        if (cl.phone4)        html += row('Teléfono 4',  phoneLink(cl.phone4));

        return html;
    }

    function renderContactos(contactos) {
        if (!contactos || contactos.length === 0) return '';
        const plural = contactos.length > 1;
        let html = '<div class="pd-field-group-title">👤 Contacto' + (plural ? 's' : '') + ' asignado' + (plural ? 's' : '') + '</div>';
        contactos.forEach(ct => {
            html += '<div class="pd-contacto-card">';
            html += '<div class="pd-contacto-nombre">' + escH(ct.nombre || '');
            if (ct.poste) html += ' <span class="pd-contacto-poste">' + escH(ct.poste) + '</span>';
            html += '</div>';
            const dir = [ct.address, ct.zip, ct.town].filter(Boolean).join(', ');
            if (dir) html += '<div class="pd-contacto-dir">📍 ' + escH(dir) + '</div>';
            if (ct.phone_pro) html += '<div>' + phoneLink(ct.phone_pro) + ' <span class="pd-tel-tipo">Trabajo</span></div>';
            if (ct.phone_mob) html += '<div>' + phoneLink(ct.phone_mob) + ' <span class="pd-tel-tipo">Móvil</span></div>';
            if (ct.phone_per) html += '<div>' + phoneLink(ct.phone_per) + ' <span class="pd-tel-tipo">Personal</span></div>';
            html += '</div>';
        });
        html += '<div class="pd-field-group-sep"></div>';
        return html;
    }

    function row(label, valueHTML) {
        return `<div class="pd-field-row">
            <span class="pd-field-lbl">${label}</span>
            <span class="pd-field-val">${valueHTML}</span>
        </div>`;
    }

    function phoneLink(tel) {
        if (!tel) return '';
        const clean = tel.replace(/\s/g, '');
        return `<a href="tel:${escH(clean)}" class="pd-phone-link">${escH(tel)}</a>`;
    }

    function emailLink(email) {
        return `<a href="mailto:${escH(email)}" class="pd-email-link">${escH(email)}</a>`;
    }

    function fotosSlotsHTML(fotos) {
        const lista = Array.isArray(fotos) ? fotos : [];
        let html = '';
        for (let i = 1; i <= 10; i++) {
            const dataUrl = lista[i - 1] || '';
            if (dataUrl) {
                html += `
                <div class="pd-foto-slot pd-foto-slot--filled" data-slot="${i}">
                    <img src="${escH(dataUrl)}" class="pd-foto-thumb" data-slot="${i}" alt="Foto ${i} del parte">
                    <button type="button" class="pd-foto-del" data-slot="${i}" aria-label="Eliminar foto ${i}">✕</button>
                </div>`;
            } else {
                html += `
                <button type="button" class="pd-foto-slot pd-foto-slot--empty" data-slot="${i}" aria-label="Añadir foto ${i}">
                    <span class="pd-foto-add-ico">📷</span>
                </button>`;
            }
        }
        return html;
    }

    function notasListHTML(notas) {
        const lista = Array.isArray(notas) ? notas : [];
        if (lista.length === 0) {
            return '<div class="pd-notas-vacio">Aún no hay anotaciones en este parte.</div>';
        }
        return lista.map(n => `
            <div class="pd-nota-item" data-nota-id="${n.id}">
                <div class="pd-nota-item-meta">
                    <span class="pd-nota-item-autor">${escH(n.autor_nombre || '—')}</span>
                    <span class="pd-nota-item-fecha">${escH(n.fecha || '')}${n.editada ? ' · editado' : ''}</span>
                    ${n.editable ? `<button type="button" class="pd-nota-edit-btn" data-nota-id="${n.id}">✏️ Editar</button>` : ''}
                </div>
                <div class="pd-nota-item-texto" id="pd-nota-texto-${n.id}">${escH(n.texto)}</div>
            </div>`).join('');
    }

    function tareaHTML(ln, idx, tipoAlbaran) {
        // El label es el título corto — la description puede ser larga con saltos de línea
        const label = (ln.label || 'Tarea ' + (idx + 1));
        const desc  = (ln.description && ln.description !== label) ? ln.description : '';
        const qty   = ln.qty != null
            ? parseFloat(ln.qty).toLocaleString('es-ES') + (ln.unidad ? ' ' + ln.unidad : '')
            : '';
        const id      = 'sec-tarea-' + idx;
        const checkId = 'check-tarea-' + idx;
        const hecha   = !!ln.tarea_realizada;
        const nCampos = contarCamposFichaRellenos(ln.ficha);

        return `
        <div class="pd-section pd-section--tarea${hecha ? ' pd-tarea-ok' : ''}" id="wrap-tarea-${idx}"
             data-tarea-idx="${idx}" data-rowid="${ln.rowid}">
            <button class="pd-section-hdr${hecha ? ' open' : ''}" data-target="${id}">
                <span class="pd-section-ico" style="background:#FFF7ED">
                    <span class="pd-tarea-num">${idx + 1}</span>
                </span>
                <span class="pd-section-title pd-tarea-titulo">${escH(label)}</span>
                ${qty ? `<span class="pd-tarea-qty">${escH(qty)}</span>` : ''}
                <span class="pd-tarea-status pd-tarea-status--${hecha ? 'done' : 'pending'}" id="status-tarea-${idx}">${hecha ? 'Hecho' : 'Pendiente'}</span>
                <span class="pd-chevron">▾</span>
            </button>
            <div class="pd-section-body${hecha ? ' open' : ''}" id="${id}">
                <!-- Label en negrita + description en texto plano separados -->
                <div class="pd-tarea-content">
                    <div class="pd-tarea-label">${escH(label)}${qty ? `<span class="pd-tarea-qty-inline"> · ${escH(qty)}</span>` : ''}</div>
                    ${ln.prod_ref ? `<div class="pd-tarea-ref">${escH(ln.prod_ref)}</div>` : ''}
                    ${desc ? `<div class="pd-tarea-desc">${escH(desc)}</div>` : ''}
                    ${!desc && !ln.prod_ref ? `<div class="pd-tarea-nodesc">Sin descripción adicional</div>` : ''}
                </div>
                <!-- Ficha técnica del producto -->
                <button type="button" class="pd-tarea-ficha-btn" data-idx="${idx}" data-rowid="${ln.rowid}">
                    <span>📋 Ficha técnica</span>
                    ${nCampos > 0 ? `<span class="pd-tarea-ficha-badge">${nCampos} rellenos</span>` : ''}
                    <span class="pd-tarea-ficha-arrow">›</span>
                </button>
                <!-- Checkbox de completar -->
                <div class="pd-tarea-check-wrap">
                    <div class="pd-tarea-check${hecha ? ' checked' : ''}" id="${checkId}" data-idx="${idx}" data-rowid="${ln.rowid}"
                         role="checkbox" aria-checked="${hecha ? 'true' : 'false'}" tabindex="0">
                        <div class="pd-check-box">${hecha ? '✓' : '☐'}</div>
                        <span class="pd-check-txt">${hecha ? '¡Completada!' : 'Marcar como completada'}</span>
                    </div>
                </div>
            </div>
        </div>`;
    }

    /** Cuenta cuántos campos de la ficha técnica de una línea tienen valor (para el contador del botón). */
    function contarCamposFichaRellenos(ficha) {
        if (!ficha) return 0;
        return Object.values(ficha).filter(v => {
            if (Array.isArray(v)) return v.length > 0; // multiselect: solo cuenta si hay algo marcado
            return v !== '' && v !== null && v !== false && v !== undefined;
        }).length;
    }

    function escH(str) {
        return String(str ?? '')
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Desplegables
    // ══════════════════════════════════════════════════════════════════════════
    function wireCollapses() {
        panel.querySelectorAll('.pd-section-hdr[data-target]').forEach(hdr => {
            hdr.addEventListener('click', () => {
                const body = document.getElementById(hdr.dataset.target);
                if (!body) return;
                const opening = !hdr.classList.contains('open');
                hdr.classList.toggle('open', opening);
                body.classList.toggle('open', opening);
            });
        });
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Guardar anotación
    // ══════════════════════════════════════════════════════════════════════════

    // ══════════════════════════════════════════════════════════════════════════
    // Checkboxes de tareas
    // ══════════════════════════════════════════════════════════════════════════
    let tareasTotal       = 0;
    let tareasCompletadas = new Set();
    let parteIdActual     = null;
    let lineasActuales    = [];
    let tipoAlbaranActual = null;

    function wireTareas(lineas, parteId, tipoAlbaran) {
        tareasTotal   = lineas.length;
        parteIdActual = parteId;
        lineasActuales = lineas;
        tipoAlbaranActual = tipoAlbaran;
        tareasCompletadas.clear();

        // Inicializar el Set con las tareas que ya vienen marcadas desde la BD
        lineas.forEach((ln, idx) => {
            if (ln.tarea_realizada) tareasCompletadas.add(idx);
        });

        panel.querySelectorAll('.pd-tarea-check').forEach(chk => {
            chk.addEventListener('click', () => toggleTarea(chk));
            chk.addEventListener('keydown', e => {
                if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggleTarea(chk); }
            });
        });
        panel.querySelectorAll('.pd-tarea-ficha-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const idx = parseInt(btn.dataset.idx, 10);
                abrirFichaModal(lineasActuales[idx], idx);
            });
        });
        actualizarBotonTerminar();
        actualizarProgresoTareas();
    }

    function toggleTarea(chk) {
        const idx     = parseInt(chk.dataset.idx, 10);
        const lineaId = parseInt(chk.dataset.rowid, 10);
        const isDone  = chk.classList.contains('checked');
        const wrap    = panel.querySelector('#wrap-tarea-' + idx);
        const status  = panel.querySelector('#status-tarea-' + idx);
        const box     = chk.querySelector('.pd-check-box');
        const txt     = chk.querySelector('.pd-check-txt');

        const nuevoEstado = !isDone; // lo que va a quedar tras el toggle

        if (isDone) {
            // Desmarcar
            chk.classList.remove('checked');
            chk.setAttribute('aria-checked', 'false');
            box.textContent = '☐';
            txt.textContent = 'Marcar como completada';
            wrap?.classList.remove('pd-tarea-ok');
            if (status) {
                status.textContent = 'Pendiente';
                status.className = 'pd-tarea-status pd-tarea-status--pending';
            }
            tareasCompletadas.delete(idx);
        } else {
            // Marcar
            chk.classList.add('checked');
            chk.setAttribute('aria-checked', 'true');
            box.textContent = '✓';
            txt.textContent = '¡Completada!';
            wrap?.classList.add('pd-tarea-ok');
            if (status) {
                status.textContent = 'Hecho';
                status.className = 'pd-tarea-status pd-tarea-status--done';
            }
            tareasCompletadas.add(idx);
        }
        actualizarBotonTerminar();
        actualizarProgresoTareas();

        // Persistir en BD (commandedet_extrafields.tarea_realizada)
        guardarTarea(lineaId, nuevoEstado, chk);
    }

    async function guardarTarea(lineaId, realizada, chk) {
        const payload = { linea_id: lineaId, realizada, parte_id: parteIdActual };

        if (!navigator.onLine) {
            await encolarProgreso(payload, 'Sin conexión');
            return;
        }

        let res, data;
        try {
            res = await fetch(`${window.PT_BASE}/index.php?pt_do=tarea`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'same-origin',
                body: JSON.stringify(payload)
            });
        } catch (networkErr) {
            await encolarProgreso(payload, networkErr.message);
            return;
        }

        try {
            data = await res.json();
        } catch {
            data = { error: 'Respuesta no válida del servidor (HTTP ' + res.status + ')' };
        }

        if (!res.ok || data.error) {
            const msg = '❌ No se pudo guardar la tarea: ' + (data.error || ('HTTP ' + res.status));
            console.error('[PT] Error al guardar tarea:', data.error || res.status, payload);
            if (typeof window.showToast === 'function') {
                window.showToast(msg, 'error', 6000);
            }
            return;
        }

        console.log('[PT] Tarea guardada en BD:', payload);
    }

    function actualizarProgresoTareas() {
        const subtitulo = panel.querySelector('#pd-tareas-subtitulo');
        const barra     = panel.querySelector('#pd-tareas-progress-bar');
        const wrap      = panel.querySelector('#sec-wrap-tareas');

        if (subtitulo && tareasTotal > 0) {
            const n = tareasCompletadas.size;
            subtitulo.textContent = `${n} / ${tareasTotal} completada${n !== 1 ? 's' : ''}`;
        }

        if (barra && tareasTotal > 0) {
            const pct = Math.round((tareasCompletadas.size / tareasTotal) * 100);
            barra.style.width = pct + '%';
            barra.style.background = pct === 100 ? '#22C55E' : '#3B82F6';
        }

        // Verde en el contenedor cuando todas completadas
        if (wrap && tareasTotal > 0) {
            if (tareasCompletadas.size === tareasTotal) {
                wrap.classList.add('pd-tareas-todas-ok');
            } else {
                wrap.classList.remove('pd-tareas-todas-ok');
            }
        }
    }

    function actualizarBotonTerminar() {
        const btn = panel.querySelector('#pd-terminar');
        if (!btn) return;

        const todasOk  = tareasTotal === 0 || tareasCompletadas.size === tareasTotal;
        const canvasC  = panel.querySelector('#pd-canvas-cliente');
        const canvasT  = panel.querySelector('#pd-canvas-tecnico');
        // Las firmas deben estar CONFIRMADAS (locked), no solo dibujadas
        const firmaC   = canvasC?.dataset.locked === '1';
        const firmaT   = canvasT?.dataset.locked === '1';
        const firmasCk = firmaC && firmaT;
        const nombreOk  = (panel.querySelector('#pd-nombre-cliente')?.value || '').trim().length > 0;
        const dniOk     = (panel.querySelector('#pd-dni-cliente')?.value    || '').trim().length > 0;
        const horaIniOk = (panel.querySelector('#pd-hora-inicio')?.value    || '').trim().length > 0;
        const horaFinOk = (panel.querySelector('#pd-hora-fin')?.value       || '').trim().length > 0;

        const listo = todasOk && firmasCk && nombreOk && dniOk && horaIniOk && horaFinOk;
        btn.disabled = !listo;

        // Texto informativo en el botón
        if (!todasOk) {
            const pendientes = tareasTotal - tareasCompletadas.size;
            btn.textContent = `⏳ Faltan ${pendientes} tarea${pendientes > 1 ? 's' : ''} por completar`;
        } else if (!nombreOk || !dniOk) {
            btn.textContent = '⚠️ Completa los datos del cliente';
        } else if (!firmaC && !firmaT) {
            btn.textContent = '✍️ Faltan ambas firmas';
        } else if (!firmaC) {
            btn.textContent = '✍️ Confirma la firma del cliente';
        } else if (!firmaT) {
            btn.textContent = '🔧 Confirma la firma del técnico';
        } else if (!horaIniOk || !horaFinOk) {
            btn.textContent = '⏱️ Rellena el horario del servicio';
        } else {
            btn.textContent = '✅ Terminar parte';
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Ficha técnica por producto (llx_commandedet_extrafields)
    // ══════════════════════════════════════════════════════════════════════════

    let fichaModalEl = null;

    /** Devuelve los grupos de campos aplicables según el tipo de albarán:
     *  globales siempre + pm_ (tipo 2, Puesta en marcha) o man_ (tipo 3/4, Avería/Mantenimiento). */
    function gruposFichaAplicables(tipoAlbaran) {
        const meta = window.PT_CAMPOS_LINEA || { global: [], pm: [], man: [] };
        const grupos = [{ titulo: null, campos: meta.global || [] }];
        const tipoNum = parseInt(tipoAlbaran, 10);
        if (tipoNum === 2) {
            grupos.push({ titulo: 'Puesta en marcha', campos: meta.pm || [] });
        } else if (tipoNum === 3 || tipoNum === 4) {
            grupos.push({ titulo: 'Avería / Mantenimiento', campos: meta.man || [] });
        }
        return grupos;
    }

    function campoFichaHTML(campo, valor) {
        if (campo.grupo) {
            return `<div class="pd-ficha-subgrupo">${escH(campo.grupo)}</div>`;
        }
        const val = valor !== undefined && valor !== null ? valor : '';
        if (campo.type === 'boolean') {
            const on = !!val;
            return `
            <label class="pd-ficha-campo pd-ficha-campo--bool">
                <span class="pd-ficha-campo-label">${escH(campo.label)}</span>
                <span class="pd-ficha-toggle${on ? ' pd-ficha-toggle--on' : ''}" data-campo="${campo.name}" data-tipo="boolean"
                      role="checkbox" aria-checked="${on ? 'true' : 'false'}" tabindex="0">
                    <span class="pd-ficha-toggle-knob"></span>
                </span>
            </label>`;
        }
        if (campo.type === 'multiselect') {
            const seleccionados = Array.isArray(val) ? val : [];
            const opts = (campo.options || []).map(o => `
                <label class="pd-ficha-multiopt${seleccionados.includes(o) ? ' pd-ficha-multiopt--on' : ''}">
                    <input type="checkbox" data-campo="${campo.name}" data-tipo="multiselect" value="${escH(o)}"${seleccionados.includes(o) ? ' checked' : ''}>
                    <span>${escH(o)}</span>
                </label>`).join('');
            return `
            <div class="pd-ficha-campo pd-ficha-campo--multiselect">
                <span class="pd-ficha-campo-label">${escH(campo.label)}</span>
                <div class="pd-ficha-multiselect-opts">${opts}</div>
            </div>`;
        }
        if (campo.type === 'select') {
            const opts = (campo.options || []).map(o =>
                `<option value="${escH(o)}"${val === o ? ' selected' : ''}>${escH(o)}</option>`
            ).join('');
            return `
            <label class="pd-ficha-campo">
                <span class="pd-ficha-campo-label">${escH(campo.label)}</span>
                <select class="pd-ficha-input pd-ficha-select" data-campo="${campo.name}" data-tipo="select">
                    <option value="">—</option>
                    ${opts}
                </select>
            </label>`;
        }
        if (campo.type === 'number') {
            return `
            <label class="pd-ficha-campo">
                <span class="pd-ficha-campo-label">${escH(campo.label)}</span>
                <input type="number" inputmode="decimal" step="any" class="pd-ficha-input" data-campo="${campo.name}" data-tipo="number" value="${val === '' ? '' : escH(val)}">
            </label>`;
        }
        // text (por defecto)
        return `
        <label class="pd-ficha-campo">
            <span class="pd-ficha-campo-label">${escH(campo.label)}</span>
            <input type="text" class="pd-ficha-input" data-campo="${campo.name}" data-tipo="text" value="${escH(val)}">
        </label>`;
    }

    function abrirFichaModal(ln, idx) {
        if (!ln) return;

        if (!fichaModalEl) {
            fichaModalEl = document.createElement('div');
            fichaModalEl.className = 'pd-ficha-modal';
            document.body.appendChild(fichaModalEl);
            fichaModalEl.addEventListener('click', e => {
                if (e.target === fichaModalEl || e.target.closest('.pd-ficha-modal-close')) cerrarFichaModal();
            });
        }

        const grupos = gruposFichaAplicables(tipoAlbaranActual);
        const ficha  = ln.ficha || {};

        const cuerpo = grupos.map(g => `
            ${g.titulo ? `<div class="pd-ficha-grupo-hdr">${escH(g.titulo)}</div>` : ''}
            ${g.campos.map(c => campoFichaHTML(c, ficha[c.name])).join('')}
        `).join('');

        fichaModalEl.innerHTML = `
            <div class="pd-ficha-modal-box">
                <div class="pd-ficha-modal-hdr">
                    <span>📋 ${escH(ln.label || 'Ficha técnica')}</span>
                    <button type="button" class="pd-ficha-modal-close" aria-label="Cerrar">✕</button>
                </div>
                <div class="pd-ficha-modal-body">${cuerpo}</div>
                <div class="pd-ficha-modal-footer">
                    <button type="button" class="pd-ficha-guardar-btn" id="pd-ficha-guardar-btn">💾 Guardar ficha técnica</button>
                    <div class="pd-ficha-feedback" id="pd-ficha-feedback"></div>
                </div>
            </div>`;

        // Los toggles booleanos no son <input> nativos: se gestionan a mano
        fichaModalEl.querySelectorAll('.pd-ficha-toggle').forEach(tg => {
            const activar = () => {
                const on = !tg.classList.contains('pd-ficha-toggle--on');
                tg.classList.toggle('pd-ficha-toggle--on', on);
                tg.setAttribute('aria-checked', on ? 'true' : 'false');
            };
            tg.addEventListener('click', activar);
            tg.addEventListener('keydown', e => {
                if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); activar(); }
            });
        });

        // Chips de selección múltiple (p.ej. tuberías en pulgadas): sincronizar
        // el estilo de "pill activa" con el estado real del checkbox nativo
        fichaModalEl.querySelectorAll('.pd-ficha-multiopt input[type="checkbox"]').forEach(chk => {
            chk.addEventListener('change', () => {
                chk.closest('.pd-ficha-multiopt')?.classList.toggle('pd-ficha-multiopt--on', chk.checked);
            });
        });

        document.getElementById('pd-ficha-guardar-btn')?.addEventListener('click', () => guardarFichaTecnica(idx));

        requestAnimationFrame(() => fichaModalEl.classList.add('pd-ficha-modal--open'));
    }

    function cerrarFichaModal() {
        fichaModalEl?.classList.remove('pd-ficha-modal--open');
    }

    async function guardarFichaTecnica(idx) {
        const ln = lineasActuales[idx];
        if (!ln || !fichaModalEl) return;
        const btn      = document.getElementById('pd-ficha-guardar-btn');
        const feedback = document.getElementById('pd-ficha-feedback');

        const campos = {};
        fichaModalEl.querySelectorAll('[data-campo]').forEach(el => {
            const nombre = el.dataset.campo;
            const tipo   = el.dataset.tipo;
            if (tipo === 'boolean') {
                campos[nombre] = el.classList.contains('pd-ficha-toggle--on');
            } else if (tipo === 'multiselect') {
                // Varios checkboxes comparten el mismo data-campo: acumular los marcados
                if (!Array.isArray(campos[nombre])) campos[nombre] = [];
                if (el.checked) campos[nombre].push(el.value);
            } else {
                campos[nombre] = el.value;
            }
        });

        btn.disabled    = true;
        btn.textContent = '⏳ Guardando…';

        const payload = { linea_id: ln.rowid, parte_id: parteIdActual, campos };
        const url = `${window.PT_BASE}/index.php?pt_do=linea_ficha`;

        try {
            if (navigator.onLine) {
                const res = await fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (!res.ok || data.error) throw new Error(data.error || 'Error del servidor');

                ln.ficha = Object.assign({}, ln.ficha, campos);
                actualizarBadgeFicha(idx, ln.ficha);
                if (typeof window.showToast === 'function') window.showToast('✅ Ficha técnica guardada', 'success');
                cerrarFichaModal();
            } else {
                if (typeof window.ptSaveAccionOffline === 'function') {
                    await window.ptSaveAccionOffline('linea_ficha', ln.rowid, payload, url);
                } else if (typeof PtDB !== 'undefined') {
                    await PtDB.saveAccion({ tipo: 'linea_ficha', parte_id: ln.rowid, payload, url, method: 'POST' });
                }
                ln.ficha = Object.assign({}, ln.ficha, campos);
                actualizarBadgeFicha(idx, ln.ficha);
                cerrarFichaModal();
            }
        } catch (err) {
            feedback.textContent = '❌ Error: ' + err.message;
            feedback.className   = 'pd-ficha-feedback pd-feedback--error';
            btn.disabled    = false;
            btn.textContent = '💾 Guardar ficha técnica';
        }
    }

    function actualizarBadgeFicha(idx, ficha) {
        const btn = panel.querySelector(`.pd-tarea-ficha-btn[data-idx="${idx}"]`);
        if (!btn) return;
        const n = contarCamposFichaRellenos(ficha);
        let badge = btn.querySelector('.pd-tarea-ficha-badge');
        if (n > 0) {
            if (!badge) {
                badge = document.createElement('span');
                badge.className = 'pd-tarea-ficha-badge';
                btn.insertBefore(badge, btn.querySelector('.pd-tarea-ficha-arrow'));
            }
            badge.textContent = `${n} rellenos`;
        } else {
            badge?.remove();
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Fotos del trabajo (hasta 10, foto_1..foto_10)
    // ══════════════════════════════════════════════════════════════════════════

    function wireFotos(parteId, p) {
        const grid  = panel.querySelector('#pd-fotos-grid');
        const input = panel.querySelector('#pd-foto-input');
        const subt  = panel.querySelector('#pd-fotos-subtitulo');
        if (!grid || !input) return;

        const actualizarContador = () => {
            const n = grid.querySelectorAll('.pd-foto-slot--filled').length;
            if (subt) subt.textContent = `${n} / 10`;
        };

        // Click en un hueco vacío → abrir selector de archivo (cámara o galería)
        grid.addEventListener('click', e => {
            const vacio = e.target.closest('.pd-foto-slot--empty');
            if (vacio) {
                input.dataset.slot = vacio.dataset.slot;
                input.click();
                return;
            }
            // Click en la miniatura (no en el botón ✕) → ver en grande
            const thumb = e.target.closest('.pd-foto-thumb');
            if (thumb && !e.target.closest('.pd-foto-del')) {
                abrirLightbox(thumb.src);
                return;
            }
            // Click en ✕ → eliminar
            const del = e.target.closest('.pd-foto-del');
            if (del) {
                eliminarFoto(parteId, del.dataset.slot, grid, actualizarContador);
            }
        });

        // Selección de archivo → comprimir y guardar
        input.addEventListener('change', async () => {
            const file = input.files && input.files[0];
            const slot = input.dataset.slot;
            input.value = ''; // permite volver a elegir el mismo archivo más tarde
            if (!file || !slot) return;

            if (!file.type.startsWith('image/')) {
                if (typeof window.showToast === 'function') {
                    window.showToast('⚠️ Selecciona un archivo de imagen', 'warn', 4000);
                }
                return;
            }

            const slotEl = grid.querySelector(`.pd-foto-slot[data-slot="${slot}"]`);
            if (slotEl) {
                slotEl.classList.add('pd-foto-slot--loading');
                slotEl.innerHTML = `<span class="pd-foto-spinner"></span>`;
            }

            try {
                const dataUrl = await comprimirImagen(file);

                // Reemplazar el hueco por la miniatura ya comprimida (optimista)
                const nuevo = document.createElement('div');
                nuevo.className = 'pd-foto-slot pd-foto-slot--filled';
                nuevo.dataset.slot = slot;
                nuevo.innerHTML = `
                    <img src="${dataUrl}" class="pd-foto-thumb" data-slot="${slot}" alt="Foto ${slot} del parte">
                    <button type="button" class="pd-foto-del" data-slot="${slot}" aria-label="Eliminar foto ${slot}">✕</button>`;
                slotEl?.replaceWith(nuevo);
                actualizarContador();

                await guardarProgreso({ id: parteId, ['foto_' + slot]: dataUrl });
            } catch (err) {
                console.error('[PT] Error al procesar la foto:', err);
                if (typeof window.showToast === 'function') {
                    window.showToast('❌ No se pudo procesar la foto: ' + err.message, 'error', 6000);
                }
                // Revertir el hueco a vacío si falló la compresión (no si falló solo el guardado:
                // guardarProgreso ya encola offline y avisa por su cuenta en ese caso)
                const actual = grid.querySelector(`.pd-foto-slot[data-slot="${slot}"]`);
                if (actual && actual.classList.contains('pd-foto-slot--loading')) {
                    const vacio = document.createElement('button');
                    vacio.type = 'button';
                    vacio.className = 'pd-foto-slot pd-foto-slot--empty';
                    vacio.dataset.slot = slot;
                    vacio.setAttribute('aria-label', 'Añadir foto ' + slot);
                    vacio.innerHTML = `<span class="pd-foto-add-ico">📷</span>`;
                    actual.replaceWith(vacio);
                }
            }
        });
    }

    async function eliminarFoto(parteId, slot, grid, actualizarContador) {
        if (!confirm('¿Eliminar esta foto?')) return;

        const slotEl = grid.querySelector(`.pd-foto-slot[data-slot="${slot}"]`);
        const vacio = document.createElement('button');
        vacio.type = 'button';
        vacio.className = 'pd-foto-slot pd-foto-slot--empty';
        vacio.dataset.slot = slot;
        vacio.setAttribute('aria-label', 'Añadir foto ' + slot);
        vacio.innerHTML = `<span class="pd-foto-add-ico">📷</span>`;
        slotEl?.replaceWith(vacio);
        actualizarContador();

        await guardarProgreso({ id: parteId, ['foto_' + slot]: '' });
    }

    /** Redimensiona y comprime una imagen en el navegador antes de subirla,
     *  para no disparar el tamaño del registro en BD ni el tráfico offline.
     *  Respeta la orientación EXIF cuando el navegador lo soporta. */
    async function comprimirImagen(file, maxDim = 1280, calidad = 0.72) {
        let bitmap = null;
        if (typeof createImageBitmap === 'function') {
            try {
                bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
            } catch {
                try { bitmap = await createImageBitmap(file); } catch { bitmap = null; }
            }
        }

        const fuente = bitmap || await cargarImagenLegacy(file);
        let width  = bitmap ? bitmap.width  : fuente.naturalWidth;
        let height = bitmap ? bitmap.height : fuente.naturalHeight;

        if (width > maxDim || height > maxDim) {
            if (width >= height) {
                height = Math.round(height * (maxDim / width));
                width  = maxDim;
            } else {
                width  = Math.round(width * (maxDim / height));
                height = maxDim;
            }
        }

        const canvas = document.createElement('canvas');
        canvas.width  = width;
        canvas.height = height;
        const ctx = canvas.getContext('2d');
        ctx.drawImage(fuente, 0, 0, width, height);
        if (bitmap && typeof bitmap.close === 'function') bitmap.close();

        return canvas.toDataURL('image/jpeg', calidad);
    }

    /** Fallback para navegadores sin createImageBitmap: carga vía <img> + FileReader. */
    function cargarImagenLegacy(file) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onerror = () => reject(new Error('No se pudo leer el archivo'));
            reader.onload = () => {
                const img = new Image();
                img.onerror = () => reject(new Error('Archivo de imagen no válido'));
                img.onload  = () => resolve(img);
                img.src = reader.result;
            };
            reader.readAsDataURL(file);
        });
    }

    // ── Lightbox: ver una foto a pantalla completa ────────────────────────────
    let lightboxEl = null;
    function abrirLightbox(src) {
        if (!lightboxEl) {
            lightboxEl = document.createElement('div');
            lightboxEl.className = 'pd-foto-lightbox';
            lightboxEl.innerHTML = `
                <button type="button" class="pd-foto-lightbox-close" aria-label="Cerrar">✕</button>
                <img class="pd-foto-lightbox-img" alt="Foto ampliada">`;
            lightboxEl.addEventListener('click', e => {
                if (e.target === lightboxEl || e.target.closest('.pd-foto-lightbox-close')) {
                    cerrarLightbox();
                }
            });
            document.body.appendChild(lightboxEl);
        }
        lightboxEl.querySelector('.pd-foto-lightbox-img').src = src;
        requestAnimationFrame(() => lightboxEl.classList.add('pd-foto-lightbox--open'));
    }
    function cerrarLightbox() {
        lightboxEl?.classList.remove('pd-foto-lightbox--open');
    }

    function wireNota(parteId) {
        const btn      = panel.querySelector('#pd-nota-save');
        const textarea = panel.querySelector('#pd-nota-input');
        const feedback = panel.querySelector('#pd-nota-feedback');
        const lista    = panel.querySelector('#pd-notas-lista');
        if (!btn || !textarea) return;

        btn.addEventListener('click', async () => {
            const texto = textarea.value.trim();
            if (!texto) {
                showFeedback(feedback, '⚠️ Escribe algo antes de guardar.', 'warn');
                return;
            }

            btn.disabled    = true;
            btn.textContent = '⏳ Guardando…';

            const payload = { id: parteId, nota: texto };
            const url = `${window.PT_BASE}/index.php?pt_do=nota`;

            try {
                if (navigator.onLine) {
                    const res = await fetch(url, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: JSON.stringify(payload)
                    });
                    const data = await res.json();
                    if (!res.ok || data.error) throw new Error(data.error || 'Error del servidor');

                    showFeedback(feedback, '✅ Anotación guardada correctamente.', 'ok');
                    textarea.value = '';
                    lista?.querySelector('.pd-notas-vacio')?.remove();
                    lista?.insertAdjacentHTML('afterbegin', notaItemHTML(data.nota));
                } else {
                    // Sin conexión: encolar (mismo mecanismo que el resto de la app)
                    // y mostrar la nota de inmediato con un ⏳ hasta que sincronice.
                    if (typeof window.ptSaveAccionOffline === 'function') {
                        await window.ptSaveAccionOffline('nota', parteId, payload, url);
                    } else if (typeof PtDB !== 'undefined') {
                        await PtDB.saveAccion({ tipo: 'nota', parte_id: parteId, payload, url, method: 'POST' });
                    }
                    textarea.value = '';
                    lista?.querySelector('.pd-notas-vacio')?.remove();
                    lista?.insertAdjacentHTML('afterbegin', notaItemHTML({
                        id: 'tmp-' + Date.now(),
                        texto,
                        autor_nombre: 'Tú',
                        fecha: '⏳ pendiente de sincronizar',
                        editada: false,
                        editable: false, // no se puede editar hasta que el servidor le asigne un ID real
                    }));
                }
            } catch (err) {
                showFeedback(feedback, '❌ Error: ' + err.message, 'error');
            } finally {
                btn.disabled    = false;
                btn.textContent = '💾 Guardar anotación';
            }
        });
    }

    function notaItemHTML(n) {
        return `
            <div class="pd-nota-item" data-nota-id="${n.id}">
                <div class="pd-nota-item-meta">
                    <span class="pd-nota-item-autor">${escH(n.autor_nombre || '—')}</span>
                    <span class="pd-nota-item-fecha">${escH(n.fecha || '')}${n.editada ? ' · editado' : ''}</span>
                    ${n.editable ? `<button type="button" class="pd-nota-edit-btn" data-nota-id="${n.id}">✏️ Editar</button>` : ''}
                </div>
                <div class="pd-nota-item-texto" id="pd-nota-texto-${n.id}">${escH(n.texto)}</div>
            </div>`;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Edición de anotaciones propias (el técnico solo edita las suyas; el
    // servidor vuelve a comprobar la autoría antes de guardar el cambio)
    // ══════════════════════════════════════════════════════════════════════════

    function wireNotas(parteId) {
        const lista = panel.querySelector('#pd-notas-lista');
        if (!lista) return;

        lista.addEventListener('click', e => {
            const editBtn = e.target.closest('.pd-nota-edit-btn');
            if (editBtn) {
                iniciarEdicionNota(editBtn.dataset.notaId);
                return;
            }
            const saveBtn = e.target.closest('.pd-nota-edit-save');
            if (saveBtn) {
                guardarEdicionNota(parteId, saveBtn.dataset.notaId);
                return;
            }
            const cancelBtn = e.target.closest('.pd-nota-edit-cancel');
            if (cancelBtn) {
                cancelarEdicionNota(cancelBtn.dataset.notaId);
            }
        });
    }

    function iniciarEdicionNota(notaId) {
        const item = panel.querySelector(`.pd-nota-item[data-nota-id="${notaId}"]`);
        const textoEl = document.getElementById(`pd-nota-texto-${notaId}`);
        if (!item || !textoEl) return;
        if (item.classList.contains('pd-nota-item--editando')) return; // ya está editando

        item.dataset.textoOriginal = textoEl.textContent;
        item.classList.add('pd-nota-item--editando');
        textoEl.outerHTML = `
            <div class="pd-nota-edit-wrap" id="pd-nota-texto-${notaId}">
                <textarea class="pd-textarea pd-nota-edit-textarea" rows="3">${escH(item.dataset.textoOriginal)}</textarea>
                <div class="pd-nota-edit-actions">
                    <button type="button" class="pd-nota-edit-save" data-nota-id="${notaId}">💾 Guardar</button>
                    <button type="button" class="pd-nota-edit-cancel" data-nota-id="${notaId}">Cancelar</button>
                </div>
                <div class="pd-nota-edit-feedback"></div>
            </div>`;
        item.querySelector('.pd-nota-edit-textarea')?.focus();
    }

    function cancelarEdicionNota(notaId) {
        const item = panel.querySelector(`.pd-nota-item[data-nota-id="${notaId}"]`);
        const wrap = document.getElementById(`pd-nota-texto-${notaId}`);
        if (!item || !wrap) return;
        wrap.outerHTML = `<div class="pd-nota-item-texto" id="pd-nota-texto-${notaId}">${escH(item.dataset.textoOriginal || '')}</div>`;
        item.classList.remove('pd-nota-item--editando');
    }

    async function guardarEdicionNota(parteId, notaId) {
        const item = panel.querySelector(`.pd-nota-item[data-nota-id="${notaId}"]`);
        const wrap = document.getElementById(`pd-nota-texto-${notaId}`);
        if (!item || !wrap) return;

        const textarea = wrap.querySelector('.pd-nota-edit-textarea');
        const saveBtn   = wrap.querySelector('.pd-nota-edit-save');
        const feedback  = wrap.querySelector('.pd-nota-edit-feedback');
        const texto = textarea.value.trim();

        if (!texto) {
            feedback.textContent = '⚠️ La anotación no puede quedar vacía.';
            feedback.className   = 'pd-nota-edit-feedback pd-feedback--warn';
            return;
        }

        saveBtn.disabled    = true;
        saveBtn.textContent = '⏳ Guardando…';

        const payload = { id_nota: notaId, nota: texto };
        const url = `${window.PT_BASE}/index.php?pt_do=editar_nota`;

        try {
            if (navigator.onLine) {
                const res = await fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (!res.ok || data.error) throw new Error(data.error || 'Error del servidor');

                item.dataset.textoOriginal = texto;
                wrap.outerHTML = `<div class="pd-nota-item-texto" id="pd-nota-texto-${notaId}">${escH(texto)}</div>`;
                item.classList.remove('pd-nota-item--editando');

                // Marcar como editada en la cabecera de la nota
                const fechaEl = item.querySelector('.pd-nota-item-fecha');
                if (fechaEl && !fechaEl.textContent.includes('editado')) {
                    fechaEl.textContent += ' · editado';
                }
                if (typeof window.showToast === 'function') {
                    window.showToast('✅ Anotación actualizada', 'success');
                }
            } else {
                if (typeof window.ptSaveAccionOffline === 'function') {
                    await window.ptSaveAccionOffline('editar_nota', notaId, payload, url);
                } else if (typeof PtDB !== 'undefined') {
                    await PtDB.saveAccion({ tipo: 'editar_nota', parte_id: notaId, payload, url, method: 'POST' });
                }
                item.dataset.textoOriginal = texto;
                wrap.outerHTML = `<div class="pd-nota-item-texto" id="pd-nota-texto-${notaId}">${escH(texto)}</div>`;
                item.classList.remove('pd-nota-item--editando');
            }
        } catch (err) {
            feedback.textContent = '❌ Error: ' + err.message;
            feedback.className   = 'pd-nota-edit-feedback pd-feedback--error';
            saveBtn.disabled    = false;
            saveBtn.textContent = '💾 Guardar';
        }
    }

    function showFeedback(el, msg, type) {
        if (!el) return;
        el.textContent  = msg;
        el.className    = 'pd-nota-feedback pd-feedback--' + type;
        el.style.display = 'block';
        setTimeout(() => { if (el) el.style.display = 'none'; }, 5000);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Skeleton y Error
    // ══════════════════════════════════════════════════════════════════════════
    function skeletonHTML() {
        return `
        <div class="pd-header">
            <div class="pd-drag-bar"><span></span></div>
            <div class="pd-header-top">
                <span class="pd-skeleton" style="width:110px;height:22px;border-radius:999px;display:inline-block"></span>
                <button class="pd-close-btn">✕</button>
            </div>
            <span class="pd-skeleton" style="width:180px;height:28px;border-radius:6px;display:block;margin-top:8px"></span>
        </div>
        <div class="pd-action-bar">
            <span class="pd-skeleton" style="flex:1;height:64px;border-radius:14px;display:block"></span>
            <span class="pd-skeleton" style="flex:1;height:64px;border-radius:14px;display:block"></span>
        </div>
        <div class="pd-body" style="gap:10px;display:flex;flex-direction:column;padding:16px">
            ${[1,2,3].map(() => `
            <div class="pd-section">
                <div class="pd-section-hdr" style="cursor:default">
                    <span class="pd-skeleton" style="width:34px;height:34px;border-radius:10px;display:block;flex-shrink:0"></span>
                    <span class="pd-skeleton" style="flex:1;height:16px;border-radius:4px;display:block"></span>
                </div>
            </div>`).join('')}
        </div>`;
    }

    function renderError(msg) {
        panel.innerHTML = `
        <div class="pd-header">
            <div class="pd-drag-bar"><span></span></div>
            <div class="pd-header-top">
                <span></span>
                <button class="pd-close-btn">✕</button>
            </div>
            <div class="pd-header-ref" style="font-size:1rem">Error al cargar</div>
        </div>
        <div class="pd-body" style="display:flex;align-items:center;justify-content:center">
            <div style="text-align:center;padding:40px 24px">
                <div style="font-size:3rem;margin-bottom:16px">⚠️</div>
                <div style="font-weight:700;font-size:1rem;color:#1A202C;margin-bottom:8px">No se pudo cargar el parte</div>
                <div style="font-size:.85rem;color:#64748B;margin-bottom:24px">${escH(msg)}</div>
                <button id="pd-retry" style="padding:10px 28px;background:#1D4ED8;color:#fff;border:none;border-radius:10px;font-size:.9rem;font-weight:600;cursor:pointer">Reintentar</button>
            </div>
        </div>`;
        panel.querySelector('.pd-close-btn').addEventListener('click', close);
        panel.querySelector('#pd-retry').addEventListener('click', () => open(currentId));
    }


    // ══════════════════════════════════════════════════════════════════════════
    // Canvas de firma + botón Terminar
    // ══════════════════════════════════════════════════════════════════════════
    function wireFirmas(parteId, p) {
        // Inicializar ambos canvas
        ['pd-canvas-cliente', 'pd-canvas-tecnico'].forEach(id => {
            initCanvas(id);
        });

        // Si ya existen firmas guardadas, precargarlas en el canvas y bloquear
        if (p.firma_cliente) cargarFirmaExistente('cliente', p.firma_cliente);
        if (p.firma_tecnico) cargarFirmaExistente('tecnico', p.firma_tecnico);

        // Autorellenar hora-inicio al confirmar firma del técnico
        // Autorellenar hora-fin al confirmar firma del cliente
        panel.querySelectorAll('.pd-firma-confirm').forEach(btn => {
            btn.addEventListener('click', () => {
                const target = btn.dataset.target;
                const now = new Date();
                // Format: YYYY-MM-DDTHH:MM
                const fmt = now.getFullYear() + '-'
                    + String(now.getMonth()+1).padStart(2,'0') + '-'
                    + String(now.getDate()).padStart(2,'0') + 'T'
                    + String(now.getHours()).padStart(2,'0') + ':'
                    + String(now.getMinutes()).padStart(2,'0');

                if (target === 'tecnico') {
                    const inp = panel.querySelector('#pd-hora-inicio');
                    if (inp && !inp.value) { inp.value = fmt; actualizarBotonTerminar(); }
                }
                if (target === 'cliente') {
                    const inp = panel.querySelector('#pd-hora-fin');
                    if (inp && !inp.value) { inp.value = fmt; actualizarBotonTerminar(); }
                }
            }, { capture: true }); // capture=true para que se ejecute ANTES de lockFirma
        });

        // Botones Editar / Eliminar (delegados en el panel)
        panel.addEventListener('click', e => {
            const editBtn = e.target.closest('.pd-firma-btn--edit');
            const delBtn  = e.target.closest('.pd-firma-btn--del');
            if (editBtn) {
                unlockFirma(editBtn.dataset.target, false);   // desbloquear sin borrar
            }
            if (delBtn) {
                unlockFirma(delBtn.dataset.target, true);     // desbloquear y borrar
            }
        });

        // Actualizar botón al escribir nombre/dni/horas
        ['pd-nombre-cliente', 'pd-dni-cliente', 'pd-hora-inicio', 'pd-hora-fin'].forEach(id => {
            panel.querySelector('#' + id)?.addEventListener('input', actualizarBotonTerminar);
            panel.querySelector('#' + id)?.addEventListener('change', actualizarBotonTerminar);
        });

        // Persistir cualquier cambio en estos campos al perder el foco (blur/change),
        // así no se pierde nada si el técnico sale del albarán sin terminarlo.
        const camposAGuardar = {
            'pd-nombre-cliente': 'nombre_cliente',
            'pd-dni-cliente':    'dni_cliente',
            'pd-hora-inicio':    'horadeinicio',
            'pd-hora-fin':       'horadefin',
        };
        Object.entries(camposAGuardar).forEach(([id, campo]) => {
            panel.querySelector('#' + id)?.addEventListener('change', e => {
                const valor = e.target.value || '';
                guardarProgreso({ id: parteId, [campo]: valor });
            });
        });

        const btnTerminar  = panel.querySelector('#pd-terminar');
        const feedback     = panel.querySelector('#pd-terminar-feedback');
        if (!btnTerminar) return;

        btnTerminar.addEventListener('click', async () => {
            const nombreCliente = (panel.querySelector('#pd-nombre-cliente')?.value || '').trim();
            const dniCliente    = (panel.querySelector('#pd-dni-cliente')?.value    || '').trim();
            const canvasCliente = panel.querySelector('#pd-canvas-cliente');
            const canvasTecnico = panel.querySelector('#pd-canvas-tecnico');

            // Validación final (el botón ya debería estar deshabilitado si falta algo)
            if (tareasTotal > 0 && tareasCompletadas.size < tareasTotal) {
                showFeedback(feedback, '⚠️ Completa todas las tareas antes de terminar.', 'warn');
                return;
            }
            if (!nombreCliente || !dniCliente || !canvasCliente?.dataset.signed || !canvasTecnico?.dataset.signed) {
                showFeedback(feedback, '⚠️ Completa todos los campos y firmas.', 'warn');
                return;
            }

            btnTerminar.disabled    = true;
            btnTerminar.textContent = '⏳ Enviando…';

            const payload = {
                id:             parteId,
                firma_cliente:  canvasCliente.toDataURL('image/png'),
                nombre_cliente: nombreCliente,
                dni_cliente:    dniCliente,
                firma_tecnico:  canvasTecnico.toDataURL('image/png'),
                horadeinicio:   (panel.querySelector('#pd-hora-inicio')?.value || ''),
                horadefin:      (panel.querySelector('#pd-hora-fin')?.value    || ''),
            };

            try {
                if (navigator.onLine) {
                    const res = await fetch(`${window.PT_BASE}/index.php?pt_do=terminar`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        credentials: 'same-origin',
                        body: JSON.stringify(payload)
                    });
                    const data = await res.json();
                    if (!res.ok || data.error) throw new Error(data.error || 'Error del servidor');

                    showFeedback(feedback, '✅ Parte terminado y firmado correctamente.', 'ok');
                    btnTerminar.textContent = '✅ Parte terminado';
                    btnTerminar.style.background = '#16A34A';

                    // Actualizar cache local
                    await PtDB.setMeta('detalle_' + parteId, null);

                    // Cerrar el panel y refrescar el listado: el parte pasa a
                    // estado "Terminado" y desaparece de la vista del técnico.
                    setTimeout(() => {
                        close();
                        if (typeof window.loadFromServer === 'function') {
                            window.loadFromServer();
                        } else if (typeof window.refreshFromCache === 'function') {
                            window.refreshFromCache();
                        }
                    }, 1200);
                } else {
                    await PtDB.saveAccion('terminar', parteId, payload,
                        `${window.PT_BASE}/index.php?pt_do=terminar`, 'POST');
                    showFeedback(feedback, '💾 Guardado localmente. Se enviará al recuperar la conexión.', 'warn');
                    btnTerminar.textContent = '💾 Pendiente de envío';
                }
            } catch (err) {
                showFeedback(feedback, '❌ ' + err.message, 'error');
                btnTerminar.disabled    = false;
                btnTerminar.textContent = '✅ Terminar parte';
            }
        });
    }

    function initCanvas(canvasId) {
        const canvas = panel.querySelector('#' + canvasId);
        if (!canvas) return;

        const targetName = canvasId.replace('pd-canvas-', ''); // 'cliente' o 'tecnico'
        const wrap       = canvas.parentElement;
        const confirmBtn = panel.querySelector('#confirm-firma-' + targetName);
        const lockEl     = panel.querySelector('#lock-' + targetName);
        const actionsEl  = panel.querySelector('#actions-firma-' + targetName);
        const badgeEl    = panel.querySelector('#badge-firma-' + targetName);

        // Tamaño canvas al contenedor
        canvas.width  = wrap.clientWidth || 320;
        canvas.height = 180;

        const ctx = canvas.getContext('2d');
        ctx.strokeStyle = '#1A202C';
        ctx.lineWidth   = 2.5;
        ctx.lineCap     = 'round';
        ctx.lineJoin    = 'round';

        let drawing = false;
        let hasTrazos = false;  // si se ha dibujado algo

        function getPos(e) {
            const rect = canvas.getBoundingClientRect();
            const src  = e.touches ? e.touches[0] : e;
            return {
                x: (src.clientX - rect.left) * (canvas.width  / rect.width),
                y: (src.clientY - rect.top)  * (canvas.height / rect.height),
            };
        }

        function startDraw(e) {
            if (canvas.dataset.locked) return;
            e.preventDefault();
            e.stopPropagation();
            drawing = true;
            const pos = getPos(e);
            ctx.beginPath();
            ctx.moveTo(pos.x, pos.y);
        }

        function draw(e) {
            if (!drawing || canvas.dataset.locked) return;
            e.preventDefault();
            e.stopPropagation();
            const pos = getPos(e);
            ctx.lineTo(pos.x, pos.y);
            ctx.stroke();
            if (!hasTrazos) {
                hasTrazos = true;
                // Mostrar botón "Confirmar firma" al primer trazo
                if (confirmBtn) confirmBtn.style.display = 'block';
            }
        }

        function stopDraw(e) {
            if (!drawing) return;
            drawing = false;
            if (e) { e.preventDefault(); e.stopPropagation(); }
        }

        canvas.addEventListener('mousedown',  startDraw);
        canvas.addEventListener('mousemove',  draw);
        canvas.addEventListener('mouseup',    stopDraw);
        canvas.addEventListener('mouseleave', stopDraw);
        canvas.addEventListener('touchstart', startDraw, { passive: false });
        canvas.addEventListener('touchmove',  draw,      { passive: false });
        canvas.addEventListener('touchend',   stopDraw,  { passive: false });

        // ── Botón "Confirmar firma" ────────────────────────────────────────────
        if (confirmBtn) {
            confirmBtn.addEventListener('click', () => {
                if (!hasTrazos) return;
                lockFirma(targetName);
            });
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Guardado incremental de progreso (firma técnico, horas, etc.)
    // Persiste de inmediato en BD para no perder nada si el técnico sale
    // del albarán antes de pulsar "Terminar parte".
    // ══════════════════════════════════════════════════════════════════════════
    async function guardarProgreso(payload) {
        // Si NO hay conexión, encolar directamente sin intentar la petición
        if (!navigator.onLine) {
            await encolarProgreso(payload, 'Sin conexión');
            return;
        }

        let res, data;
        try {
            res = await fetch(`${window.PT_BASE}/index.php?pt_do=progreso`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'same-origin',
                body: JSON.stringify(payload)
            });
        } catch (networkErr) {
            // Fallo de RED real (sin respuesta del servidor) → encolar offline
            await encolarProgreso(payload, networkErr.message);
            return;
        }

        // El servidor SÍ respondió — leer el resultado
        try {
            data = await res.json();
        } catch {
            data = { error: 'Respuesta no válida del servidor (HTTP ' + res.status + ')' };
        }

        if (!res.ok || data.error) {
            // Error del SERVIDOR (403/500/validación) — NO es un problema de conexión.
            // Mostrarlo de forma MUY visible (toast flotante, no depende del scroll)
            // porque reintentarlo más tarde fallaría exactamente igual.
            const msg = '❌ No se pudo guardar: ' + (data.error || ('HTTP ' + res.status));
            console.error('[PT] Error al guardar progreso:', data.error || res.status, payload);

            if (typeof window.showToast === 'function') {
                window.showToast(msg, 'error', 8000);
            } else {
                alert(msg);
            }
            // También en el feedback inline por si está visible
            showFeedback(panel.querySelector('#pd-terminar-feedback'), msg, 'error');
            return;
        }

        console.log('[PT] Progreso guardado en BD:', data.campos_guardados);
        if (typeof window.showToast === 'function') {
            window.showToast('✅ Guardado en el servidor', 'success', 2000);
        }
    }

    async function encolarProgreso(payload, motivo) {
        if (typeof window.ptSaveAccionOffline === 'function') {
            await window.ptSaveAccionOffline(
                'progreso', payload.id, payload,
                `${window.PT_BASE}/index.php?pt_do=progreso`, 'POST'
            );
        } else {
            await PtDB.saveAccion({
                tipo: 'progreso', parte_id: payload.id, payload,
                url: `${window.PT_BASE}/index.php?pt_do=progreso`, method: 'POST'
            });
        }
        console.warn('[PT] Progreso encolado offline (' + motivo + '):', payload);
    }

    function cargarFirmaExistente(name, dataUrl) {
        const canvas = panel.querySelector('#pd-canvas-' + name);
        if (!canvas || !dataUrl) return;

        const img = new Image();
        img.onload = () => {
            const ctx = canvas.getContext('2d');
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
            // Bloquear inmediatamente: ya estaba firmado al abrir el parte.
            // persist=false: el dato ya viene de la BD, no hace falta reenviarlo.
            lockFirma(name, false);
        };
        img.src = dataUrl;
    }

    function lockFirma(name, persist = true) {
        const canvas    = panel.querySelector('#pd-canvas-' + name);
        const wrap      = canvas?.parentElement;
        const lockEl    = panel.querySelector('#lock-' + name);
        const actionsEl = panel.querySelector('#actions-firma-' + name);
        const confirmBtn= panel.querySelector('#confirm-firma-' + name);
        const badgeEl   = panel.querySelector('#badge-firma-' + name);
        const sectionEl = panel.querySelector('#sec-wrap-firma-' + name);

        if (!canvas) return;

        // Bloquear
        canvas.dataset.locked = '1';
        canvas.dataset.signed = '1';
        canvas.style.pointerEvents = 'none';
        canvas.style.touchAction   = 'auto'; // liberar scroll al bloquear
        if (wrap) wrap.classList.add('locked');
        if (lockEl)    lockEl.classList.add('visible');
        if (confirmBtn) confirmBtn.style.display = 'none';
        if (actionsEl) actionsEl.style.display  = 'flex';
        if (badgeEl) {
            badgeEl.textContent  = '✓ Firmado';
            badgeEl.className    = 'pd-firma-badge pd-firma-badge--ok';
        }
        if (sectionEl) sectionEl.classList.add('pd-section--firmada');

        actualizarBotonTerminar();

        // Guardar inmediatamente en BD para no perder la firma si el técnico sale
        if (persist) {
            const dataUrl = canvas.toDataURL('image/png');
            const payload = { id: currentId };

            if (name === 'tecnico') {
                payload.firma_tecnico = dataUrl;
                const horaIni = panel.querySelector('#pd-hora-inicio')?.value;
                if (horaIni) payload.horadeinicio = horaIni;
            } else if (name === 'cliente') {
                payload.firma_cliente  = dataUrl;
                payload.nombre_cliente = panel.querySelector('#pd-nombre-cliente')?.value || '';
                payload.dni_cliente    = panel.querySelector('#pd-dni-cliente')?.value    || '';
                const horaFin = panel.querySelector('#pd-hora-fin')?.value;
                if (horaFin) payload.horadefin = horaFin;
            }
            guardarProgreso(payload);
        }
    }

    function unlockFirma(name, clear) {
        const canvas    = panel.querySelector('#pd-canvas-' + name);
        const wrap      = canvas?.parentElement;
        const lockEl    = panel.querySelector('#lock-' + name);
        const actionsEl = panel.querySelector('#actions-firma-' + name);
        const confirmBtn= panel.querySelector('#confirm-firma-' + name);
        const badgeEl   = panel.querySelector('#badge-firma-' + name);
        const sectionEl = panel.querySelector('#sec-wrap-firma-' + name);

        if (!canvas) return;

        if (clear) {
            const ctx = canvas.getContext('2d');
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            canvas.dataset.signed = '';
            canvas.dataset.locked = '';
            canvas.style.pointerEvents = '';
            canvas.style.touchAction   = 'none';
            if (confirmBtn) confirmBtn.style.display = 'none';
            initCanvas('pd-canvas-' + name);

            // Borrar también en BD para que no reaparezca al recargar
            const payloadClear = { id: currentId };
            if (name === 'tecnico') payloadClear.firma_tecnico = '';
            if (name === 'cliente') payloadClear.firma_cliente = '';
            guardarProgreso(payloadClear);
        } else {
            // Solo editar: desbloquear sin borrar
            canvas.dataset.locked = '';
            canvas.style.pointerEvents = '';
            canvas.style.touchAction   = 'none';
            if (confirmBtn) confirmBtn.style.display = 'block';
        }

        if (wrap) wrap.classList.remove('locked');
        if (lockEl)    lockEl.classList.remove('visible');
        if (actionsEl) actionsEl.style.display  = 'none';
        if (badgeEl) {
            badgeEl.textContent = '';
            badgeEl.className   = 'pd-firma-badge';
        }
        if (sectionEl) sectionEl.classList.remove('pd-section--firmada');

        actualizarBotonTerminar();
    }


    return { open, close };
})();

// ── Interceptar clicks en fichas ──────────────────────────────────────────────
function handleCardActivation(e) {
    const card = e.target.closest('.pt-card[data-id]');
    if (!card) return;
    if (e.type === 'keydown' && e.key !== 'Enter' && e.key !== ' ') return;
    e.preventDefault();
    const parteId = parseInt(card.dataset.id, 10);
    if (parteId) PtDetalle.open(parteId);
}
document.addEventListener('click',  handleCardActivation);
document.addEventListener('keydown', handleCardActivation);
